<?php

namespace Tests\Feature\Production;

use App\Services\Backups\BackupHealthService;
use App\Services\Backups\BackupRetentionService;
use App\Services\Backups\ErpBackupService;
use App\Services\Backups\ErpRestoreService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupRestoreTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tpz-erp-backup-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->workspace.'/storage/public/company-profile');
        File::ensureDirectoryExists($this->workspace.'/storage/private/documents');
        File::ensureDirectoryExists($this->workspace.'/storage/private/customer-return-evidence/originals');
        File::ensureDirectoryExists($this->workspace.'/storage/private/customer-return-evidence/labels');
        File::ensureDirectoryExists($this->workspace.'/storage/private/livewire-tmp');
        file_put_contents($this->workspace.'/storage/public/company-profile/logo.png', 'logo-content');
        file_put_contents($this->workspace.'/storage/private/documents/recovery.txt', 'recoverable-content');
        file_put_contents($this->workspace.'/storage/private/customer-return-evidence/originals/photo.jpg', 'original-photo');
        file_put_contents($this->workspace.'/storage/private/customer-return-evidence/originals/video.mp4', 'original-video');
        file_put_contents($this->workspace.'/storage/private/customer-return-evidence/labels/claim.mp4', 'labeled-video');
        file_put_contents($this->workspace.'/storage/private/livewire-tmp/temporary.txt', 'temporary-content');
        file_put_contents($this->workspace.'/storage/public/.gitignore', '*');

        $database = $this->workspace.'/source.sqlite';
        $pdo = new PDO('sqlite:'.$database);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('2026_08_26_090000_create_login_email_recovery_and_expenses', 42)");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name VARCHAR NOT NULL)');
        $pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Owner'), (2, 'Employee')");

        config([
            'database.default' => 'backup_test',
            'database.connections.backup_test' => [
                'driver' => 'sqlite',
                'database' => $database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'backup.root' => $this->workspace.'/backups',
            'backup.storage.enabled' => true,
            'backup.storage.roots' => [
                'public' => $this->workspace.'/storage/public',
                'private' => $this->workspace.'/storage/private',
            ],
            'backup.storage.excluded_segments' => ['backups', 'livewire-tmp'],
            'backup.storage.excluded_files' => ['.gitignore'],
            'backup.retention.daily' => 14,
            'backup.retention.weekly' => 8,
            'backup.retention.monthly' => 6,
        ]);
        DB::purge('backup_test');
    }

    protected function tearDown(): void
    {
        DB::purge('backup_test');
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_backup_creates_verified_database_storage_and_manifest(): void
    {
        $result = app(ErpBackupService::class)->create();
        $manifest = $result['manifest'];

        $this->assertDirectoryExists($result['path']);
        $this->assertSame('successful', $manifest['status']);
        $this->assertSame('manual', $manifest['purpose']);
        $this->assertTrue($manifest['retention_protected']);
        $this->assertSame('sqlite', $manifest['database_driver']);
        $this->assertStringEndsWith('+00:00', $manifest['backup_timestamp']);
        $this->assertSame('2026_08_26_090000_create_login_email_recovery_and_expenses', $manifest['latest_migration']);
        $this->assertCount(2, $manifest['files']);
        $this->assertSame(['database', 'storage'], array_column($manifest['files'], 'kind'));

        foreach ($manifest['files'] as $file) {
            $path = $result['path'].'/'.$file['filename'];
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, $file['bytes']);
            $this->assertSame($file['sha256'], hash_file('sha256', $path));
        }

        $database = new PDO('sqlite:'.$result['path'].'/database.sqlite');
        $this->assertSame('ok', $database->query('PRAGMA integrity_check')->fetchColumn());
        $this->assertSame(2, (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path'].'/storage.zip'));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();

        $this->assertContains('public/company-profile/logo.png', $names);
        $this->assertContains('private/documents/recovery.txt', $names);
        $this->assertContains('private/customer-return-evidence/originals/photo.jpg', $names);
        $this->assertContains('private/customer-return-evidence/originals/video.mp4', $names);
        $this->assertContains('private/customer-return-evidence/labels/claim.mp4', $names);
        $this->assertNotContains('private/livewire-tmp/temporary.txt', $names);
        $this->assertNotContains('public/.gitignore', $names);
    }

    public function test_private_disk_permissions_allow_only_owner_and_approved_backup_group_to_read(): void
    {
        $permissions = config('filesystems.disks.local.permissions');
        $this->assertSame(0640, $permissions['file']['private']);
        $this->assertSame(0750, $permissions['dir']['private']);
        $this->assertSame('private', config('filesystems.disks.local.visibility'));
        $this->assertSame('local', config('filesystems.default'));
    }

    public function test_scheduled_backups_are_explicitly_unprotected_and_default_manual_backups_are_protected(): void
    {
        $result = app(ErpBackupService::class)->create(includeDatabase: true, includeStorage: true, purpose: 'scheduled');
        $this->assertSame('scheduled', $result['manifest']['purpose']);
        $this->assertFalse($result['manifest']['retention_protected']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup purpose');
        app(ErpBackupService::class)->create(purpose: 'untrusted');
    }

    public function test_storage_only_backup_creates_a_verified_archive_for_empty_configured_roots(): void
    {
        File::deleteDirectory($this->workspace.'/storage/public');
        File::deleteDirectory($this->workspace.'/storage/private');
        File::ensureDirectoryExists($this->workspace.'/storage/public');
        File::ensureDirectoryExists($this->workspace.'/storage/private');

        $result = app(ErpBackupService::class)->create(includeDatabase: false, includeStorage: true);
        $manifest = $result['manifest'];
        $storage = $manifest['files'][0];
        $archive = $result['path'].'/storage.zip';

        $this->assertCount(1, $manifest['files']);
        $this->assertSame('storage', $storage['kind']);
        $this->assertFileExists($archive);
        $this->assertGreaterThan(0, filesize($archive));
        $this->assertSame(filesize($archive), $storage['bytes']);
        $this->assertSame(hash_file('sha256', $archive), $storage['sha256']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();

        $this->assertContains('public/', $names);
        $this->assertContains('private/', $names);
        $this->assertFileDoesNotExist($result['path'].'/database.sqlite');
    }

    public function test_storage_only_backup_includes_normal_files_and_preserves_exclusions(): void
    {
        $result = app(ErpBackupService::class)->create(includeDatabase: false, includeStorage: true);
        $archive = $result['path'].'/storage.zip';
        $zip = new ZipArchive;

        $this->assertTrue($zip->open($archive));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();

        $this->assertContains('public/company-profile/logo.png', $names);
        $this->assertContains('private/documents/recovery.txt', $names);
        $this->assertNotContains('private/livewire-tmp/temporary.txt', $names);
        $this->assertNotContains('public/.gitignore', $names);
        $this->assertFileDoesNotExist($result['path'].'/database.sqlite');
    }

    public function test_excluded_unreadable_directory_is_pruned_before_storage_traversal(): void
    {
        $excluded = $this->workspace.'/storage/private/livewire-tmp';
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($excluded, 0000);
        }

        try {
            $result = app(ErpBackupService::class)->create(includeDatabase: false, includeStorage: true);
        } finally {
            if (PHP_OS_FAMILY !== 'Windows') {
                chmod($excluded, 0770);
            }
        }

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path'].'/storage.zip'));
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $names[] = $zip->getNameIndex($index);
        }
        $zip->close();

        $this->assertContains('private/documents/recovery.txt', $names);
        $this->assertNotContains('private/livewire-tmp/', $names);
        $this->assertNotContains('private/livewire-tmp/temporary.txt', $names);
    }

    public function test_storage_size_preflight_ignores_excluded_segments_and_files(): void
    {
        $service = app(ErpBackupService::class);
        $method = new \ReflectionMethod($service, 'directorySize');

        $this->assertSame(
            strlen('recoverable-content') + strlen('original-photo') + strlen('original-video') + strlen('labeled-video'),
            $method->invoke($service, $this->workspace.'/storage/private'),
        );
        $this->assertSame(
            strlen('logo-content'),
            $method->invoke($service, $this->workspace.'/storage/public'),
        );
    }

    public function test_backup_artifacts_use_private_shared_group_permissions_on_posix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permission bits are not available on Windows.');
        }

        $result = app(ErpBackupService::class)->create();

        $this->assertPosixMode(config('backup.root'), 0770);
        $this->assertPosixMode($result['path'], 0770);
        $this->assertPosixMode($result['path'].'/database.sqlite', 0660);
        $this->assertPosixMode($result['path'].'/storage.zip', 0660);
        $this->assertPosixMode($result['path'].'/manifest.json', 0660);

        $sharedGroup = filegroup(config('backup.root'));
        $this->assertNotFalse($sharedGroup);
        foreach ([
            $result['path'],
            $result['path'].'/database.sqlite',
            $result['path'].'/storage.zip',
            $result['path'].'/manifest.json',
        ] as $artifact) {
            clearstatcache(true, $artifact);
            $this->assertSame($sharedGroup, filegroup($artifact), 'Incorrect backup group for '.basename($artifact));
        }
    }

    public function test_disposable_restore_preserves_database_and_storage(): void
    {
        $backup = app(ErpBackupService::class)->create();
        $restoredDatabase = $this->workspace.'/restored/database.sqlite';
        $restoredStorage = $this->workspace.'/restored/storage';

        $result = app(ErpRestoreService::class)->restoreToDisposableTargets(
            $backup['path'],
            $restoredDatabase,
            $restoredStorage,
        );

        $this->assertSame('ok', $result['database']['integrity']);
        $this->assertSame(0, $result['database']['foreign_key_violations']);
        $this->assertSame($backup['manifest']['latest_migration'], $result['database']['latest_migration']);
        $this->assertSame(5, $result['storage_files']);
        $this->assertFileExists($restoredStorage.'/public/company-profile/logo.png');
        $this->assertFileExists($restoredStorage.'/private/documents/recovery.txt');
        $this->assertFileExists($restoredStorage.'/private/customer-return-evidence/originals/photo.jpg');
        $this->assertFileExists($restoredStorage.'/private/customer-return-evidence/originals/video.mp4');
        $this->assertFileExists($restoredStorage.'/private/customer-return-evidence/labels/claim.mp4');

        $pdo = new PDO('sqlite:'.$restoredDatabase);
        $this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_corrupted_backup_is_rejected_before_restore(): void
    {
        $backup = app(ErpBackupService::class)->create();
        file_put_contents($backup['path'].'/database.sqlite', 'corrupted');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum validation failed');
        app(ErpRestoreService::class)->validate($backup['path']);
    }

    public function test_restore_command_requires_an_explicit_target(): void
    {
        $backup = app(ErpBackupService::class)->create();

        $this->artisan('erp:restore', ['backup' => $backup['path']])
            ->expectsOutputToContain('No restore target was provided')
            ->assertExitCode(2);
    }

    public function test_restore_service_refuses_the_active_database_even_with_force(): void
    {
        $backup = app(ErpBackupService::class)->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Active database restore is intentionally disabled');
        app(ErpRestoreService::class)->restoreToDisposableTargets(
            $backup['path'],
            (string) config('database.connections.backup_test.database'),
            null,
            true,
        );
    }

    public function test_retention_keeps_newest_and_tiered_recovery_points(): void
    {
        config([
            'backup.retention.daily' => 2,
            'backup.retention.weekly' => 1,
            'backup.retention.monthly' => 1,
        ]);
        $root = config('backup.root');
        File::ensureDirectoryExists($root);

        foreach (range(0, 12) as $daysAgo) {
            $timestamp = CarbonImmutable::parse('2026-08-26 02:00:00 UTC')->subDays($daysAgo);
            $directory = $root.'/'.$timestamp->format('Y-m-d_His');
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.'/manifest.json', json_encode([
                'status' => 'successful',
                'purpose' => 'scheduled',
                'retention_protected' => false,
                'backup_timestamp' => $timestamp->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        $plan = app(BackupRetentionService::class)->plan();
        $newest = $root.DIRECTORY_SEPARATOR.'2026-08-26_020000';

        $this->assertContains($newest, $plan['kept']);
        $this->assertNotEmpty($plan['expired']);
        $dryRun = app(BackupRetentionService::class)->prune(true);
        $this->assertDirectoryExists($dryRun['deleted'][0]);
        app(BackupRetentionService::class)->prune(false);
        $this->assertDirectoryExists($newest);
        $this->assertDirectoryDoesNotExist($plan['expired'][0]);
    }

    public function test_retention_preserves_legacy_and_protected_same_day_backups_after_a_newer_scheduled_run(): void
    {
        config([
            'backup.retention.daily' => 1,
            'backup.retention.weekly' => 0,
            'backup.retention.monthly' => 0,
        ]);

        $root = config('backup.root');
        File::ensureDirectoryExists($root);
        $fixture = function (string $name, array $metadata) use ($root): string {
            $directory = $root.'/'.$name;
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.'/manifest.json', json_encode([
                'status' => 'successful',
                'backup_timestamp' => CarbonImmutable::createFromFormat('!Y-m-d_His', $name, 'UTC')->toIso8601String(),
            ] + $metadata, JSON_THROW_ON_ERROR));

            return $directory;
        };

        // All five share the same UTC daily, weekly and monthly buckets.
        $legacyFull = $fixture('2026-11-01_141816', []); // old manifest with unknown intent
        $deployment = $fixture('2026-11-01_142930', ['purpose' => 'deployment', 'retention_protected' => true]);
        $manual = $fixture('2026-11-01_143030', ['purpose' => 'manual', 'retention_protected' => true]);
        $earlyScheduled = $fixture('2026-11-01_150000', ['purpose' => 'scheduled', 'retention_protected' => false]);
        $latestScheduled = $fixture('2026-11-01_210016', ['purpose' => 'scheduled', 'retention_protected' => false]);

        $plan = app(BackupRetentionService::class)->plan();
        foreach ([$legacyFull, $deployment, $manual, $latestScheduled] as $mustKeep) {
            $this->assertContains($mustKeep, $plan['kept']);
            $this->assertNotContains($mustKeep, $plan['expired']);
        }
        $this->assertSame([$earlyScheduled], $plan['expired']);

        $dryRun = app(BackupRetentionService::class)->prune(true);
        $this->assertSame([$earlyScheduled], $dryRun['deleted']);
        $this->assertDirectoryExists($earlyScheduled);

        $actual = app(BackupRetentionService::class)->prune();
        $this->assertSame([$earlyScheduled], $actual['deleted']);
        $this->assertDirectoryDoesNotExist($earlyScheduled);
        foreach ([$legacyFull, $deployment, $manual, $latestScheduled] as $mustKeep) {
            $this->assertDirectoryExists($mustKeep);
        }
    }

    public function test_retention_rejects_protected_manifests_even_if_the_plan_was_cached_prior_to_protection(): void
    {
        config([
            'backup.retention.daily' => 1,
            'backup.retention.weekly' => 0,
            'backup.retention.monthly' => 0,
        ]);
        $root = config('backup.root');
        File::ensureDirectoryExists($root);

        foreach (['2026-11-01_120000', '2026-11-01_210000'] as $name) {
            $directory = $root.'/'.$name;
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.'/manifest.json', json_encode([
                'status' => 'successful',
                'purpose' => 'scheduled',
                'retention_protected' => false,
                'backup_timestamp' => CarbonImmutable::createFromFormat('!Y-m-d_His', $name, 'UTC')->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
        }

        $candidate = $root.'/2026-11-01_120000';
        $this->assertContains($candidate, app(BackupRetentionService::class)->plan()['expired']);
        // A protected backup with the same day never enters a prune plan.
        $manifest = json_decode(file_get_contents($candidate.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['retention_protected'] = true;
        file_put_contents($candidate.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

        $plan = app(BackupRetentionService::class)->plan();
        $this->assertNotContains($candidate, $plan['expired']);
        app(BackupRetentionService::class)->prune();
        $this->assertDirectoryExists($candidate);
    }

    public function test_failed_backup_does_not_prune_a_previous_successful_backup(): void
    {
        $good = app(ErpBackupService::class)->create();
        config(['database.connections.backup_test.database' => $this->workspace.'/missing.sqlite']);
        DB::purge('backup_test');

        $this->artisan('erp:backup')->assertFailed();

        $this->assertDirectoryExists($good['path']);
        $this->assertFileExists($good['path'].'/manifest.json');

        if (PHP_OS_FAMILY !== 'Windows') {
            $incomplete = collect(File::directories(config('backup.root')))
                ->first(fn (string $path): bool => str_starts_with(basename($path), '.incomplete-'));
            $this->assertNotNull($incomplete);
            $this->assertPosixMode($incomplete, 0770);
            $this->assertPosixMode($incomplete.'/manifest.json', 0660);
        }
    }

    public function test_backup_health_reports_latest_checksum_state(): void
    {
        $backup = app(ErpBackupService::class)->create();
        $health = app(BackupHealthService::class)->status();

        $this->assertTrue($health['configured']);
        $this->assertTrue($health['checksums_valid']);
        $this->assertSame(
            str_replace('\\', '/', $backup['path'].'/manifest.json'),
            str_replace('\\', '/', $health['manifest_path']),
        );
        $this->assertSame(0, $health['age_hours']);
    }

    private function assertPosixMode(string $path, int $expected): void
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);
        $this->assertNotFalse($permissions);
        $this->assertSame($expected, $permissions & 0777);
    }
}
