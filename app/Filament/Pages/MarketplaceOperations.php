<?php

namespace App\Filament\Pages;

use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplacePlatform;
use App\Models\User;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use App\Services\Marketplace\MarketplaceCredentialReferenceService;
use App\Services\Marketplace\MarketplaceIntegrationService;
use App\Services\Marketplace\MarketplaceMonitoringSettingsService;
use App\Services\Marketplace\MarketplaceOperationsSummaryService;
use App\Services\Marketplace\NoonConnectionTester;
use App\Services\Marketplace\NoonCredentialManagementService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use UnitEnum;

class MarketplaceOperations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'Marketplace';

    protected static ?string $navigationLabel = 'Marketplace Operations';

    protected static ?string $title = 'Marketplace Operations';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.marketplace-operations';

    public array $settings = [];

    public string $summaryTimes = '09:00, 14:00, 19:00';

    public array $accountForm = ['marketplace_platform_id' => null, 'name' => '', 'code' => '', 'product_condition' => null, 'enabled' => true];

    public array $connectionForm = ['marketplace_account_id' => null, 'name' => '', 'connection_type' => 'api', 'driver_option' => 'amazon_sp_api', 'driver' => '', 'priority_position' => 'primary', 'priority' => 10, 'enabled' => false, 'credential_reference' => '', 'capabilities' => []];

    public array $connectionSettings = [];

    public array $noonCredentialForm = [];

    public array $noonConnectionTestStatuses = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(MarketplaceOperationsAuthorization::class)->allows($user, MarketplaceOperationsPermission::View);
    }

    public function mount(MarketplaceMonitoringSettingsService $settings): void
    {
        abort_unless(static::canAccess(), 403);
        $this->settings = $settings->effective();
        $this->summaryTimes = implode(', ', $this->settings['summary_times']);
        $this->loadConnectionSettings();
    }

    public function saveSettings(MarketplaceMonitoringSettingsService $service): void
    {
        $this->settings['summary_times'] = preg_split('/\s*,\s*/', trim($this->summaryTimes)) ?: [];
        $service->save($this->settings, $this->actor());
        $this->settings = $service->effective();
        $this->summaryTimes = implode(', ', $this->settings['summary_times']);
        Notification::make()->success()->title('Marketplace monitoring settings saved')->send();
    }

    public function createAccount(MarketplaceIntegrationService $service): void
    {
        $service->saveAccount($this->accountForm, $this->actor());
        $this->accountForm = ['marketplace_platform_id' => null, 'name' => '', 'code' => '', 'product_condition' => null, 'enabled' => true];
        Notification::make()->success()->title('Marketplace account created')->send();
    }

    public function createConnection(MarketplaceIntegrationService $service): void
    {
        $data = $this->connectionForm;
        $data['driver'] = $data['driver_option'] === 'custom' ? trim((string) $data['driver']) : $data['driver_option'];
        if (in_array($data['driver'], ['sharafdg_browser', 'microless_browser'], true)) {
            $data['connection_type'] = 'browser';
        }
        $data['priority'] = $this->priorityFromPosition((string) $data['priority_position'], (int) $data['priority']);
        unset($data['driver_option'], $data['priority_position']);
        $service->saveConnection($data, $this->actor());
        $this->connectionForm = ['marketplace_account_id' => null, 'name' => '', 'connection_type' => 'api', 'driver_option' => 'amazon_sp_api', 'driver' => '', 'priority_position' => 'primary', 'priority' => 10, 'enabled' => false, 'credential_reference' => '', 'capabilities' => []];
        $this->loadConnectionSettings();
        Notification::make()->success()->title('Marketplace connection created')->send();
    }

    public function saveConnectionConfiguration(int $connectionId, MarketplaceIntegrationService $service): void
    {
        $connection = MarketplaceConnection::query()->with('capabilities')->findOrFail($connectionId);
        $edit = $this->connectionSettings[$connectionId] ?? [];
        $service->saveConnection([
            'marketplace_account_id' => $connection->marketplace_account_id,
            'name' => $connection->name,
            'connection_type' => $connection->connection_type->value,
            'driver' => $connection->driver,
            'priority' => $this->priorityFromPosition((string) ($edit['priority_position'] ?? 'custom'), (int) ($edit['priority'] ?? $connection->priority)),
            'enabled' => $connection->enabled,
            'credential_reference' => null,
            'capabilities' => $edit['capabilities'] ?? [],
        ], $this->actor(), $connection);
        $this->loadConnectionSettings();
        Notification::make()->success()->title('Connection priority and capabilities saved')->send();
    }

    public function saveNoonCredentials(int $connectionId, NoonCredentialManagementService $service): void
    {
        $actor = $this->actor();
        app(MarketplaceOperationsAuthorization::class)->authorize($actor, MarketplaceOperationsPermission::Manage);
        $connection = MarketplaceConnection::query()->findOrFail($connectionId);

        try {
            $service->save($connection, $this->noonCredentialForm[$connectionId] ?? [], $actor);
            unset($this->noonConnectionTestStatuses[$connectionId]);
            Notification::make()->success()->title('Noon credentials saved')->send();
        } finally {
            $this->noonCredentialForm = [];
        }
    }

    public function testNoonConnection(int $connectionId, NoonConnectionTester $tester): void
    {
        $actor = $this->actor();
        app(MarketplaceOperationsAuthorization::class)->authorize($actor, MarketplaceOperationsPermission::Manage);
        $connection = MarketplaceConnection::query()->findOrFail($connectionId);
        $this->noonConnectionTestStatuses[$connectionId] = $tester->test($connection, $actor);
    }

    public function toggleAccount(int $accountId, MarketplaceIntegrationService $service): void
    {
        $service->toggleAccount(MarketplaceAccount::query()->findOrFail($accountId), $this->actor());
        Notification::make()->success()->title('Marketplace account status updated')->send();
    }

    public function toggleConnection(int $connectionId, MarketplaceIntegrationService $service): void
    {
        $service->toggleConnection(MarketplaceConnection::query()->findOrFail($connectionId), $this->actor());
        Notification::make()->success()->title('Marketplace connection status updated')->send();
    }

    public function getViewData(): array
    {
        return app(MarketplaceOperationsSummaryService::class)->summary() + [
            'canManage' => app(MarketplaceOperationsAuthorization::class)->allows($this->actor(), MarketplaceOperationsPermission::Manage),
            'platforms' => MarketplacePlatform::query()->active()->orderBy('name')->get(),
            'accounts' => MarketplaceAccount::query()->with(['platform', 'connections.capabilities'])->orderBy('marketplace_platform_id')->orderBy('name')->get(),
        ];
    }

    public function credentialsConfigured(MarketplaceConnection $connection): bool
    {
        if (in_array($connection->driver, ['sharafdg_browser', 'microless_browser'], true)) {
            return (bool) config('marketplace_monitoring.browser_worker.enabled') && filled(config('marketplace_monitoring.browser_worker.url'));
        }
        $credentials = app(MarketplaceCredentialReferenceService::class)->credentials($connection);
        $required = match ($connection->driver) {
            'amazon_sp_api' => ['endpoint', 'marketplace_id', 'seller_id', 'lwa_client_id', 'lwa_client_secret', 'refresh_token'],
            'noon_api' => ['key_id', 'project_code', 'private_key'],
            'carrefour_maf_api' => [],
            default => null,
        };

        return $required === null
            ? filled($connection->getRawOriginal('credential_reference'))
            : (bool) ($credentials['enabled'] ?? false) && collect($required)->every(fn (string $key): bool => filled($credentials[$key] ?? null));
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function loadConnectionSettings(): void
    {
        $this->connectionSettings = MarketplaceConnection::query()->with('capabilities')->get()->mapWithKeys(fn (MarketplaceConnection $connection): array => [
            $connection->id => ['priority_position' => $this->positionFromPriority($connection->priority), 'priority' => $connection->priority, 'capabilities' => $connection->capabilities->pluck('capability.value')->all()],
        ])->all();
    }

    private function priorityFromPosition(string $position, int $custom): int
    {
        return match ($position) {
            'primary' => 10,
            'fallback_1' => 20,
            'fallback_2' => 30,
            default => max(1, min(65535, $custom)),
        };
    }

    private function positionFromPriority(int $priority): string
    {
        return match ($priority) {
            10 => 'primary',
            20 => 'fallback_1',
            30 => 'fallback_2',
            default => 'custom',
        };
    }
}
