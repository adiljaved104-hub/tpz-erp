<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class ActivityLogger
{
    /** @var array<int, string> */
    private const FORBIDDEN_KEY_PARTS = [
        'password', 'hash', 'token', 'session', 'credential', 'secret',
        'cost', 'price', 'amount', 'total', 'profit', 'value',
    ];

    /** @param array<string, mixed> $properties */
    public function log(
        string $event,
        ?User $actor = null,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null,
    ): ActivityLog {
        $this->assertSafeProperties($properties);

        return ActivityLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'event' => $event,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function assertSafeProperties(array $properties, string $path = ''): void
    {
        foreach ($properties as $key => $value) {
            $qualifiedKey = $path === '' ? (string) $key : $path.'.'.$key;
            $normalizedKey = strtolower((string) $key);

            foreach (self::FORBIDDEN_KEY_PARTS as $forbidden) {
                if (str_contains($normalizedKey, $forbidden)) {
                    throw new InvalidArgumentException("Sensitive activity-log property rejected: {$qualifiedKey}");
                }
            }

            if (is_array($value)) {
                $this->assertSafeProperties($value, $qualifiedKey);
            }
        }
    }
}
