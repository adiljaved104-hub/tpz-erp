<?php

namespace App\Services\Notifications;

use App\Enums\NotificationRulePermission;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\NotificationRuleAuthorization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NotificationRuleService
{
    /** @var array<string, bool> */
    private static array $missingWarnings = [];

    public function __construct(
        private readonly NotificationRuleCatalog $catalog,
        private readonly NotificationRuleAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(string $eventKey): array
    {
        $key = $this->catalog->canonicalKey($eventKey);
        $fallback = $this->catalog->all()[$key] ?? [
            'name' => $key, 'category' => 'Operational', 'enabled' => true,
            'in_app_enabled' => true, 'email_enabled' => true,
            'recipient_strategy' => 'existing_business_routing',
            'recipient_options' => ['existing_business_routing'],
            'threshold_value' => null, 'threshold_unit' => null, 'configuration' => [],
        ];

        if (! Schema::hasTable('notification_rules')) {
            return ['event_key' => $key] + $fallback;
        }

        $rule = NotificationRule::query()->where('event_key', $key)->first();
        if (! $rule instanceof NotificationRule) {
            if (! isset(self::$missingWarnings[$key])) {
                Log::warning('Operational notification rule is missing; existing safe defaults are being used.', ['event_key' => $key]);
                self::$missingWarnings[$key] = true;
            }

            return ['event_key' => $key] + $fallback;
        }

        return $rule->toArray() + ['recipient_options' => $fallback['recipient_options']];
    }

    public function enabled(string $eventKey): bool
    {
        return (bool) $this->resolve($eventKey)['enabled'];
    }

    public function channelEnabled(string $eventKey, string $channel): bool
    {
        $rule = $this->resolve($eventKey);

        return (bool) $rule['enabled'] && match ($channel) {
            'database', 'in_app' => (bool) $rule['in_app_enabled'],
            'mail', 'email' => (bool) $rule['email_enabled'],
            default => false,
        };
    }

    public function recipientStrategy(string $eventKey): string
    {
        return (string) $this->resolve($eventKey)['recipient_strategy'];
    }

    public function threshold(string $eventKey, int $fallback): int
    {
        $value = $this->resolve($eventKey)['threshold_value'] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : $fallback;
    }

    /** @param array<string, mixed> $data */
    public function update(NotificationRule $rule, array $data, User $actor): NotificationRule
    {
        $this->authorization->authorize($actor, NotificationRulePermission::Manage);
        $definition = $this->catalog->all()[$rule->event_key] ?? null;
        abort_unless(is_array($definition), 422);

        $enabled = (bool) ($data['enabled'] ?? $rule->enabled);
        $inApp = (bool) ($data['in_app_enabled'] ?? $rule->in_app_enabled);
        $email = (bool) ($data['email_enabled'] ?? $rule->email_enabled);
        if ($enabled && ! $inApp && ! $email) {
            throw ValidationException::withMessages(['channels' => 'Enable at least one notification channel.']);
        }

        $strategy = (string) ($data['recipient_strategy'] ?? $rule->recipient_strategy);
        if (! in_array($strategy, $definition['recipient_options'], true)) {
            throw ValidationException::withMessages(['recipient_strategy' => 'The selected recipient rule is not valid for this event.']);
        }

        $threshold = array_key_exists('threshold_value', $data) && filled($data['threshold_value']) ? (int) $data['threshold_value'] : null;
        if ($rule->event_key === 'warranty.due_soon' && ($threshold === null || $threshold < 0 || $threshold > 365)) {
            throw ValidationException::withMessages(['threshold_value' => 'Enter a due-soon threshold between 0 and 365 days.']);
        }

        return DB::transaction(function () use ($rule, $actor, $enabled, $inApp, $email, $strategy, $threshold): NotificationRule {
            $locked = NotificationRule::query()->lockForUpdate()->findOrFail($rule->id);
            $before = $this->auditState($locked);
            $locked->fill([
                'enabled' => $enabled,
                'in_app_enabled' => $inApp,
                'email_enabled' => $email,
                'recipient_strategy' => $strategy,
                'threshold_value' => $locked->event_key === 'warranty.due_soon' ? $threshold : $locked->threshold_value,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $after = $this->auditState($locked->fresh());

            $this->activity->log('notification_rule.updated', $actor, $locked, [
                'event_key' => $locked->event_key,
                'before' => $before,
                'after' => $after,
            ]);

            return $locked->refresh();
        });
    }

    public function ensureDefaults(): void
    {
        if (! Schema::hasTable('notification_rules')) {
            return;
        }

        foreach ($this->catalog->all() as $eventKey => $definition) {
            NotificationRule::query()->firstOrCreate(['event_key' => $eventKey], Arr::except($definition, 'recipient_options'));
        }
    }

    public function claimEmailDelivery(string $eventKey, string $deduplicationKey, User $recipient): bool
    {
        if (! Schema::hasTable('notification_rule_deliveries')) {
            return true;
        }

        return DB::table('notification_rule_deliveries')->insertOrIgnore([
            'event_key' => $this->catalog->canonicalKey($eventKey),
            'deduplication_key' => $deduplicationKey,
            'recipient_user_id' => $recipient->id,
            'channel' => 'email',
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    /** @return array<string, mixed> */
    private function auditState(NotificationRule $rule): array
    {
        return [
            'enabled' => $rule->enabled,
            'in_app' => $rule->in_app_enabled,
            'email' => $rule->email_enabled,
            'recipient' => $rule->recipient_strategy,
            'threshold' => $rule->threshold_value,
            'unit' => $rule->threshold_unit,
        ];
    }
}
