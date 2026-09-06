<?php

namespace App\Console\Commands;

use App\Services\DemoData\StagingDemoDataService;
use Illuminate\Console\Command;
use Throwable;

class SeedStagingDemo extends Command
{
    protected $signature = 'erp:seed-demo {--profile=full} {--dry-run} {--confirm=}';

    protected $description = 'Safely generate the approved deterministic staging demo dataset';

    public function handle(StagingDemoDataService $service): int
    {
        try {
            $report = $service->run(
                profile: (string) $this->option('profile'),
                dryRun: (bool) $this->option('dry-run'),
                confirmation: $this->option('confirm'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($report->dryRun ? 'Demo-data preflight passed. No data was written.' : 'Staging demo data is ready.');
        $this->table(['Scenario', 'Records'], collect($report->counts)->map(fn (int $count, string $name): array => [$name, $count])->values()->all());

        return self::SUCCESS;
    }
}
