<?php

namespace App\Console\Commands;

use App\Enums\ScanStatus;
use App\Exceptions\ReportRenderFailed;
use App\Models\Submission;
use App\Services\Reports\Mapping\ReportDataMapper;
use Illuminate\Console\Command;

/** Maps the newest completed scan of a submission and prints the numbers. Prints no manuscript text and writes nothing. */
class MapReportData extends Command
{
    protected $signature = 'report:mapping {public_id : Public ID of the submission}';

    protected $description = 'Show the report data mapped from a saved scan (score, sources, highlight ranges)';

    public function handle(ReportDataMapper $mapper): int
    {
        $submission = Submission::query()->where('public_id', (string) $this->argument('public_id'))->first();
        $scan = $submission?->scans()->where('status', ScanStatus::Completed->value)->latest('id')->first();

        if ($scan === null) {
            $this->error('no_completed_scan: this submission has no completed scan.');

            return self::FAILURE;
        }

        try {
            $data = $mapper->map($scan);
        } catch (ReportRenderFailed $e) {
            $this->error("{$e->reason}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->line('Score: '.$data->similarityPercent.'%');
        $this->line('Total words: '.$data->totalWords);
        $this->line('Sources: '.count($data->sources));

        foreach ($data->sources as $i => $row) {
            $stat = $data->sourceStats[$i];
            $this->line(sprintf(
                '  #%d [%s] %s  %s%%  %d words  %s',
                $row->rank,
                $row->category ?? 'no category',
                $row->name,
                number_format($row->percent, 2),
                $stat->matchedWords,
                $stat->rangeCount === 0 && $stat->droppedRanges > 0
                    ? "0 ranges ({$stat->droppedRanges} dropped past text end)"
                    : "{$stat->rangeCount} ranges",
            ));

            if (! $stat->hasId || ($stat->rangeCount === 0 && $stat->droppedRanges === 0)) {
                $this->warn("  warning: source #{$row->rank} has matched words but ".($stat->hasId ? '0 ranges.' : 'no id.'));
            }
        }

        $longest = 0;
        foreach ($data->ranges as $range) {
            $longest = max($longest, $range->length);
        }

        $this->line('Merged ranges: '.count($data->ranges));
        $this->line('Largest merged range: '.$longest.' characters');

        $this->line("Trimmed ranges: {$data->droppedRanges()} dropped, {$data->clippedRanges()} clipped");
        $this->line("Largest range end: {$data->largestRangeEnd}");
        $this->line("Text length: {$data->textLength}");

        return self::SUCCESS;
    }
}
