<?php

namespace App\Services\DemoData;

use App\Models\Component;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\SalesConfiguration;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

final class DemoContext
{
    /** @var Collection<string, Team> */
    public Collection $teams;

    /** @var Collection<string, Employee> */
    public Collection $employees;

    /** @var Collection<int, Supplier> */
    public Collection $suppliers;

    /** @var Collection<int, MarketplacePlatform> */
    public Collection $platforms;

    /** @var Collection<int, Product> */
    public Collection $products;

    /** @var Collection<int, Component> */
    public Collection $components;

    /** @var Collection<string, SalesConfiguration> */
    public Collection $configurations;

    /** @var Collection<string, UpgradeRecipe> */
    public Collection $recipes;

    /** @var array<string, int> */
    public array $counts = [];

    public function __construct(
        public readonly User $owner,
        public readonly Warehouse $warehouse,
    ) {
        $this->teams = collect();
        $this->employees = collect();
        $this->suppliers = collect();
        $this->platforms = collect();
        $this->products = collect();
        $this->components = collect();
        $this->configurations = collect();
        $this->recipes = collect();
    }

    public function count(string $key, int $value): void
    {
        $this->counts[$key] = $value;
    }
}
