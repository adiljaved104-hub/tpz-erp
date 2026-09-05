<?php

namespace App\Services\Notifications;

use App\Enums\EmailSettingsPermission;
use App\Models\EmailSetting;
use App\Models\User;
use App\Notifications\SmtpTestNotification;
use App\Services\ActivityLogger;
use App\Services\Authorization\EmailSettingsAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class EmailConfigurationService
{
    public function __construct(
        private readonly EmailSettingsAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function settings(): ?EmailSetting
    {
        return Schema::hasTable('email_settings') ? EmailSetting::query()->find(1) : null;
    }

    public function enabled(): bool
    {
        return $this->settings()?->enabled ?? (bool) config('mail.notifications_enabled', true);
    }

    public function configured(): bool
    {
        $settings = $this->settings();
        if ($settings instanceof EmailSetting) {
            return filled($settings->smtp_host)
                && filled($settings->smtp_port)
                && filled($settings->smtp_username)
                && filled($settings->smtp_password_encrypted)
                && filled($settings->from_email);
        }

        return filled(config('mail.environment_fallback.host'))
            && filled(config('mail.environment_fallback.port'))
            && filled(config('mail.environment_fallback.username'))
            && filled(config('mail.environment_fallback.password'))
            && filled(config('mail.environment_fallback.from_address'));
    }

    public function apply(bool $requireEnabled = true): bool
    {
        $settings = $this->settings();
        if (! $settings instanceof EmailSetting) {
            if ($requireEnabled && ! $this->enabled()) {
                return false;
            }

            config([
                'mail.default' => config('mail.environment_fallback.default'),
                'mail.mailers.smtp.host' => config('mail.environment_fallback.host'),
                'mail.mailers.smtp.port' => config('mail.environment_fallback.port'),
                'mail.mailers.smtp.username' => config('mail.environment_fallback.username'),
                'mail.mailers.smtp.password' => config('mail.environment_fallback.password'),
                'mail.mailers.smtp.scheme' => config('mail.environment_fallback.scheme'),
                'mail.from.address' => config('mail.environment_fallback.from_address'),
                'mail.from.name' => config('mail.environment_fallback.from_name'),
            ]);
            Mail::purge('smtp');

            return true;
        }

        if (($requireEnabled && ! $settings->enabled) || ! $this->configured()) {
            return false;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings->smtp_host,
            'mail.mailers.smtp.port' => $settings->smtp_port,
            'mail.mailers.smtp.username' => $settings->smtp_username,
            'mail.mailers.smtp.password' => $settings->smtp_password_encrypted,
            'mail.mailers.smtp.scheme' => $settings->encryption === 'ssl' ? 'smtps' : null,
            'mail.from.address' => $settings->from_email,
            'mail.from.name' => $settings->from_name,
        ]);
        Mail::purge('smtp');

        return true;
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, User $actor): EmailSetting
    {
        $this->authorization->authorize($actor, EmailSettingsPermission::Manage);

        return DB::transaction(function () use ($data, $actor): EmailSetting {
            $settings = EmailSetting::query()->lockForUpdate()->find(1) ?? (new EmailSetting)->forceFill(['id' => 1]);
            $oldEnabled = $settings->exists ? $settings->enabled : null;
            $password = trim((string) ($data['smtp_password'] ?? ''));

            $settings->fill([
                'enabled' => (bool) $data['enabled'],
                'smtp_host' => filled($data['smtp_host'] ?? null) ? trim((string) $data['smtp_host']) : null,
                'smtp_port' => filled($data['smtp_port'] ?? null) ? (int) $data['smtp_port'] : null,
                'encryption' => filled($data['encryption'] ?? null) ? (string) $data['encryption'] : null,
                'smtp_username' => filled($data['smtp_username'] ?? null) ? trim((string) $data['smtp_username']) : null,
                'from_email' => trim((string) $data['from_email']),
                'from_name' => trim((string) $data['from_name']),
                'updated_by_user_id' => $actor->id,
            ]);
            if ($password !== '') {
                $settings->smtp_password_encrypted = $password;
            }
            $settings->save();

            $this->activity->log('email_settings.updated', $actor, $settings, [
                'enabled_before' => $oldEnabled,
                'enabled_after' => $settings->enabled,
                'configuration_complete' => $this->configuredFields($settings),
                'actor_id' => $actor->id,
            ]);

            return $settings->refresh();
        });
    }

    public function sendTest(string $recipient, User $actor): void
    {
        $this->authorization->authorize($actor, EmailSettingsPermission::Test);
        $settings = $this->settings();
        if (! $settings instanceof EmailSetting || ! $this->apply(false)) {
            throw new RuntimeException('Unable to send the test email. Check the SMTP configuration.');
        }

        $this->activity->log('email_settings.test_initiated', $actor, $settings, ['actor_id' => $actor->id]);

        try {
            Notification::route('mail', $recipient)->notifyNow(new SmtpTestNotification);
            $settings->forceFill(['last_successful_test_at' => now()])->save();
            $this->activity->log('email_settings.test_succeeded', $actor, $settings, ['actor_id' => $actor->id]);
        } catch (Throwable $exception) {
            $settings->forceFill(['last_failed_test_at' => now()])->save();
            Log::error('SMTP test email failed.', ['exception_class' => $exception::class, 'exception_code' => (string) $exception->getCode()]);
            $this->activity->log('email_settings.test_failed', $actor, $settings, ['actor_id' => $actor->id]);

            throw new RuntimeException('Unable to send the test email. Check the SMTP configuration.', previous: $exception);
        }
    }

    private function configuredFields(EmailSetting $settings): bool
    {
        return filled($settings->smtp_host) && filled($settings->smtp_port) && filled($settings->smtp_username)
            && filled($settings->smtp_password_encrypted) && filled($settings->from_email);
    }
}
