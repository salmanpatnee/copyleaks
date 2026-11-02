<?php

namespace App\Console\Commands;

use App\Services\Copyleaks\FetchingScanSweeper;
use Illuminate\Console\Command;

class SweepFetchingScans extends Command
{
    protected $signature = 'copyleaks:sweep-fetching';

    protected $description = 'Finish, retry or fail scans stuck after the Copyleaks summary (spec 0005)';

    public function handle(FetchingScanSweeper $sweeper): int
    {
        $this->info('Looked at '.$sweeper->sweep().' fetching scan(s).');

        return self::SUCCESS;
    }
}
