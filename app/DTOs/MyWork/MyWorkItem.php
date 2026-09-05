<?php

namespace App\DTOs\MyWork;

use App\Enums\MyWorkPriority;
use Carbon\CarbonImmutable;

final readonly class MyWorkItem
{
    /** @param array<string, string> $financial */
    public function __construct(
        public string $key,
        public string $module,
        public int $recordId,
        public string $reference,
        public ?string $product,
        public ?string $sku,
        public ?string $platform,
        public string $status,
        public ?string $assignedTo,
        public MyWorkPriority $priority,
        public string $attentionReason,
        public CarbonImmutable $waitingSince,
        public ?CarbonImmutable $dueAt,
        public string $nextAction,
        public string $url,
        public bool $canAct,
        public ?string $actionKey = null,
        public array $financial = [],
    ) {}

    public function daysWaiting(): int
    {
        return (int) $this->waitingSince->startOfDay()->diffInDays(now(config('app.timezone'))->startOfDay());
    }

    public function dueLabel(): ?string
    {
        if ($this->dueAt === null) {
            return null;
        }

        $days = now(config('app.timezone'))->startOfDay()->diffInDays($this->dueAt->startOfDay(), false);

        return match (true) {
            $days < 0 => abs((int) $days).' '.str('day')->plural(abs((int) $days)).' overdue',
            (int) $days === 0 => 'Due today',
            default => (int) $days.' '.str('day')->plural((int) $days).' left',
        };
    }
}
