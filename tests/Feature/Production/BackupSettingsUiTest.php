<?php

namespace Tests\Feature\Production;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\BackupSettingsPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Administration\BackupSettings as BackupSettingsPage;
use App\Models\BackupSetting;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\BackupSettingsAuthorization;
use App\Services\Backups\BackupHistoryService;
use App\Services\Backups\BackupOperationsService;
use App\Services\Backups\BackupRetentionService;
use App\Services\Backups\BackupSettingsService;
use App\Services\Backups\ErpBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PDO;
use RuntimeException;
use Tests\TestCase;

class BackupSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    private string $backupRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tpz-backup-settings-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->backupRoot);
        config(['backup.root' => $this->backupRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backupRoot);
        parent::tearDown();
    }

    public function test_role_defaults_navigation_and_employee_overrides_are_enforced(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $admin = $this->user(EmployeeRole::Admin);
        $manager = $this->user(EmployeeRole::Manager);
        $staff = $this->user(EmployeeRole::Staff);
        $authorization = app(BackupSettingsAuthorization::class);

        $this->assertTrue($authorization->allows($owner, BackupSettingsPermission::Download));
        $this->assertTrue($authorization->allows($admin, BackupSettingsPermission::Manage));
        $this->assertFalse($authorization->allows($admin, BackupSettingsPermission::Download));
        $this->assertFalse($authorization->allows($manager, BackupSettingsPermission::View));
        $this->assertFalse($authorization->allows($staff, BackupSettingsPermission::View));
        $this->actingAs($manager)->get(BackupSettingsPage::getUrl())->assertForbidden();

        EmployeePermissionOverride::query()->create([
            'employee_id' => $manager->employee->id,
            'permission_key' => BackupSettingsPermission::View->value,
            'effect' => EmployeePermissionEffect::Allow,
            'granted_by_user_id' => $owner->id,
        ]);
        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($manager->employee->id);
        $this->assertTrue($authorization->allows($manager, BackupSettingsPermission::View));
        $manager = $manager->fresh('employee');
        $this->actingAs($manager)->get(BackupSettingsPage::getUrl())->assertOk();
    }

    public function test_database_settings_override_fallback_and_page_saves_singleton_with_audit(): void
    {
        config([
            'backup.schedule.enabled' => false,
            'backup.schedule.time' => '04:15',
            'backup.retention.daily' => 9,
        ]);
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(BackupSettingsService::class);

        $this->assertSame('Configuration fallback', $service->effective()['source']);
        $this->assertSame('04:15', $service->effective()['backup_time']);

        Livewire::actingAs($owner)->test(BackupSettingsPage::class)
            ->assertSee('Backup Status')
            ->set('enabled', true)
            ->set('backupTime', '03:30')
            ->set('databaseEnabled', true)
            ->set('storageEnabled', false)
            ->set('dailyRetention', 21)
            ->set('weeklyRetention', 10)
            ->set('monthlyRetention', 12)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertNotified('Backup settings saved');

        $this->assertSame(1, BackupSetting::query()->count());
        $this->assertDatabaseHas('backup_settings', ['id' => 1, 'enabled' => 1, 'backup_time' => '03:30', 'daily_retention' => 21]);
        $this->assertSame('Database settings', $service->effective()['source']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'backup_settings.updated', 'subject_type' => 'backup_setting', 'subject_id' => 1]);
    }

    public function test_enabled_backups_require_at_least_one_component(): void
    {
        $owner = $this->user(EmployeeRole::Owner);

        Livewire::actingAs($owner)->test(BackupSettingsPage::class)
            ->set('enabled', true)
            ->set('databaseEnabled', false)
            ->set('storageEnabled', false)
            ->call('saveSettings')
            ->assertHasErrors(['databaseEnabled']);

        $this->assertDatabaseCount('backup_settings', 0);
    }

    public function test_history_verification_checks_manifest_checksum_and_sqlite_integrity(): void
    {
        $directory = $this->backupRoot.'/2026-08-26_160000';
        File::ensureDirectoryExists($directory);
        $database = $directory.'/database.sqlite';
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY)');
        unset($pdo);
        file_put_contents($directory.'/manifest.json', json_encode([
            'manifest_version' => 1,
            'status' => 'successful',
            'backup_timestamp' => '2026-08-26T16:00:00+00:00',
            'database_driver' => 'sqlite',
            'files' => [[
                'kind' => 'database', 'filename' => 'database.sqlite',
                'bytes' => filesize($database), 'sha256' => hash_file('sha256', $database),
            ]],
        ], JSON_THROW_ON_ERROR));

        $service = app(BackupHistoryService::class);
        $owner = $this->user(EmployeeRole::Owner);
        $verified = app(BackupOperationsService::class)->verify('2026-08-26_160000', $owner);
        $this->assertSame('ok', $verified['database_integrity']);
        $this->assertSame(0, $verified['foreign_key_violations']);
        $this->assertSame('2026-08-26_160000', $service->latestSuccessful()['id']);
        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.verification_completed', 'actor_user_id' => $owner->id]);

        try {
            $service->verify('../database.sqlite');
            $this->fail('Path traversal was not rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('identifier is invalid', $exception->getMessage());
        }

        file_put_contents($database, 'corrupt');
        try {
            app(BackupOperationsService::class)->verify('2026-08-26_160000', $owner);
            $this->fail('Corrupt backup was not rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('checksum', $exception->getMessage());
        }
        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.verification_failed', 'actor_user_id' => $owner->id]);
    }

    public function test_next_schedule_uses_effective_database_time_and_disabled_state(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $service = app(BackupSettingsService::class);
        $service->save([
            'enabled' => true, 'backup_time' => '02:30', 'database_enabled' => true, 'storage_enabled' => false,
            'daily_retention' => 14, 'weekly_retention' => 8, 'monthly_retention' => 6, 'backup_disk' => 'local',
            'offsite_enabled' => false, 'encryption_enabled' => false,
        ], $owner);

        $this->assertSame('2026-08-27 02:30', $service->nextScheduledAt(CarbonImmutable::parse('2026-08-26 03:00 UTC'))->format('Y-m-d H:i'));
        BackupSetting::query()->findOrFail(1)->update(['enabled' => false]);
        $this->assertNull($service->nextScheduledAt());
    }

    public function test_manual_operation_uses_selected_components_then_retention_and_audits(): void
    {
        $owner = $this->user(EmployeeRole::Owner);
        $settings = app(BackupSettingsService::class);
        $settings->save([
            'enabled' => false, 'backup_time' => '02:00', 'database_enabled' => true, 'storage_enabled' => false,
            'daily_retention' => 14, 'weekly_retention' => 8, 'monthly_retention' => 6, 'backup_disk' => 'local',
            'offsite_enabled' => false, 'encryption_enabled' => false,
        ], $owner);

        $backup = \Mockery::mock(ErpBackupService::class);
        $backup->shouldReceive('create')->once()->with(true, false)->andReturn([
            'path' => $this->backupRoot.'/2026-08-26_170000',
            'manifest' => ['files' => [['kind' => 'database']]],
        ]);
        $retention = \Mockery::mock(BackupRetentionService::class);
        $retention->shouldReceive('prune')->once()->andReturn(['kept' => [], 'deleted' => []]);
        $operations = new BackupOperationsService(
            app(BackupSettingsAuthorization::class),
            $settings,
            $backup,
            $retention,
            app(BackupHistoryService::class),
            app(ActivityLogger::class),
        );

        $operations->runManual($owner);

        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.manual_started', 'actor_user_id' => $owner->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'backup.manual_completed', 'actor_user_id' => $owner->id]);
    }

    public function test_corrupt_backup_manifest_does_not_crash_health_history_or_owner_page(): void
    {
        $validDirectory = $this->backupRoot.'/2026-10-29_020000';
        $badDirectory = $this->backupRoot.'/2026-10-30_020000';
        File::ensureDirectoryExists($validDirectory);
        File::ensureDirectoryExists($badDirectory);

        $contents = 'example backup file';
        file_put_contents($validDirectory.'/database.sql', $contents);
        file_put_contents($validDirectory.'/manifest.json', json_encode([
            'backup_timestamp' => '2026-10-29T02:00:00+00:00',
            'status' => 'successful',
            'database_driver' => 'mysql',
            'files' => [['kind' => 'database', 'filename' => 'database.sql',
                'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents)]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($badDirectory.'/manifest.json', '{invalid');

        $health = app(\App\Services\Backups\BackupHealthService::class)->status();
        $this->assertTrue($health['access_warning']);
        $this->assertSame(1, $health['unreadable_manifests']);
        $this->assertTrue($health['checksums_valid']);

        $history = app(BackupHistoryService::class)->all();
        $this->assertCount(1, $history);
        $this->assertSame('2026-10-29_020000', $history[0]['id']);

        Livewire::actingAs($this->user(EmployeeRole::Owner))
            ->test(BackupSettingsPage::class)
            ->assertSee('Backup access needs attention')
            ->assertSee('29 Oct 2026');
    }

    public function test_unreadable_manifest_is_warned_about_without_a_server_error_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX unreadable file permission is not available on Windows.');
        }

        $directory = $this->backupRoot.'/2026-10-30_020000';
        File::ensureDirectoryExists($directory);
        $manifestPath = $directory.'/manifest.json';
        file_put_contents($manifestPath, '{"status":"successful","backup_timestamp":"2026-10-30T02:00:00+00:00"}');
        chmod($manifestPath, 0000);
        if (is_readable($manifestPath)) {
            chmod($manifestPath, 0600);
            $this->markTestSkipped('The test process can still read mode 0000 files.');
        }

        try {
            $this->assertTrue(app(\App\Services\Backups\BackupHealthService::class)->status()['access_warning']);
            $this->assertCount(0, app(BackupHistoryService::class)->all());
            Livewire::actingAs($this->user(EmployeeRole::Owner))
                ->test(BackupSettingsPage::class)
                ->assertSee('Backup access needs attention');
        } finally {
            chmod($manifestPath, 0600);
        }
    }

    public function test_bad_manifest_file_entries_do_not_cause_a_type_error(): void
    {
        $directory = $this->backupRoot.'/2026-10-31_020000';
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/manifest.json', json_encode([
            'backup_timestamp' => '2026-10-31T02:00:00+00:00',
            'status' => 'successful',
            'database_driver' => 'mysql',
            'files' => ['invalid entry', null],
        ], JSON_THROW_ON_ERROR));

        $health = app(\App\Services\Backups\BackupHealthService::class)->status();
        $this->assertFalse($health['checksums_valid']);

        $history = app(BackupHistoryService::class)->all();
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['database']);

        Livewire::actingAs($this->user(EmployeeRole::Owner))
            ->test(BackupSettingsPage::class)
            ->assertSee('Backup Status');
    }

    public function test_latest_failed_backup_attempt_is_visible_to_owner_without_an_error(): void
    {
        $directory = $this->backupRoot.'/.incomplete-2026-10-31_020000-error';
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/manifest.json', json_encode([
            'backup_timestamp' => '2026-10-31T02:00:00+00:00',
            'status' => 'failed',
            'database_driver' => 'mysql',
            'files' => [],
        ], JSON_THROW_ON_ERROR));

        $history = app(BackupHistoryService::class)->all();
        $this->assertCount(1, $history);
        $this->assertSame('.incomplete-2026-10-31_020000-error', $history[0]['id']);
        $this->assertSame('failed', $history[0]['status']);
        $this->assertNull(app(BackupHistoryService::class)->latestSuccessful());

        Livewire::actingAs($this->user(EmployeeRole::Owner))
            ->test(BackupSettingsPage::class)
            ->assertSee('Most recent backup attempt failed');
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => strtolower($role->value).'-'.bin2hex(random_bytes(3)).'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }
}
