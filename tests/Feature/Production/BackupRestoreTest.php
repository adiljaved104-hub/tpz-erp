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
        File::ensureDirectoryExists($this->workspace.'/storage/private/livewire-tmp');
        file_put_contents($this->workspace.'/storage/public/company-profile/logo.png', 'logo-content');
        file_put_contents($this->workspace.'/storage/private/documents/recovery.txt', 'recoverable-content');
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
        $this->assertSame('sqlite', $manifest['database_driver']);
        $this->assertStringEndsWith('+00:00', $manifest['backup_timestamp']);
        $this->assertSame('2026_08_26_090000_create_login_email_recovery_and_expenses', $manifest['latest_migration']);
        $this->assertCount(2, $manifest['files']);

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
        $this->assertNotContains('private/livewire-tmp/temporary.txt', $names);
        $this->assertNotContains('public/.gitignore', $names);
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
        $this->assertSame(2, $result['storage_files']);
        $this->assertFileExists($restoredStorage.'/public/company-profile/logo.png');
        $this->assertFileExists($restoredStorage.'/private/documents/recovery.txt');

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
