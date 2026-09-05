<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BackgroundServiceHealth
{
    public const SCHEDULER_HEARTBEAT = 'scheduler_heartbeat';

    public const TASK_EVALUATOR_SUCCESS = 'task_evaluator_success';

    public const WARRANTY_EVALUATOR_SUCCESS = 'warranty_evaluator_success';

    public const HIKVISION_ATTEMPT = 'hikvision_attempt';

    public const HIKVISION_SUCCESS = 'hikvision_success';

    private const PREFIX = 'erp:background-health:';

    public function record(string $key): void
    {
        Cache::forever(self::PREFIX.$key, now()->toIso8601String());
    }

    public function last(string $key): ?CarbonImmutable
    {
        try {
            $value = Cache::get(self::PREFIX.$key);

            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
