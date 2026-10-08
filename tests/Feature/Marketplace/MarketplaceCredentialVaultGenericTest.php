<?php

namespace Tests\Feature\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceCredentialVault;
use App\Models\MarketplacePlatform;
use App\Services\Marketplace\MarketplaceCredentialReferenceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceCredentialVaultGenericTest extends TestCase
{
    use RefreshDatabase;

    public function test_multiple_provider_references_resolve_independently_from_encrypted_vault_before_config(): void
    {
        [$noon, $amazon] = $this->connections();
        config()->set('marketplace_credentials.references.noon_default', ['private_key' => 'fake-noon-config-secret']);
        config()->set('marketplace_monitoring.amazon', ['lwa_client_secret' => 'fake-amazon-config-secret']);
        $resolver = app(MarketplaceCredentialReferenceService::class);
        $this->assertSame('fake-noon-config-secret', $resolver->credentials($noon)['private_key']);
        $this->assertSame('fake-amazon-config-secret', $resolver->credentials($amazon)['lwa_client_secret']);

        MarketplaceCredentialVault::query()->create([
            'reference' => 'noon_default',
            'encrypted_payload' => ['enabled' => true, 'private_key' => 'fake-noon-vault-secret'],
        ]);
        MarketplaceCredentialVault::query()->create([
            'reference' => 'amazon_default',
            'encrypted_payload' => [
                'enabled' => true,
                'lwa_client_secret' => 'fake-amazon-vault-secret',
                'settings' => ['region' => 'eu', 'scopes' => ['pricing', 'listing']],
            ],
        ]);

        $this->assertSame('fake-noon-vault-secret', $resolver->credentials($noon)['private_key']);
        $this->assertSame('fake-amazon-vault-secret', $resolver->credentials($amazon)['lwa_client_secret']);
        $this->assertSame(['region' => 'eu', 'scopes' => ['pricing', 'listing']], $resolver->credentials($amazon)['settings']);
        $this->assertSame('noon_default', $noon->fresh()->getRawOriginal('credential_reference'));
        $this->assertSame('amazon_default', $amazon->fresh()->getRawOriginal('credential_reference'));
        $this->assertArrayNotHasKey('credential_reference', $amazon->toArray());

        foreach (MarketplaceCredentialVault::query()->get() as $vault) {
            $raw = DB::table('marketplace_credential_vaults')->where('reference', $vault->reference)->value('encrypted_payload');
            $this->assertStringNotContainsString('fake-noon-vault-secret', $raw);
            $this->assertStringNotContainsString('fake-amazon-vault-secret', $raw);
            $this->assertArrayNotHasKey('encrypted_payload', $vault->toArray());
            $this->assertStringNotContainsString('fake-amazon-vault-secret', $vault->toJson());
            $this->assertStringNotContainsString('fake-noon-vault-secret', $vault->toJson());
        }
    }

    public function test_shared_vault_survives_connection_deletion_and_reference_replacement(): void
    {
        [, $amazon] = $this->connections();
        $other = MarketplaceConnection::query()->create([
            'marketplace_account_id' => $amazon->marketplace_account_id,
            'name' => 'Amazon fallback', 'connection_type' => 'api', 'driver' => 'amazon_sp_api',
            'priority' => 20, 'enabled' => true, 'credential_reference' => 'amazon_default',
        ]);
        MarketplaceCredentialVault::query()->create([
            'reference' => 'amazon_default',
            'encrypted_payload' => ['enabled' => true, 'lwa_client_secret' => 'fake-shared-secret'],
        ]);

        $amazon->delete();
        $this->assertDatabaseHas('marketplace_credential_vaults', ['reference' => 'amazon_default']);
        $this->assertSame('fake-shared-secret', app(MarketplaceCredentialReferenceService::class)->credentials($other)['lwa_client_secret']);

        $other->update(['credential_reference' => 'carrefour_default']);
        config()->set('marketplace_credentials.references.carrefour_default', ['api_key' => 'fake-carrefour-config-secret']);
        $this->assertDatabaseHas('marketplace_credential_vaults', ['reference' => 'amazon_default']);
        $this->assertSame('fake-carrefour-config-secret', app(MarketplaceCredentialReferenceService::class)->credentials($other)['api_key']);

        try {
            MarketplaceCredentialVault::query()->create([
                'reference' => 'amazon_default',
                'encrypted_payload' => ['private_key' => 'fake-duplicate-secret'],
            ]);
            $this->fail('The vault accepted a duplicate reference.');
        } catch (QueryException $exception) {
            $this->assertStringNotContainsString('fake-duplicate-secret', $exception->getMessage());
            $this->assertDatabaseCount('marketplace_credential_vaults', 1);
        }
    }

    /** @return array{MarketplaceConnection, MarketplaceConnection} */
    private function connections(): array
    {
        $platform = MarketplacePlatform::factory()->create();
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id,
            'name' => 'Shared account', 'code' => 'shared', 'enabled' => true,
        ]);
        $noon = MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id,
            'name' => 'Noon API', 'connection_type' => 'api', 'driver' => 'noon_api',
            'priority' => 10, 'enabled' => true, 'credential_reference' => 'noon_default',
        ]);
        $amazon = MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id,
            'name' => 'Amazon SP-API', 'connection_type' => 'api', 'driver' => 'amazon_sp_api',
            'priority' => 20, 'enabled' => true, 'credential_reference' => 'amazon_default',
        ]);

        return [$noon, $amazon];
    }
}
