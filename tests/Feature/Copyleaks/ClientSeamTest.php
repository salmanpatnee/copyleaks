<?php

use App\Services\Copyleaks\CopyleaksScanClient;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\FakeScanClient;
use App\Services\Copyleaks\ResendResult;
use App\Services\Copyleaks\ScanClient;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\Copyleaks\ScanOptions;
use App\Services\Copyleaks\SubmitOutcome;
use App\Services\Copyleaks\SubmitRequest;
use App\Services\Copyleaks\WebhookToken;
use App\Services\Copyleaks\WebhookUrlBuilder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../../Support/CopyleaksTestHelpers.php';

beforeEach(fn () => copyleaksReady());

it('sends exactly the agreed options and no others (AC-6)', function () {
    $options = app(ScanOptions::class)->for('01abc', true, true);
    $token = app(WebhookToken::class)->for('01abc');

    expect($options)->toBe([
        'webhooks' => [
            'status' => "https://tool.example.test/webhooks/copyleaks/01abc/{$token}/{STATUS}",
            'statusHeaders' => [['X-Webhook-Secret', 'test-webhook-secret-not-real']],
        ],
        'sandbox' => true,
        'expiration' => 72,
        'developerPayload' => '01abc',
        'cheatDetection' => true,
        'aiGeneratedText' => ['detect' => true, 'sensitivity' => 2, 'explain' => ['enable' => false]],
        'scanning' => [
            'internet' => true,
            'copyleaksDb' => ['includeUserCollection' => false, 'includeSharedCollection' => false],
        ],
    ]);
});

it('turns the AI check off from the checkbox and takes sandbox from the scan (AC-6)', function () {
    $options = app(ScanOptions::class)->for('01abc', false, false);

    expect($options['aiGeneratedText']['detect'])->toBeFalse()
        ->and($options['sandbox'])->toBeFalse()
        ->and($options)->not->toHaveKeys(['exclude', 'indexing', 'pdf']);
});

