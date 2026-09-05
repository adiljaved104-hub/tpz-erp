<?php

namespace App\Filament\Pages\Administration;

use App\Enums\BackupSettingsPermission;
use App\Models\User;
use App\Services\Authorization\BackupSettingsAuthorization;
use App\Services\Backups\BackupHealthService;
use App\Services\Backups\BackupHistoryService;
use App\Services\Backups\BackupOperationsService;
use App\Services\Backups\BackupSettingsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class BackupSettings extends Page
{
    protected string $view = 'filament.pages.administration.backup-settings';

    protected static ?string $slug = 'administration/backup-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Backup Settings';

    protected static ?string $title = 'Backup Settings';

    public bool $enabled = false;

    public string $backupTime = '02:00';

    public bool $databaseEnabled = true;

    public bool $storageEnabled = true;

    public int $dailyRetention = 14;

    public int $weeklyRetention = 8;

    public int $monthlyRetention = 6;

    public string $backupDisk = 'local';

    public bool $offsiteEnabled = false;

    public bool $encryptionEnabled = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BackupSettingsAuthorization::class)->allows($user, BackupSettingsPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(BackupSettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillFrom($settings->effective());
    }

    public function saveSettings(BackupSettingsService $settings): void
    {
        $user = $this->authenticatedUser();
        app(BackupSettingsAuthorization::class)->authorize($user, BackupSettingsPermission::Manage);
        $validated = $this->validate([
            'enabled' => ['boolean'],
            'backupTime' => ['required', 'date_format:H:i'],
            'databaseEnabled' => ['boolean'],
            'storageEnabled' => ['boolean'],
            'dailyRetention' => ['required', 'integer', 'between:1,365'],
            'weeklyRetention' => ['required', 'integer', 'between:0,104'],
            'monthlyRetention' => ['required', 'integer', 'between:0,60'],
            'backupDisk' => ['required', Rule::in(['local'])],
        ]);

        if ($this->enabled && ! $this->databaseEnabled && ! $this->storageEnabled) {
            $this->addError('databaseEnabled', 'Enable Database Backup or Storage Backup before enabling automatic backups.');

            return;
        }
        if ($this->offsiteEnabled && ! config('backup.offsite.configured', false)) {
            $this->addError('offsiteEnabled', 'Offsite backup is not configured on this server.');

            return;
        }
        if ($this->encryptionEnabled && ! config('backup.encryption.configured', false)) {
            $this->addError('encryptionEnabled', 'Backup encryption is not configured on this server.');

            return;
        }

        $saved = $settings->save([
            'enabled' => $validated['enabled'],
            'backup_time' => $validated['backupTime'],
            'database_enabled' => $validated['databaseEnabled'],
            'storage_enabled' => $validated['storageEnabled'],
            'daily_retention' => $validated['dailyRetention'],
            'weekly_retention' => $validated['weeklyRetention'],
            'monthly_retention' => $validated['monthlyRetention'],
            'backup_disk' => $validated['backupDisk'],
            'offsite_enabled' => $this->offsiteEnabled,
            'encryption_enabled' => $this->encryptionEnabled,
        ], $user);
        $this->fillFrom($saved->toArray() + ['source' => 'Database settings']);
        Notification::make()->success()->title('Backup settings saved')->send();
    }

    public function runBackupNow(BackupOperationsService $operations): void
    {
        try {
            $operations->runManual($this->authenticatedUser());
            Notification::make()->success()->title('Backup completed successfully')->body('The new backup was verified during creation and retention was applied afterward.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Backup failed safely')->body('Previous successful backups were preserved.')->send();
        }
    }

    public function verifyLatestBackup(BackupOperationsService $operations): void
    {
        try {
            $result = $operations->verifyLatest($this->authenticatedUser());
            Notification::make()->success()->title('Latest backup verified')->body('Manifest, component checksums, and supported database integrity checks passed.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Backup verification failed')->body($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Backup verification failed safely')->send();
        }
    }

    public function verifyBackup(string $backupId, BackupOperationsService $operations): void
    {
        try {
            $operations->verify($backupId, $this->authenticatedUser());
            Notification::make()->success()->title('Backup verified')->body('Manifest, component checksums, and supported database integrity checks passed.')->send();
        } catch (AuthorizationException) {
            abort(403);
        } catch (RuntimeException $exception) {
            Notification::make()->danger()->title('Backup verification failed')->body($exception->getMessage())->send();
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->danger()->title('Backup verification failed safely')->send();
        }
    }

    public function refreshHistory(): void
    {
        app(BackupSettingsAuthorization::class)->authorize($this->authenticatedUser(), BackupSettingsPermission::View);
        Notification::make()->success()->title('Backup history refreshed')->send();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $user = $this->authenticatedUser();

        return [
            'health' => app(BackupHealthService::class)->status(),
            'history' => array_slice(app(BackupHistoryService::class)->all(), 0, 25),
            'settingsSource' => app(BackupSettingsService::class)->effective()['source'],
            'canManage' => app(BackupSettingsAuthorization::class)->allows($user, BackupSettingsPermission::Manage),
            'canRun' => app(BackupSettingsAuthorization::class)->allows($user, BackupSettingsPermission::Run),
            'canVerify' => app(BackupSettingsAuthorization::class)->allows($user, BackupSettingsPermission::Verify),
            'canDownload' => app(BackupSettingsAuthorization::class)->allows($user, BackupSettingsPermission::Download),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function fillFrom(array $settings): void
    {
        $this->enabled = (bool) $settings['enabled'];
        $this->backupTime = (string) $settings['backup_time'];
        $this->databaseEnabled = (bool) $settings['database_enabled'];
        $this->storageEnabled = (bool) $settings['storage_enabled'];
        $this->dailyRetention = (int) $settings['daily_retention'];
        $this->weeklyRetention = (int) $settings['weekly_retention'];
        $this->monthlyRetention = (int) $settings['monthly_retention'];
        $this->backupDisk = (string) $settings['backup_disk'];
        $this->offsiteEnabled = (bool) $settings['offsite_enabled'];
        $this->encryptionEnabled = (bool) $settings['encryption_enabled'];
    }

    private function authenticatedUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
