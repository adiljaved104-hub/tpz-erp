<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Services\Preferences\UserUiPreferenceService;
use Illuminate\Validation\ValidationException;

class StockRequestColumnRegistry
{
    /** @return array<string, string> */
    public function columns(): array
    {
        return ['warehouse' => 'Warehouse', 'mine' => 'My Stock', 'reserved' => 'Reserved', 'others' => 'Available From Others', 'holder' => 'Stock Holders', 'request_status' => 'Request Status'];
    }

    /** @return array<int, string> */
    public function defaults(): array
    {
        return array_keys($this->columns());
    }

    /** @return array<int, string> */
    public function resolve(User $user): array
    {
        return array_values(array_intersect(app(UserUiPreferenceService::class)->get($user, UserUiPreferenceService::STOCK_REQUESTS_COLUMNS, $this->defaults()), $this->defaults()));
    }

    /** @param array<int, string> $columns */
    public function save(User $user, array $columns): void
    {
        if (array_diff($columns, $this->defaults()) !== [] || count($columns) !== count(array_unique($columns))) {
            throw ValidationException::withMessages(['columns' => 'The stock request column selection is invalid.']);
        }
        app(UserUiPreferenceService::class)->put($user, UserUiPreferenceService::STOCK_REQUESTS_COLUMNS, array_values(array_intersect($this->defaults(), $columns)));
    }

    public function reset(User $user): void
    {
        app(UserUiPreferenceService::class)->forget($user, UserUiPreferenceService::STOCK_REQUESTS_COLUMNS);
    }
}
