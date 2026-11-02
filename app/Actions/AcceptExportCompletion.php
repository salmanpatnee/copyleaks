<?php

namespace App\Actions;

use App\Enums\EventKind;
use App\Models\Scan;
use App\Models\ScanEvent;
use App\Services\Copyleaks\ExportAttempts;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Handles the export completion call (spec 0005, AC-6). Calls nothing at Copyleaks.
 *
 * Records one `export_completed` event with a cleaned payload (task endpoints carry
 * the token, so they are never saved), then runs the finish check. Only the latest
 * export ID may start a retry. A repeat of the same export ID changes nothing.
 */
class AcceptExportCompletion
{
    public function __construct(
        private readonly FinishScan $finish,
        private readonly AdvanceExport $advance,
        private readonly ExportAttempts $attempts,
    ) {}

    /** @param  array<string, mixed>  $body  the decoded completion call */
    public function __invoke(Scan $scan, ScanEvent $claim, array $body): void
    {
        $tasks = $this->cleanTasks($body);

        $recorded = ScanEvent::query()->insertOrIgnore([[
            'scan_id' => $scan->getKey(),
            'kind' => EventKind::ExportCompleted->value,
            'status' => $claim->status,
            'payload' => json_encode(['completed' => (bool) Arr::get($body, 'completed', false), 'tasks' => $tasks]),
            'received_at' => now(),
            'handled_at' => now(),
        ]]);

        if ($recorded === 0) {
            return;
        }

        $unhealthy = count(array_filter($tasks, fn (array $task): bool => $task['isHealthy'] !== true));

        Log::info('Copyleaks export completion received.', [
            'scan_id' => $scan->copyleaks_scan_id,
            'export_id' => $claim->status,
            'part' => 'completed',
            'status' => $unhealthy === 0 ? 'healthy' : 'unhealthy',
        ]);

        if ($this->attempts->latest($scan)?->status === $claim->status) {
            ($this->advance)($scan);

            return;
        }

        ($this->finish)($scan);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{isHealthy: mixed, httpStatusCode: mixed}>
     */
    private function cleanTasks(array $body): array
    {
        $tasks = [];

        foreach ((array) Arr::get($body, 'tasks', []) as $task) {
            if (is_array($task)) {
                $tasks[] = ['isHealthy' => $task['isHealthy'] ?? null, 'httpStatusCode' => $task['httpStatusCode'] ?? null];
            }
        }

        return $tasks;
    }
}
