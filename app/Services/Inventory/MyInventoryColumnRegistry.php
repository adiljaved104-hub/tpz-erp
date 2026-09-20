<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Services\Preferences\UserUiPreferenceService;
use Illuminate\Validation\ValidationException;

class MyInventoryColumnRegistry
{
    public function __construct(private readonly UserUiPreferenceService $preferences) {}

    /** @return array<string, string> */
    public function columns(): array
    {
        return [
            'product' => 'Product',
            'brand' => 'Brand',
            'condition' => 'Condition',
            'platform' => 'Platform',
            'location' => 'Location',
            'physical_sellable' => 'Physical Sellable',
            'reserved' => 'Reserved',
            'allocation_remaining' => 'Allocation Remaining',
            'usable_now' => 'Usable Now',
            'status' => 'Status',
            'visible_because' => 'Visible Because',
        ];
    }

    /** @return array<int, string> */
    public function defaults(): array
    {
        return array_keys($this->columns());
    }

    /** @return array<int, string> */
    public function resolve(User $user): array
    {
        $saved = $this->preferences->get($user, UserUiPreferenceService::MY_INVENTORY_COLUMNS, $this->defaults());

        return array_values(array_intersect($saved, array_keys($this->columns())));
    }

    /** @param array<int, string> $columns */
    public function save(User $user, array $columns): void
    {
        if (array_diff($columns, array_keys($this->columns())) !== [] || count($columns) !== count(array_unique($columns))) {
            throw ValidationException::withMessages(['columns' => 'The My Inventory column selection is invalid.']);
        }

        $ordered = array_values(array_intersect(array_keys($this->columns()), $columns));
        $this->preferences->put($user, UserUiPreferenceService::MY_INVENTORY_COLUMNS, $ordered);
    }

    public function reset(User $user): void
    {
        $this->preferences->forget($user, UserUiPreferenceService::MY_INVENTORY_COLUMNS);
    }
}
