<?php

namespace App\Filament\Pages\Administration;

use App\Enums\EmailSettingsPermission;
use App\Models\User;
use App\Notifications\SmtpTestNotification;
use App\Services\Authorization\EmailSettingsAuthorization;
use App\Services\Notifications\EmailConfigurationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class EmailDelivery extends Page
{
    protected string $view = 'filament.pages.administration.email-delivery';

    protected static ?string $slug = 'administration/email-delivery';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Email Settings';

    protected static ?string $title = 'Email Settings';

    public bool $enabled = false;

    public string $smtpHost = '';

    public ?int $smtpPort = null;

    public string $encryption = 'tls';

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public string $fromEmail = '';

    public string $fromName = '';

    public string $recipientEmail = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(EmailSettingsAuthorization::class)->allows($user, EmailSettingsPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(EmailConfigurationService $configuration): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = $configuration->settings();
        $this->enabled = $settings?->enabled ?? $configuration->enabled();
        $this->smtpHost = (string) ($settings?->smtp_host ?? config('mail.environment_fallback.host'));
        $this->smtpPort = filled($settings?->smtp_port ?? config('mail.environment_fallback.port')) ? (int) ($settings?->smtp_port ?? config('mail.environment_fallback.port')) : null;
        $this->encryption = (string) ($settings?->encryption ?? 'tls');
        $this->smtpUsername = (string) ($settings?->smtp_username ?? config('mail.environment_fallback.username'));
        $this->smtpPassword = '';
        $this->fromEmail = (string) ($settings?->from_email ?? config('mail.environment_fallback.from_address'));
        $this->fromName = (string) ($settings?->from_name ?? config('mail.environment_fallback.from_name'));
        $this->recipientEmail = (string) auth()->user()->routeNotificationForMail(new SmtpTestNotification);
    }

    public function saveSettings(EmailConfigurationService $configuration): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(EmailSettingsAuthorization::class)->authorize($user, EmailSettingsPermission::Manage);
        $hasPassword = filled($configuration->settings()?->smtp_password_encrypted);
        $validated = $this->validate([
            'enabled' => ['boolean'],
            'smtpHost' => [Rule::requiredIf($this->enabled), 'nullable', 'string', 'max:255'],
            'smtpPort' => [Rule::requiredIf($this->enabled), 'nullable', 'integer', 'between:1,65535'],
            'encryption' => [Rule::requiredIf($this->enabled), 'nullable', Rule::in(['tls', 'ssl'])],
            'smtpUsername' => [Rule::requiredIf($this->enabled), 'nullable', 'string', 'max:255'],
            'smtpPassword' => [Rule::requiredIf($this->enabled && ! $hasPassword), 'nullable', 'string', 'max:2048'],
            'fromEmail' => ['required', 'email:rfc', 'max:255'],
            'fromName' => ['required', 'string', 'max:255'],
        ]);
        $configuration->save([
            'enabled' => $validated['enabled'], 'smtp_host' => $validated['smtpHost'] ?? null,
            'smtp_port' => $validated['smtpPort'] ?? null, 'encryption' => $validated['encryption'] ?? null,
            'smtp_username' => $validated['smtpUsername'] ?? null, 'smtp_password' => $validated['smtpPassword'] ?? '',
            'from_email' => $validated['fromEmail'], 'from_name' => $validated['fromName'],
        ], $user);
        $this->smtpPassword = '';
        Notification::make()->success()->title('Email settings saved')->body('SMTP connectivity is verified separately with Send Test Email.')->send();
    }

    public function sendTestEmail(EmailConfigurationService $configuration): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        app(EmailSettingsAuthorization::class)->authorize($user, EmailSettingsPermission::Test);
        $validated = $this->validate(['recipientEmail' => ['required', 'email:rfc', 'max:255']]);

        try {
            $configuration->sendTest($validated['recipientEmail'], $user);
            Notification::make()->success()->title('Test email sent successfully.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (RuntimeException) {
            Notification::make()->danger()->title('Unable to send the test email. Check the SMTP configuration.')->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Unable to send the test email. Check the SMTP configuration.')->send();
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $settings = app(EmailConfigurationService::class)->settings();

        return [
            'settingsRecord' => $settings,
            'smtpConfigured' => app(EmailConfigurationService::class)->configured(),
            'canManage' => auth()->user()?->can(EmailSettingsPermission::Manage->value) === true,
            'canTest' => auth()->user()?->can(EmailSettingsPermission::Test->value) === true,
        ];
    }
}
