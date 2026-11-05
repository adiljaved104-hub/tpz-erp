<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Services\Preferences\UserUiPreferenceService;
use Illuminate\Validation\ValidationException;

class StockAdjustmentColumnRegistry
{
    public function __construct(private readonly UserUiPreferenceService $preferences) {}

    /** @return array<string, string> */
    public function columns(): array
    {
        return [
            'brand' => 'Brand',
            'warehouse' => 'Warehouse',
            'saleable' => 'Saleable',
            'damaged' => 'Damaged',
            'reserved' => 'Reserved',
            'holder' => 'Stock Holder',
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
        return array_values(array_intersect(
            $this->preferences->get($user, UserUiPreferenceService::STOCK_ADJUSTMENTS_COLUMNS, $this->defaults()),
            array_keys($this->columns()),
        ));
    }

    /** @param array<int, string> $columns */
    public function save(User $user, array $columns): void
    {
        if (array_diff($columns, array_keys($this->columns())) !== [] || count($columns) !== count(array_unique($columns))) {
            throw ValidationException::withMessages(['columns' => 'The stock adjustment column selection is invalid.']);
        }

        $this->preferences->put($user, UserUiPreferenceService::STOCK_ADJUSTMENTS_COLUMNS, array_values(array_intersect(array_keys($this->columns()), $columns)));
    }

    public function reset(User $user): void
    {
        $this->preferences->forget($user, UserUiPreferenceService::STOCK_ADJUSTMENTS_COLUMNS);
    }
}
