<?php

namespace App\Console\Commands;

use App\Services\Operations\BackgroundServiceHealth;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'erp:scheduler-heartbeat';

    protected $description = 'Record a lightweight scheduler heartbeat in the configured cache';

    public function handle(BackgroundServiceHealth $health): int
    {
        $health->record(BackgroundServiceHealth::SCHEDULER_HEARTBEAT);

        return self::SUCCESS;
    }
}
