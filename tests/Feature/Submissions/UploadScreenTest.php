<?php

use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    RateLimiter::clear('uploads');
});

function manuscriptFixture(string $name, ?string $as = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($as ?? $name, file_get_contents(base_path("tests/Fixtures/{$name}")));
}

function docxFile(string $name = 'essay.docx', bool $valid = true): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString($valid ? 'word/document.xml' : 'notes.txt', '<doc/>');
    $zip->close();

    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
}

/** @return array<string, mixed> */
function uploadDetails(UploadedFile $file, array $overrides = []): array
{
    return array_merge([
        'file' => $file,
        'author' => 'Ada Lovelace',
        'title' => 'On Engines',
        'assignment' => 'History 101',
    ], $overrides);
}

it('saves a valid pdf and shows the saved page (AC-1, AC-5, AC-8, AC-9)', function () {
    $response = $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf', 'My Essay.pdf')));

    $submission = Submission::sole();
    $response->assertRedirect(route('submissions.show', $submission));

    expect($submission->page_count)->toBe(1)
        ->and($submission->original_name)->toBe('My Essay.pdf')
        ->and($submission->file_path)->toBe("submissions/{$submission->public_id}/manuscript.pdf")
        ->and($submission->file_sha256)->toBe(hash_file('sha256', base_path('tests/Fixtures/one-page.pdf')))
        ->and($submission->file_size)->toBe(filesize(base_path('tests/Fixtures/one-page.pdf')))
        ->and($submission->user_id)->toBeNull()
        ->and($submission->author)->toBe('Ada Lovelace');

    Storage::disk('local')->assertExists($submission->file_path);
    expect(Scan::count())->toBe(0);

    $this->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Show')
            ->has('submission', fn (Assert $s) => $s
                ->where('public_id', $submission->public_id)
                ->where('original_name', 'My Essay.pdf')
                ->where('page_count', 1)
                ->where('author', 'Ada Lovelace')
                ->where('title', 'On Engines')
                ->where('assignment', 'History 101')
                ->where('status.label', 'Not sent')
                ->where('is_word', false)
                ->where('duplicates', [])
                ->has('file_size')
                ->has('created_at')
                ->missing('id')
                ->missing('file_path')));
});

it('saves a docx with an unknown page count (AC-4)', function () {
    $this->post(route('submissions.store'), uploadDetails(docxFile()))->assertRedirect();

    $submission = Submission::sole();

    expect($submission->page_count)->toBeNull()
        ->and($submission->file_path)->toEndWith('/manuscript.docx');

    $this->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.page_count', null)
            ->where('submission.is_word', true));
});

it('rejects files that are not allowed and saves nothing (AC-2, AC-3)', function (callable $makeFile, string $message) {
    $this->post(route('submissions.store'), uploadDetails($makeFile()))
        ->assertSessionHasErrors(['file' => $message]);

    expect(Submission::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'wrong type' => [fn () => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'), 'Only PDF (.pdf) and Word (.docx) files are allowed.'],
    'old doc' => [fn () => manuscriptFixture('old-word.doc'), 'Only PDF (.pdf) and Word (.docx) files are allowed.'],
    'text renamed to pdf' => [fn () => manuscriptFixture('renamed-text.pdf'), 'Only PDF (.pdf) and Word (.docx) files are allowed.'],
    'zip renamed to docx' => [fn () => docxFile('fake.docx', valid: false), 'Only PDF (.pdf) and Word (.docx) files are allowed.'],
    'over 20 MB' => [fn () => UploadedFile::fake()->create('big.pdf', 20481), 'The file is too big. The limit is 20 MB.'],
    'encrypted pdf' => [fn () => manuscriptFixture('encrypted.pdf'), 'This PDF is password protected. Remove the password and upload it again.'],
    'corrupt pdf' => [fn () => manuscriptFixture('corrupt.pdf'), 'This PDF could not be read. It may be damaged or empty.'],
    'too many pages' => [fn () => manuscriptFixture('hundred-and-one-pages.pdf'), 'This PDF has more than 100 pages, which is the limit.'],
]);

it('shows field errors and keeps what was typed (AC-1, AC-10)', function () {
    $this->from(route('submissions.create'))
        ->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf'), [
            'author' => '',
            'title' => str_repeat('x', 256),
            'assignment' => 'History 101',
        ]))
        ->assertSessionHasErrors(['author', 'title'])
        ->assertSessionHasInput('assignment', 'History 101');

    expect(Submission::count())->toBe(0);
});

