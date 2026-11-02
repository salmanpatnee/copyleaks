<?php

namespace App\Actions;

use App\Enums\ArtifactKind;
use App\Enums\EventKind;
use App\Enums\ScanStatus;
use App\Events\ScanResultsArrived;
use App\Models\Scan;
use App\Models\ScanArtifact;
use App\Models\ScanEvent;
use App\Services\Copyleaks\RawFileWriter;
use App\Services\ScanTransitioner;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles one valid webhook call (AC-9, AC-10). Calls nothing at Copyleaks.
 *
 * Order: (1) save the exact `completed` body, gzip compressed, to disk;
 * (2) in one transaction insert the artifact row and the event row, move the scan
 * and set `handled_at`; (3) after the commit, fire ScanResultsArrived once.
 * A repeat of the same (scan, status) changes nothing.
 */
class AcceptWebhook
{
    public function __construct(
        private readonly ScanTransitioner $transitioner,
        private readonly RawFileWriter $writer,
    ) {}

    /** @param  array<string, mixed>  $body  the decoded JSON of $rawBody */
    public function __invoke(Scan $scan, string $status, string $rawBody, array $body): void
    {
        $isCompleted = $status === 'completed';
        $summary = $isCompleted ? $this->writer->store("scans/{$scan->copyleaks_scan_id}/summary.json.gz", $rawBody) : null;

        $fetching = DB::transaction(function () use ($scan, $status, $body, $summary): bool {
            if ($summary !== null) {
                ScanArtifact::query()->insertOrIgnore([[
                    'scan_id' => $scan->getKey(),
                    'kind' => ArtifactKind::Summary->value,
                    'source_id' => '',
                    'path' => $summary['path'],
                    'sha256' => $summary['sha256'],
                    'size_bytes' => $summary['size'],
                    'created_at' => now(),
                ]]);
            }

            $recorded = ScanEvent::query()->insertOrIgnore([[
                'scan_id' => $scan->getKey(),
                'kind' => EventKind::Status->value,
                'status' => $status,
                'payload' => json_encode($this->payloadFor($status, $body)),
                'received_at' => now(),
                'handled_at' => now(),
            ]]);

            // A repeat of the same call was already handled. Change nothing.
            if ($recorded === 0) {
                return false;
            }

            return $status === 'completed' ? $this->moveOnCompleted($scan) : $this->moveOnError($scan, $body);
        });

        Log::info('Copyleaks webhook accepted.', ['scan_id' => $scan->copyleaks_scan_id, 'status' => $status]);

        if ($fetching) {
            event(new ScanResultsArrived($scan));
        }
    }

    /** `completed` moves a waiting or submitting scan to `fetching`. Returns true when it did. */
    private function moveOnCompleted(Scan $scan): bool
    {
        if ($this->transitioner->markFetching($scan)) {
            return true;
        }

        if ($scan->status === ScanStatus::Failed) {
            // Credits were spent but we had already given up. The summary is kept.
            Log::warning('Copyleaks completed a scan that was already failed.', ['scan_id' => $scan->copyleaks_scan_id]);
        }

        return false;
    }

    /** @param  array<string, mixed>  $body */
    private function moveOnError(Scan $scan, array $body): bool
    {
        $code = Arr::get($body, 'error.code', 'error');
        $message = Arr::get($body, 'error.message', 'Copyleaks reported an error.');
        $reason = mb_substr((is_scalar($code) ? $code : 'error').': '.(is_scalar($message) ? $message : 'Copyleaks reported an error.'), 0, 1000);

        $this->transitioner->markFailed($scan, $reason);

        return false;
    }

    /**
     * A small copy built by us. Never source text, manuscript text or headers.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function payloadFor(string $status, array $body): array
    {
        if ($status === 'error') {
            return [
                'status' => Arr::get($body, 'status'),
                'developerPayload' => Arr::get($body, 'developerPayload'),
                'error' => [
                    'type' => Arr::get($body, 'error.type'),
                    'id' => Arr::get($body, 'error.id'),
                    'code' => Arr::get($body, 'error.code'),
                    'message' => Arr::get($body, 'error.message'),
                ],
            ];
        }

        return [
            'status' => Arr::get($body, 'status'),
            'developerPayload' => Arr::get($body, 'developerPayload'),
            'scannedDocument' => [
                'scanId' => Arr::get($body, 'scannedDocument.scanId'),
                'totalWords' => Arr::get($body, 'scannedDocument.totalWords'),
                'credits' => Arr::get($body, 'scannedDocument.credits'),
            ],
            'results' => ['score' => Arr::get($body, 'results.score')],
            'alerts' => array_values(array_filter(
                Arr::pluck((array) Arr::get($body, 'notifications.alerts', []), 'code'),
                fn ($code): bool => $code !== null,
            )),
        ];
    }
}
