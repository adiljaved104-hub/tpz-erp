<?php

namespace Database\Seeders;

use App\Services\DemoData\StagingDemoDataService;
use Illuminate\Database\Seeder;

class StagingDemoSeeder extends Seeder
{
    public function run(): void
    {
        app(StagingDemoDataService::class)->run(
            profile: (string) config('demo.profile', 'full'),
            dryRun: false,
            confirmation: null,
        );
    }
}
