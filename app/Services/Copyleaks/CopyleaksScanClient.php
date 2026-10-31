<?php

namespace App\Services\Copyleaks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The real Copyleaks client. Three calls: login, submit, resend.
 *
 * It uses Laravel's Http client because the official SDK is not confirmed on
 * Laravel 13 (spec 0004, Follow-up). It refuses to run under the `testing`
 * environment, so no test can ever spend a credit.
 */
class CopyleaksScanClient implements ScanClient
{
    private const TOKEN_CACHE_KEY = 'copyleaks.login-token';

    public function __construct(private readonly CopyleaksSettings $settings) {}

    public function submit(SubmitRequest $request): SubmitResult
    {
        $this->guardAgainstTests();

        try {
            $response = $this->withLoginRetry(fn (string $token): Response => $this->http($token)
                ->put($this->url("/v3/scans/submit/file/{$request->scanId}"), [
                    'base64' => base64_encode($request->contents),
                    'filename' => $request->fileName,
                    'properties' => $request->options,
                ]));
        } catch (CopyleaksLoginRefused $e) {
            return SubmitResult::refused($e->getMessage());
        }

        return match (true) {
            $response->status() === 201 || $response->successful() => SubmitResult::created(),
            $response->status() === 409 => SubmitResult::conflict(),
            default => SubmitResult::refused($this->reasonFrom($response)),
        };
    }

    public function resendWebhook(string $scanId): ResendResult
    {
        $this->guardAgainstTests();

        try {
            $response = $this->withLoginRetry(fn (string $token): Response => $this->http($token)
                ->post($this->url("/v3/scans/{$scanId}/webhooks/resend")));
        } catch (CopyleaksLoginRefused $e) {
            throw new ScanClientUnavailable($e->getMessage(), previous: $e);
        }

        return match (true) {
            $response->status() === 202 || $response->successful() => ResendResult::Accepted,
            $response->status() === 400 => ResendResult::NotReady,
            $response->status() === 404 => ResendResult::NotFound,
            default => throw new ScanClientUnavailable('Copyleaks refused the resend call ('.$response->status().').'),
        };
    }

    /** Never reached from a test. A fake ScanClient is bound there instead. */
    private function guardAgainstTests(): void
    {
        if (app()->environment('testing')) {
            throw new LogicException('The real Copyleaks client must never run in the testing environment.');
        }
    }

    /**
     * A 401 means the cached login token is no longer good: forget it, log in
     * once more and repeat the same call once.
     *
     * @param  callable(string): Response  $call
     */
    private function withLoginRetry(callable $call): Response
    {
        $response = $this->send($call, $this->token());

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->send($call, $this->token());
        }

        if ($response->serverError()) {
            throw new ScanClientUnavailable('Copyleaks answered with a server error ('.$response->status().').');
        }

        return $response;
    }

    /** @param  callable(string): Response  $call */
    private function send(callable $call, string $token): Response
    {
        try {
            return $call($token);
        } catch (ConnectionException $e) {
            // The message can carry the address. Say only that we could not connect.
            throw new ScanClientUnavailable('Could not reach Copyleaks.', previous: $e);
        }
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::acceptJson()->asJson()->timeout(30)
                ->post($this->idUrl('/v3/account/login/api'), [
                    'email' => $this->settings->email(),
                    'key' => $this->settings->apiKey(),
                ]);
        } catch (ConnectionException $e) {
            throw new ScanClientUnavailable('Could not reach Copyleaks.', previous: $e);
        }

        if ($response->serverError()) {
            throw new ScanClientUnavailable('Copyleaks login answered with a server error.');
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            // A wrong key is a clear refusal, not a network problem. No key text in the message.
            throw new CopyleaksLoginRefused($response->status().': Copyleaks login was refused. Check the email and API key.');
        }

        Cache::put(self::TOKEN_CACHE_KEY, $token, now()->addHours((int) config('copyleaks.token_cache_hours')));

        return $token;
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->asJson()->connectTimeout(15)->timeout(60);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('copyleaks.api_base'), '/').$path;
    }

    private function idUrl(string $path): string
    {
        return rtrim((string) config('copyleaks.id_base'), '/').$path;
    }

    /** `"{code}: {message}"` from the Copyleaks error body, else the HTTP status. */
    private function reasonFrom(Response $response): string
    {
        $code = $response->json('error.code') ?? $response->status();
        $message = $response->json('error.message');

        $reason = $code.': '.(is_string($message) && $message !== '' ? $message : 'Copyleaks refused the request.');

        return mb_substr($reason, 0, 1000);
    }
}
