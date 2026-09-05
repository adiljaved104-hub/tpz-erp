<?php

namespace App\Console\Commands;

use App\Services\Hikvision\HikvisionAttendanceImporter;
use App\Services\Operations\BackgroundServiceHealth;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class SyncHikvisionAttendance extends Command
{
    protected $signature = 'hikvision:sync-attendance';

    protected $description = 'Incrementally import Hikvision attendance evidence';

    public function handle(HikvisionAttendanceImporter $importer, BackgroundServiceHealth $health): int
    {
        if (! config('hikvision.enabled') || ! config('hikvision.scheduled_sync_enabled')) {
            $this->components->info('Scheduled Hikvision synchronization is disabled.');

            return self::SUCCESS;
        }

        $health->record(BackgroundServiceHealth::HIKVISION_ATTEMPT);

        try {
            $result = $importer->syncScheduled();
            $run = $result?->run;
            $this->components->info($run === null
                ? 'Hikvision synchronization did not run.'
                : "Hikvision synchronization finished with status {$run->status}.");

            if ($run?->status === 'successful') {
                $health->record(BackgroundServiceHealth::HIKVISION_SUCCESS);
            }

            return $run?->status === 'failed' ? self::FAILURE : self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->components->warn(collect($exception->errors())->flatten()->first() ?? 'Hikvision synchronization could not start.');

            return self::SUCCESS;
        }
    }
}
