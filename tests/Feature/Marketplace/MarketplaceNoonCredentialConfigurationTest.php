<?php

namespace Tests\Feature\Marketplace;

use App\Enums\EmployeeRole;
use App\Filament\Pages\MarketplaceOperations;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplacePlatform;
use App\Services\Marketplace\MarketplaceCredentialReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MarketplaceNoonCredentialConfigurationTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_noon_default_resolves_env_backed_values_and_decodes_private_key(): void
    {
        $privateKey = "fake-private-key\nfor-tests-only";
        $reference = $this->noonReference([
            'NOON_API_ENABLED' => 'true',
            'NOON_API_KEY_ID' => 'fake-key-id',
            'NOON_API_PROJECT_CODE' => 'fake-project',
            'NOON_API_PRIVATE_KEY_BASE64' => base64_encode($privateKey),
            'NOON_API_BUSINESS_MODEL' => 'fake-model',
        ]);
        config()->set('marketplace_credentials.references.noon_default', $reference);
        $connection = (new MarketplaceConnection(['credential_reference' => 'noon_default']))->syncOriginal();

        $this->assertSame('noon_default', $connection->getRawOriginal('credential_reference'));
        $this->assertSame([
            'enabled' => true,
            'key_id' => 'fake-key-id',
            'project_code' => 'fake-project',
            'private_key' => $privateKey,
            'business_model' => 'fake-model',
        ], app(MarketplaceCredentialReferenceService::class)->credentials($connection));
    }

    public function test_blank_and_invalid_private_key_base64_fail_safely(): void
    {
        foreach (['', '*not-base64*'] as $encoded) {
            $reference = $this->noonReference([
                'NOON_API_ENABLED' => 'true',
                'NOON_API_PRIVATE_KEY_BASE64' => $encoded,
                'NOON_API_BUSINESS_MODEL' => '',
            ]);

            $this->assertNull($reference['private_key']);
            $this->assertNull($reference['business_model']);
        }

        $this->assertFalse($this->noonReference(['NOON_API_ENABLED' => 'false'])['enabled']);
    }

    public function test_noon_credentials_are_not_exposed_in_marketplace_operations(): void
    {
        $privateKey = 'fake-private-key-ui-sentinel';
        $encodedKey = base64_encode($privateKey);
        config()->set('marketplace_credentials.references.noon_default', $this->noonReference([
            'NOON_API_ENABLED' => 'true',
            'NOON_API_KEY_ID' => 'fake-key-id-ui-sentinel',
            'NOON_API_PROJECT_CODE' => 'fake-project-ui-sentinel',
            'NOON_API_PRIVATE_KEY_BASE64' => $encodedKey,
            'NOON_API_BUSINESS_MODEL' => 'fake-model-ui-sentinel',
        ]));
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Noon UAE']);
        $account = MarketplaceAccount::query()->create([
            'marketplace_platform_id' => $platform->id,
            'name' => 'Default',
            'code' => 'default',
            'enabled' => true,
        ]);
        $connection = MarketplaceConnection::query()->create([
            'marketplace_account_id' => $account->id,
            'name' => 'Noon Monitor',
            'connection_type' => 'api',
            'driver' => 'noon_api',
            'priority' => 10,
            'enabled' => true,
            'credential_reference' => 'noon_default',
        ]);

        $this->assertSame('noon_default', $connection->fresh()->getRawOriginal('credential_reference'));
        $this->actingAs($owner)->get(MarketplaceOperations::getUrl())
            ->assertOk()
            ->assertSee('Noon API')
            ->assertSee('Configured')
            ->assertDontSee('noon_default')
            ->assertDontSee('fake-key-id-ui-sentinel')
            ->assertDontSee('fake-project-ui-sentinel')
            ->assertDontSee('fake-model-ui-sentinel')
            ->assertDontSee($privateKey)
            ->assertDontSee($encodedKey);
    }

    /** @param array<string, string> $values
     * @return array<string, mixed>
     */
    private function noonReference(array $values): array
    {
        $names = ['NOON_API_ENABLED', 'NOON_API_KEY_ID', 'NOON_API_PROJECT_CODE', 'NOON_API_PRIVATE_KEY_BASE64', 'NOON_API_BUSINESS_MODEL'];
        $previous = [];
        foreach ($names as $name) {
            $previous[$name] = [
                'env_present' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_present' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
            $_ENV[$name] = $_SERVER[$name] = $values[$name] ?? '';
        }

        try {
            return (require base_path('config/marketplace_credentials.php'))['references']['noon_default'];
        } finally {
            foreach ($names as $name) {
                if ($previous[$name]['env_present']) {
                    $_ENV[$name] = $previous[$name]['env'];
                } else {
                    unset($_ENV[$name]);
                }
                if ($previous[$name]['server_present']) {
                    $_SERVER[$name] = $previous[$name]['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }
}