it('requires the file (AC-1)', function () {
    $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf'), ['file' => null]))
        ->assertSessionHasErrors(['file' => 'Please choose a manuscript file.']);
});

it('removes the file when the row cannot be saved (AC-6)', function () {
    Submission::creating(function () {
        throw new RuntimeException('database is down');
    });

    $this->withoutExceptionHandling()
        ->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf')));
})->throws(RuntimeException::class, 'database is down');

it('leaves no file behind after a failed row save (AC-6)', function () {
    Submission::creating(function () {
        throw new RuntimeException('database is down');
    });

    $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf')))->assertServerError();

    expect(Submission::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('keeps both rows for the same file and links the earlier one (AC-7)', function () {
    $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf', 'first.pdf')));
    $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf', 'second.pdf')));

    expect(Submission::count())->toBe(2);

    $first = Submission::where('original_name', 'first.pdf')->sole();
    $second = Submission::where('original_name', 'second.pdf')->sole();

    $this->get(route('submissions.show', $second))
        ->assertInertia(fn (Assert $page) => $page
            ->has('submission.duplicates', 1)
            ->where('submission.duplicates.0.public_id', $first->public_id));

    $this->get(route('submissions.show', $first))
        ->assertInertia(fn (Assert $page) => $page
            ->where('submission.duplicates.0.public_id', $second->public_id));
});

it('lists at most five earlier uploads, newest first (AC-7)', function () {
    $hash = str_repeat('a', 64);
    $rows = Submission::factory()->count(7)->create(['file_sha256' => $hash]);

    $this->get(route('submissions.show', $rows->first()))
        ->assertInertia(fn (Assert $page) => $page
            ->has('submission.duplicates', 5)
            ->where('submission.duplicates.0.public_id', $rows->last()->public_id));
});

it('returns 404 for an unknown or badly formed id (AC-8)', function (string $id) {
    $this->get(route('submissions.show', $id))->assertNotFound();
})->with(['unknown ulid' => ['01jzzzzzzzzzzzzzzzzzzzzzzz'], 'bad id' => ['not-an-id']]);

it('limits uploads to 10 a minute and shows it as a file error (AC-10)', function () {
    foreach (range(1, 10) as $_) {
        $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf')));
    }

    $this->post(route('submissions.store'), uploadDetails(manuscriptFixture('one-page.pdf')))
        ->assertSessionHasErrors(['file' => 'Too many uploads. Please wait a minute and try again.']);

    expect(Submission::count())->toBe(10);
});

it('shows a notice after a post that was too big for PHP (AC-10)', function () {
    $this->get(route('submissions.create', ['notice' => 'too-large']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('submissions/Create')
            ->where('notice', 'File is too large or did not finish uploading.'));

    $this->get(route('submissions.create'))
        ->assertInertia(fn (Assert $page) => $page->where('notice', null));
});

it('works without a login and sends the home page to the upload page (AC-11)', function () {
    $this->get('/')->assertRedirect('/submissions/create');
    $this->get(route('submissions.create'))->assertOk();
});

it('sends a post that is bigger than PHP allows back to the upload page with a notice (AC-10)', function () {
    $this->withServerVariables(['CONTENT_LENGTH' => 5_000_000_000])
        ->post(route('submissions.store'))
        ->assertRedirect(route('submissions.create', ['notice' => 'too-large']));

    expect(Submission::count())->toBe(0);
});
