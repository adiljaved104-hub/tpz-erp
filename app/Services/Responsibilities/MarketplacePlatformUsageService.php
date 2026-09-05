<?php

namespace App\Services\Responsibilities;

use App\DTOs\Usage\UsageCheckResult;
use App\Models\MarketplacePlatform;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplacePlatformUsageService
{
    /** @var array<string, array{column: string, source: string}> */
    private const REFERENCE_SOURCES = [
        'responsibility_assignment_platforms' => [
            'column' => 'marketplace_platform_id',
            'source' => 'responsibility_assignments',
        ],
    ];

    public function check(MarketplacePlatform $platform): UsageCheckResult
    {
        $sources = [];

        foreach (self::REFERENCE_SOURCES as $table => $reference) {
            if (Schema::hasTable($table) && DB::table($table)->where($reference['column'], $platform->id)->exists()) {
                $sources[] = $reference['source'];
            }
        }

        return $sources === [] ? UsageCheckResult::unused() : new UsageCheckResult(true, $sources);
    }
}
