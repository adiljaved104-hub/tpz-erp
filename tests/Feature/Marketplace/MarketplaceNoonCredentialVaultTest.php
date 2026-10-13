<?php

namespace Tests\Feature\Marketplace;

use App\Enums\EmployeeRole;
use App\Filament\Pages\MarketplaceOperations;
use App\Models\ActivityLog;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceCredentialVault;
use App\Models\MarketplacePlatform;
use App\Services\Marketplace\MarketplaceCredentialReferenceService;
use App\Services\Marketplace\NoonConnectionTester;
use App\Services\Marketplace\NoonCredentialManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MarketplaceNoonCredentialVaultTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_noon_credentials_are_encrypted_at_rest_and_only_opaque_reference_is_on_connection(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->saveCredentials($connection, $owner);

        $ciphertext = DB::table('marketplace_credential_vaults')->value('encrypted_payload');
        $this->assertIsString($ciphertext);
        foreach (['fake-key-id-sentinel', 'fake-project-sentinel', 'fake-private-key-sentinel', 'fake-business-model-sentinel'] as $value) {
            $this->assertStringNotContainsString($value, $ciphertext);
        }
        $this->assertSame('noon_default', $connection->fresh()->getRawOriginal('credential_reference'));
        $this->assertSame('fake-private-key-sentinel', app(MarketplaceCredentialReferenceService::class)->credentials($connection)['private_key']);
        $this->assertArrayNotHasKey('encrypted_payload', MarketplaceCredentialVault::query()->sole()->toArray());
        $this->assertNull(ActivityLog::query()->where('event', 'marketplace.noon_credentials_saved')->sole()->properties);
        $this->assertStringNotContainsString('fake-private-key-sentinel', json_encode(ActivityLog::query()->where('event', 'marketplace.noon_credentials_saved')->sole()->toArray()));
    }

    public function test_blank_private_key_preserves_saved_value_and_explicit_input_replaces_it(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->saveCredentials($connection, $owner);
        $service = app(NoonCredentialManagementService::class);

        $service->save($connection, ['key_id' => '', 'project_code' => '', 'private_key' => '', 'business_model' => 'replacement-model'], $owner);
        $preserved = app(MarketplaceCredentialReferenceService::class)->credentials($connection);
        $this->assertSame('fake-private-key-sentinel', $preserved['private_key']);
        $this->assertSame('fake-key-id-sentinel', $preserved['key_id']);
        $this->assertSame('fake-project-sentinel', $preserved['project_code']);
        $this->assertSame('replacement-model', $preserved['business_model']);

        $service->save($connection, ['private_key' => 'replacement-private-key-sentinel'], $owner);
        $this->assertSame('replacement-private-key-sentinel', app(MarketplaceCredentialReferenceService::class)->credentials($connection)['private_key']);
        $this->assertStringNotContainsString('replacement-private-key-sentinel', DB::table('marketplace_credential_vaults')->value('encrypted_payload'));
    }

    public function test_vault_precedes_env_configuration_and_env_is_only_fallback_without_vault_row(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        config()->set('marketplace_credentials.references.noon_default', [
            'enabled' => true, 'key_id' => 'fake-env-key', 'project_code' => 'fake-env-project',
            'private_key' => 'fake-env-private', 'business_model' => null,
        ]);
        $resolver = app(MarketplaceCredentialReferenceService::class);
        $this->assertSame('fake-env-private', $resolver->credentials($connection)['private_key']);

        $this->saveCredentials($connection, $owner);
        $this->assertSame('fake-private-key-sentinel', $resolver->credentials($connection)['private_key']);
        DB::table('marketplace_credential_vaults')->update(['encrypted_payload' => 'invalid-ciphertext']);
        $this->assertSame([], $resolver->credentials($connection));
    }

    public function test_only_manage_users_can_save_or_test_noon_credentials(): void
    {
        $connection = $this->connection();
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        foreach ([$admin, $staff] as $user) {
            try {
                app(NoonCredentialManagementService::class)->save($connection, ['private_key' => 'unauthorized-secret'], $user);
                $this->fail('Unauthorized credentials were saved.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('marketplace_credential_vaults', 0);
            }
            try {
                app(NoonConnectionTester::class)->test($connection, $user);
                $this->fail('Unauthorized connection test was allowed.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        $this->actingAs($staff)->get(MarketplaceOperations::getUrl())->assertForbidden();
    }

    public function test_livewire_save_clears_sensitive_state_and_never_renders_saved_values(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $id = $connection->id;
        $this->actingAs($owner);

        Livewire::test(MarketplaceOperations::class)
            ->set("noonCredentialForm.{$id}.key_id", 'fake-key-id-sentinel')
            ->set("noonCredentialForm.{$id}.project_code", 'fake-project-sentinel')
            ->set("noonCredentialForm.{$id}.private_key", 'fake-private-key-sentinel')
            ->set("noonCredentialForm.{$id}.business_model", 'fake-business-model-sentinel')
            ->call('saveNoonCredentials', $id)
            ->assertHasNoErrors()
            ->assertSet('noonCredentialForm', [])
            ->assertSee('Configured')
            ->assertDontSee('fake-key-id-sentinel')
            ->assertDontSee('fake-project-sentinel')
            ->assertDontSee('fake-private-key-sentinel')
            ->assertDontSee('fake-business-model-sentinel');

        $this->get(MarketplaceOperations::getUrl())
            ->assertOk()
            ->assertSee('Save Credentials')
            ->assertSee('Test Connection')
            ->assertSee('Configured')
            ->assertDontSee('noon_default')
            ->assertDontSee('fake-key-id-sentinel')
            ->assertDontSee('fake-project-sentinel')
            ->assertDontSee('fake-private-key-sentinel')
            ->assertDontSee('fake-business-model-sentinel');
    }

    public function test_connection_check_uses_only_noon_login_and_returns_safe_statuses(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->assertSame('Not configured', app(NoonConnectionTester::class)->test($connection, $owner));
        $this->saveCredentials($connection, $owner, $this->fakeSigningKey());
        $tester = app(NoonConnectionTester::class);

        Http::fake(['https://noon-api-gateway.noon.partners/*' => Http::sequence()
            ->push([], 200, ['Set-Cookie' => 'sid=fake-session; Path=/'])
            ->push(['error' => 'fake-secret-error-body'], 401)
            ->push(['error' => 'fake-secret-error-body'], 403)
            ->push(['error' => 'fake-secret-error-body'], 429)
            ->push(['error' => 'fake-secret-error-body'], 503)]);
        $statuses = [
            $tester->test($connection, $owner),
            $tester->test($connection, $owner),
            $tester->test($connection, $owner),
            $tester->test($connection, $owner),
            $tester->test($connection, $owner),
        ];
        $this->assertSame(['Connected', 'Authentication failed', 'Permission denied', 'Rate limited', 'Service unavailable'], $statuses);
        Http::assertSentCount(5);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/identity/public/v1/api/login'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/offer/v1/product/'));
        $this->assertStringNotContainsString('fake-secret-error-body', json_encode($statuses));
    }

    public function test_validation_errors_do_not_include_submitted_secret_values(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $secret = str_repeat('fake-secret-sentinel', 1200);

        try {
            app(NoonCredentialManagementService::class)->save($connection, ['private_key' => $secret], $owner);
            $this->fail('Oversized private key was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('fake-secret-sentinel', $exception->getMessage());
            $this->assertStringNotContainsString('fake-secret-sentinel', json_encode($exception->errors()));
        }
        $this->assertDatabaseCount('marketplace_credential_vaults', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_ui_connection_check_shows_only_fixed_safe_status(): void
    {
        $connection = $this->connection();
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $this->saveCredentials($connection, $owner, $this->fakeSigningKey());
        Http::fake(['https://noon-api-gateway.noon.partners/*' => Http::response(['error' => 'fake-secret-error-body'], 401)]);

        Livewire::actingAs($owner)->test(MarketplaceOperations::class)
            ->call('testNoonConnection', $connection->id)
            ->assertSet('noonConnectionTestStatuses.'.$connection->id, 'Authentication failed')
            ->assertSee('Authentication failed')
            ->assertDontSee('fake-secret-error-body');
        Http::assertSentCount(1);
    }

    private function connection(): MarketplaceConnection
    {
        $platform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id, 'name' => 'Default', 'code' => 'default', 'enabled' => true,
        ]);

        return MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id, 'name' => 'Noon API', 'connection_type' => 'api',
            'driver' => 'noon_api', 'priority' => 10, 'enabled' => true, 'credential_reference' => 'noon_default',
        ]);
    }

    private function saveCredentials(MarketplaceConnection $connection, $owner, string $privateKey = 'fake-private-key-sentinel'): void
    {
        app(NoonCredentialManagementService::class)->save($connection, [
            'key_id' => 'fake-key-id-sentinel', 'project_code' => 'fake-project-sentinel',
            'private_key' => $privateKey, 'business_model' => 'fake-business-model-sentinel',
        ], $owner);
    }

    private function fakeSigningKey(): string
    {
        $options = ['private_key_bits' => 2048];
        $windowsConfig = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private, null, $options);

        return $private;
    }
}
