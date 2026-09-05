<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\CriticalAlertDatabaseNotification;
use App\Notifications\CriticalAlertMailNotification;
use Illuminate\Database\QueryException;
use Throwable;

class CriticalAlertDispatcher
{
    public function __construct(
        private readonly EmailConfigurationService $emailConfiguration,
        private readonly NotificationRuleService $rules,
    ) {}

    /** @param array<string, mixed> $payload */
    public function send(User $recipient, string $type, string $eventKey, array $payload, string $mailSubject, ?string $url = null, array $mailDetails = []): bool
    {
        if (! $this->activeUser($recipient) || ! $this->rules->enabled($type)) {
            return false;
        }

        $id = $this->deterministicId($type.':'.$eventKey.':user:'.$recipient->id);
        $inApp = $this->rules->channelEnabled($type, 'in_app');
        $email = $this->rules->channelEnabled($type, 'email');
        $delivered = false;

        if ($inApp) {
            if ($recipient->notifications()->whereKey($id)->exists()) {
                return false;
            }

            try {
                $recipient->notify(new CriticalAlertDatabaseNotification($id, $type, $payload));
                $delivered = true;
            } catch (QueryException $exception) {
                if ($recipient->notifications()->whereKey($id)->exists()) {
                    return false;
                }
                report($exception);

                return false;
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }
        }

        if ($email && ($inApp || $this->rules->claimEmailDelivery($type, $eventKey, $recipient))) {
            $delivered = $this->queueEmail($recipient, $mailSubject, (string) ($payload['title'] ?? 'ERP alert'), (string) ($payload['reference'] ?? ''), (string) ($payload['message'] ?? ''), $payload['status'] ?? null, $url, $payload['target_type'] ?? null, isset($payload['target_id']) ? (int) $payload['target_id'] : null, $mailDetails, $type) || $delivered;
        }

        return $delivered;
    }

    public function queueEmail(User $recipient, string $subject, string $title, string $reference, string $reason, ?string $status = null, ?string $url = null, ?string $targetType = null, ?int $targetId = null, array $details = [], ?string $ruleEventKey = null): bool
    {
        if (! $this->emailConfiguration->enabled() || ! $this->activeUser($recipient) || ! filter_var(trim((string) $recipient->employee?->email), FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $recipient->notify(new CriticalAlertMailNotification($subject, $title, $reference, $reason, $status, $url, $targetType, $targetId, $details, $ruleEventKey));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function activeUser(User $user): bool
    {
        $employee = $user->employee;

        return $employee?->status === true;
    }

    private function deterministicId(string $key): string
    {
        $hex = hash('sha256', 'tpz-erp-notification:'.$key);
        $variant = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-'.$variant.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
