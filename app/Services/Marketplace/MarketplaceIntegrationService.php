<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceConnectionCapability;
use App\Enums\MarketplaceConnectionType;
use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceConnectionCapabilityRecord;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceIntegrationService
{
    public function __construct(private readonly MarketplaceOperationsAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /** @param array<string, mixed> $data */
    public function saveAccount(array $data, User $actor, ?MarketplaceAccount $account = null): MarketplaceAccount
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);
        $validated = validator($data, [
            'marketplace_platform_id' => ['required', 'integer', 'exists:marketplace_platforms,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9][a-z0-9_-]*\z/', Rule::unique('marketplace_accounts')->where('marketplace_platform_id', $data['marketplace_platform_id'] ?? null)->ignore($account?->id)],
            'product_condition' => ['nullable', Rule::in(['new', 'renewed', 'used', 'open_box', 'refurbished'])],
            'enabled' => ['boolean'],
        ])->validate();

        return DB::transaction(function () use ($validated, $actor, $account): MarketplaceAccount {
            $target = $account?->exists ? MarketplaceAccount::query()->lockForUpdate()->findOrFail($account->id) : new MarketplaceAccount;
            $before = $target->exists ? $target->toArray() : [];
            $target->fill($validated)->save();
            $this->activity->log('marketplace_account.saved', $actor, $target, ['before' => $before, 'after' => $target->toArray()]);

            return $target->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function saveConnection(array $data, User $actor, ?MarketplaceConnection $connection = null): MarketplaceConnection
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);
        $validated = validator($data, [
            'marketplace_account_id' => ['required', 'integer', 'exists:marketplace_accounts,id'],
            'name' => ['required', 'string', 'max:120'],
            'connection_type' => ['required', Rule::enum(MarketplaceConnectionType::class)],
            'driver' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9][a-z0-9_-]*\z/'],
            'priority' => ['required', 'integer', 'min:1', 'max:65535'],
            'enabled' => ['boolean'],
            'credential_reference' => ['nullable', 'string', 'max:191', 'regex:/\A[a-z0-9][a-z0-9._-]*\z/i'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => [Rule::enum(MarketplaceConnectionCapability::class)],
        ])->validate();
        if ($connection !== null && (int) $validated['marketplace_account_id'] !== (int) $connection->marketplace_account_id) {
            throw ValidationException::withMessages(['marketplace_account_id' => 'A connection cannot be moved to another marketplace account.']);
        }

        $capabilities = array_values(array_unique(array_map(fn ($capability): string => $capability instanceof MarketplaceConnectionCapability ? $capability->value : (string) $capability, $validated['capabilities'])));

        return DB::transaction(function () use ($validated, $capabilities, $actor, $connection): MarketplaceConnection {
            $target = $connection?->exists ? MarketplaceConnection::query()->lockForUpdate()->findOrFail($connection->id) : new MarketplaceConnection;
            $before = $target->exists ? $target->withoutRelations()->toArray() : [];
            $credentials = trim((string) ($validated['credential_reference'] ?? ''));
            unset($validated['capabilities'], $validated['credential_reference']);
            $target->fill($validated);
            if ($credentials !== '') {
                $target->credential_reference = $credentials;
            }
            $target->save();
            $target->capabilities()->delete();
            foreach ($capabilities as $capability) {
                MarketplaceConnectionCapabilityRecord::query()->create(['marketplace_connection_id' => $target->id, 'capability' => $capability, 'enabled' => true]);
            }
            $this->activity->log('marketplace_connection.saved', $actor, $target, ['before' => $before, 'after' => $target->withoutRelations()->makeHidden('credential_reference')->toArray(), 'capabilities' => $capabilities]);

            return $target->refresh()->load('capabilities');
        });
    }

    public function toggleAccount(MarketplaceAccount $account, User $actor): MarketplaceAccount
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);

        return DB::transaction(function () use ($account, $actor): MarketplaceAccount {
            $locked = MarketplaceAccount::query()->lockForUpdate()->findOrFail($account->id);
            $locked->forceFill(['enabled' => ! $locked->enabled])->save();
            $this->activity->log('marketplace_account.toggled', $actor, $locked, ['enabled' => $locked->enabled]);

            return $locked->refresh();
        });
    }

    public function toggleConnection(MarketplaceConnection $connection, User $actor): MarketplaceConnection
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);

        return DB::transaction(function () use ($connection, $actor): MarketplaceConnection {
            $locked = MarketplaceConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $locked->forceFill(['enabled' => ! $locked->enabled])->save();
            $this->activity->log('marketplace_connection.toggled', $actor, $locked, ['enabled' => $locked->enabled]);

            return $locked->refresh();
        });
    }
}