it('derives a stable token from the scan id and APP_KEY, 64 hex characters (AC-4)', function () {
    $tokens = app(WebhookToken::class);

    expect($tokens->for('a'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($tokens->for('a'))->toBe($tokens->for('a'))
        ->and($tokens->for('a'))->not->toBe($tokens->for('b'))
        ->and($tokens->hashFor('a'))->toBe(hash('sha256', $tokens->for('a')));

    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

    expect($tokens->for('a'))->not->toBe(hash_hmac('sha256', 'copyleaks-webhook|a', 'x'));
});

it('builds the status address by joining text so {STATUS} stays as written', function () {
    config(['copyleaks.webhook_url' => 'https://tool.example.test/']);

    expect(app(WebhookUrlBuilder::class)->statusUrl('abc'))
        ->toEndWith('/{STATUS}')
        ->toStartWith('https://tool.example.test/webhooks/copyleaks/abc/')
        ->not->toContain('%7B');
});

it('uses the app address when no webhook address is set', function () {
    config(['copyleaks.webhook_url' => null, 'app.url' => 'https://app.example.test']);

    expect(app(CopyleaksSettings::class)->webhookBase())->toBe('https://app.example.test');
});

it('is ready only with every setting and an https address (AC-13)', function (array $override) {
    copyleaksReady($override);

    expect(app(CopyleaksSettings::class)->isReady())->toBeFalse();
})->with([
    'no email' => [['copyleaks.email' => '']],
    'no key' => [['copyleaks.api_key' => '']],
    'no secret' => [['copyleaks.webhook_secret' => '']],
    'http address' => [['copyleaks.webhook_url' => 'http://tool.example.test']],
]);

it('is ready with every setting', function () {
    expect(app(CopyleaksSettings::class)->isReady())->toBeTrue();
});

it('estimates credits from the page count and returns null when unknown', function () {
    $settings = app(CopyleaksSettings::class);

    expect($settings->estimateCredits(10))->toBe(20)
        ->and($settings->estimateCredits(1))->toBe(2)
        ->and($settings->estimateCredits(null))->toBeNull();
});

it('binds the fake client in tests and the real one refuses to run (AC-13)', function () {
    expect(app(ScanClient::class))->toBeInstanceOf(FakeScanClient::class);

    $real = app(CopyleaksScanClient::class);

    expect(fn () => $real->submit(new SubmitRequest('a', 'a.pdf', 'x', [])))->toThrow(LogicException::class)
        ->and(fn () => $real->resendWebhook('a'))->toThrow(LogicException::class);
});

describe('real client (HTTP faked, never the network)', function () {
    beforeEach(function () {
        Cache::flush();
        $this->originalEnv = app()['env'];
        app()['env'] = 'local';
    });

    afterEach(fn () => app()['env'] = $this->originalEnv);

    it('logs in once, caches the token for 47 hours and sends the body (AC-5, AC-14)', function () {
        Http::fake([
            'id.copyleaks.com/*' => Http::response(['access_token' => 'tok123']),
            'api.copyleaks.com/v3/scans/submit/file/*' => Http::response('', 201),
        ]);

        $client = app(CopyleaksScanClient::class);
        $request = new SubmitRequest('scan1', 'My Essay.pdf', 'PDFBYTES', ['sandbox' => true]);

        expect($client->submit($request)->outcome)->toBe(SubmitOutcome::Created)
            ->and($client->submit($request)->outcome)->toBe(SubmitOutcome::Created);

        Http::assertSentCount(3); // one login, two submits
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/v3/scans/submit/file/scan1')
            && $r->hasHeader('Authorization', 'Bearer tok123')
            && $r['base64'] === base64_encode('PDFBYTES')
            && $r['filename'] === 'My Essay.pdf'
            && $r['properties'] === ['sandbox' => true]);
        expect(Cache::get('copyleaks.login-token'))->toBe('tok123');
    });

    it('logs in again once on 401 and repeats the same call (AC-7)', function () {
        Cache::put('copyleaks.login-token', 'stale', now()->addHour());
        Http::fakeSequence('api.copyleaks.com/*')->push('', 401)->push('', 201);
        Http::fake(['id.copyleaks.com/*' => Http::response(['access_token' => 'fresh'])]);

        $result = app(CopyleaksScanClient::class)->submit(new SubmitRequest('scan1', 'a.pdf', 'x', []));

        expect($result->outcome)->toBe(SubmitOutcome::Created)
            ->and(Cache::get('copyleaks.login-token'))->toBe('fresh');
    });

    it('maps 409 to a conflict and other 4xx to a refusal with code and message (AC-5, AC-7)', function () {
        Cache::put('copyleaks.login-token', 'tok', now()->addHour());
        Http::fakeSequence('api.copyleaks.com/*')
            ->push('', 409)
            ->push(['error' => ['code' => 12, 'message' => 'No credits']], 402);

        $client = app(CopyleaksScanClient::class);
        $request = new SubmitRequest('scan1', 'a.pdf', 'x', []);

        expect($client->submit($request)->outcome)->toBe(SubmitOutcome::Conflict);

        $refused = $client->submit($request);

        expect($refused->outcome)->toBe(SubmitOutcome::Refused)
            ->and($refused->reason)->toBe('12: No credits');
    });

    it('treats a server error and a connection error as unavailable (AC-7)', function () {
        Cache::put('copyleaks.login-token', 'tok', now()->addHour());
        Http::fake(['api.copyleaks.com/*' => Http::response('', 503)]);

        expect(fn () => app(CopyleaksScanClient::class)->submit(new SubmitRequest('s', 'a.pdf', 'x', [])))
            ->toThrow(ScanClientUnavailable::class);

        Http::fake(['api.copyleaks.com/*' => fn () => throw new ConnectionException('timeout')]);

        expect(fn () => app(CopyleaksScanClient::class)->submit(new SubmitRequest('s', 'a.pdf', 'x', [])))
            ->toThrow(ScanClientUnavailable::class);
    });

    it('refuses clearly when the login is rejected, without leaking the key (AC-14)', function () {
        Http::fake(['id.copyleaks.com/*' => Http::response('', 401)]);

        $result = app(CopyleaksScanClient::class)->submit(new SubmitRequest('s', 'a.pdf', 'x', []));

        expect($result->outcome)->toBe(SubmitOutcome::Refused)
            ->and($result->reason)->not->toContain('test-api-key-not-real');
    });

    it('maps the resend answers 202, 400 and 404 (AC-12)', function () {
        Cache::put('copyleaks.login-token', 'tok', now()->addHour());
        Http::fakeSequence('api.copyleaks.com/*')->push('{}', 202)->push('', 400)->push('', 404);

        $client = app(CopyleaksScanClient::class);

        expect($client->resendWebhook('s'))->toBe(ResendResult::Accepted)
            ->and($client->resendWebhook('s'))->toBe(ResendResult::NotReady)
            ->and($client->resendWebhook('s'))->toBe(ResendResult::NotFound);
    });
});
