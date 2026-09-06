<?php

namespace App\Services\DemoData;

use App\Services\Notifications\EmailConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class DemoEnvironmentGuard
{
    public function __construct(private readonly EmailConfigurationService $email) {}

    public function assertAllowed(bool $dryRun, ?string $confirmation): void
    {
        $environment = app()->environment();
        if (! in_array($environment, (array) config('demo.allowed_environments'), true)) {
            throw new RuntimeException("Demo data is denied in the [{$environment}] environment.");
        }
        if (! (bool) config('demo.enabled')) {
            throw new RuntimeException('Demo data is disabled. Set ERP_DEMO_DATA_ENABLED=true only in an approved environment.');
        }

        $allowed = trim((string) config('demo.allowed_database'));
        $actual = $this->databaseIdentity();
        if ($allowed === '' || ! hash_equals($this->normalize($allowed), $this->normalize($actual))) {
            throw new RuntimeException('The active database does not match ERP_DEMO_DATABASE_NAME.');
        }

        $this->assertSupplementalBranch($environment);

        if ($dryRun) {
            return;
        }
        $requiresConfirmation = ! app()->runningUnitTests() || (bool) config('demo.require_confirmation_in_tests', false);
        if ($requiresConfirmation && ! hash_equals((string) config('demo.confirmation'), (string) $confirmation)) {
            throw new RuntimeException('The exact staging demo confirmation token is required.');
        }
        if (blank(config('demo.password'))) {
            throw new RuntimeException('ERP_DEMO_USER_PASSWORD is required to create demo login accounts.');
        }
        if ($this->email->enabled()) {
            throw new RuntimeException('Outbound email must be disabled before persistent demo generation.');
        }
    }

    public function databaseIdentity(): string
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();

        if ($connection->getDriverName() !== 'sqlite' || $database === ':memory:') {
            return $database;
        }

        $real = realpath($database);

        return $real === false ? $database : $real;
    }

    private function normalize(string $identity): string
    {
        $identity = str_replace('\\', '/', trim($identity));

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($identity) : $identity;
    }

    private function assertSupplementalBranch(string $environment): void
    {
        if ($environment !== 'staging' || ! is_dir(base_path('.git'))) {
            return;
        }

        $result = Process::timeout(5)->run(['git', 'branch', '--show-current']);
        if ($result->successful() && trim($result->output()) !== 'staging') {
            throw new RuntimeException('The supplemental Git branch check requires staging.');
        }
    }
}
