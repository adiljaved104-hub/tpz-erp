<?php

namespace App\Services\ProductIntelligence;

use App\Contracts\ProductQueryInterpreterInterface;
use App\DTOs\ProductIntelligence\ParsedProductQuery;
use App\DTOs\ProductIntelligence\ProductMatchReason;
use App\DTOs\ProductIntelligence\ProductMatchRequest;
use App\DTOs\ProductIntelligence\ProductMatchResult;
use App\Enums\OrderPermission;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Enums\QuotationPermission;
use App\Enums\UpgradeRecipeOperation;
use App\Enums\WebSalesPermission;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductInventory;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Upgrades\UpgradeRecipeValidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductMatchService
{
    /** @var array{query_count: int, sql_ms: float, total_ms: float, candidate_count: int, top_result_count: int} */
    private array $lastMetrics = ['query_count' => 0, 'sql_ms' => 0, 'total_ms' => 0, 'candidate_count' => 0, 'top_result_count' => 0];

    private int $metricQueries = 0;

    public function __construct(
        private readonly ProductQueryInterpreterInterface $interpreter,
        private readonly ProductMatchScorer $scorer,
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly OrderAuthorization $orders,
        private readonly WebSalesAuthorization $webSales,
        private readonly PurchaseAuthorization $purchases,
        private readonly QuotationAuthorization $quotations,
        private readonly ProductAuthorization $products,
        private readonly UpgradeRecipeValidationService $recipeValidator,
    ) {}

    /** @return Collection<int, ProductMatchResult> */
    public function match(ProductMatchRequest $request): Collection
    {
        $started = hrtime(true);
        $this->metricQueries = 0;
        $query = trim($request->query);
        if (mb_strlen($query) < (int) config('product_matching.minimum_query_length', 2) || ! $this->authorized($request)) {
            return collect();
        }

        $knownBrands = ProductBrand::query()->where('status', true)->orderBy('name')->pluck('name')->all();
        $this->metricQueries++;
        $parsed = $this->interpreter->interpret($query, $request->attributes, $knownBrands);
        $sqlStarted = hrtime(true);
        $candidates = $this->candidates($request, $parsed);
        $sqlMs = $this->millisecondsSince($sqlStarted);
        $configurations = $this->configurations($request, $parsed, $candidates);
        $stock = $this->stock($request, $candidates, $configurations);

        $results = $candidates->map(function (Product $product) use ($request, $parsed, $configurations, $stock): ProductMatchResult {
            $configuration = $this->matchingConfiguration($parsed, $product, $configurations->get($product->id, collect()));
            $candidateAttributes = $this->candidateAttributes($product, $configuration);
            $componentSpecification = $request->context->allowsComponents()
                ? $product->component?->specification
                : null;
            $candidateText = trim($product->sku.' '.$product->name.' '.($componentSpecification ?? ''));
            $candidate = $this->interpreter->interpret($candidateText, $candidateAttributes, [$product->displayBrandName()]);
            $scored = $this->scorer->score($parsed, $candidate, $request->context);
            $recipe = $configuration?->recipes->first(fn (UpgradeRecipe $recipe): bool => $recipe->active && $this->recipeValidator->validate($recipe)->valid);
            $buildable = $configuration && $recipe && $request->warehouseId
                ? $this->buildableQuantity($product, $recipe, $stock)
                : null;
            $reasons = $scored['reasons'];
            if ($configuration !== null) {
                $reasons[] = new ProductMatchReason('configuration', 'match', "Existing configuration: {$configuration->display_name}.");
                if ($buildable !== null) {
                    $reasons[] = new ProductMatchReason('availability', $buildable > 0 ? 'match' : 'unknown', $buildable > 0 ? "Can build {$buildable} from current stock." : 'Configuration exists, but required stock is unavailable.');
                }
            }

            $classification = $buildable !== null && $buildable > 0
                ? ProductMatchClassification::BuildableConfiguration
                : $scored['classification'];

            return new ProductMatchResult(
                productId: $product->id,
                sku: $product->sku,
                name: $product->name,
                inventoryItemType: $product->inventory_item_type->value,
                score: $scored['score'],
                classification: $classification,
                reasons: $reasons,
                sellableQuantity: $request->warehouseId ? (int) ($stock[$product->id] ?? 0) : null,
                salesConfigurationId: $configuration?->id,
                salesConfigurationName: $configuration?->display_name,
                upgradeRecipeId: $recipe?->id,
                buildSummary: $recipe ? $this->recipeValidator->validate($recipe)->buildSummary : null,
                buildableQuantity: $buildable,
            );
        })->filter(fn (ProductMatchResult $result): bool => $result->score >= 15 || $result->classification === ProductMatchClassification::BuildableConfiguration)
            ->sortBy([['score', 'desc'], ['productId', 'asc']])
            ->take(max(1, min($request->limit, 30)))->values();

        $this->lastMetrics = [
            'query_count' => $this->metricQueries,
            'sql_ms' => round($sqlMs, 2),
            'total_ms' => round($this->millisecondsSince($started), 2),
            'candidate_count' => $candidates->count(),
            'top_result_count' => $results->count(),
        ];

        return $results;
    }

    /** @return array{query_count: int, sql_ms: float, total_ms: float, candidate_count: int, top_result_count: int} */
    public function lastMetrics(): array
    {
        return $this->lastMetrics;
    }

    /** @return Collection<int, Product> */
    private function candidates(ProductMatchRequest $request, ParsedProductQuery $parsed): Collection
    {
        $candidateLimit = (int) config('product_matching.candidate_limit', 150);
        $query = Product::query()->active()->select([
            'id', 'sku', 'name', 'inventory_item_type', 'brand', 'brand_id', 'model', 'processor', 'ram', 'storage', 'screen_size', 'graphics', 'status',
        ])->with('brandRelation:id,name,status');

        if ($request->context->allowsComponents()) {
            $query->with('component:id,product_id,component_type,specification,capacity_value,capacity_unit,interface_type,attributes');
        }

        if ($request->excludeProductId !== null) {
            $query->whereKeyNot($request->excludeProductId);
        }

        if (! $request->context->allowsComponents()) {
            $query->products();
        }
        if (in_array($request->context, [ProductMatchContext::Order, ProductMatchContext::WebSales, ProductMatchContext::Quotation, ProductMatchContext::Purchase], true)) {
            if ($this->responsibilities->requiresScope($request->user) && ! $request->warehouseId) {
                return collect();
            }
            if ($request->warehouseId) {
                $query = $this->responsibilities->applyProducts($query, $request->user, $request->platformId, $request->warehouseId);
            }
        }

        $tokens = collect($parsed->tokens)->reject(fn (string $token): bool => mb_strlen($token) < 2 || in_array($token, ['gb', 'tb', 'mb', 'core', 'ultra', 'intel', 'nvidia', 'geforce'], true))->take(8)->values();
        $narrowed = clone $query;
        if ($tokens->isNotEmpty()) {
            $narrowed->where(function (Builder $where) use ($request, $tokens): void {
                foreach ($tokens as $token) {
                    $where->orWhere('sku', 'like', "%{$token}%")
                        ->orWhere('name', 'like', "%{$token}%")
                        ->orWhere('brand', 'like', "%{$token}%")
                        ->orWhere('model', 'like', "%{$token}%")
                        ->orWhere('processor', 'like', "%{$token}%")
                        ->orWhere('ram', 'like', "%{$token}%")
                        ->orWhere('storage', 'like', "%{$token}%")
                        ->orWhere('graphics', 'like', "%{$token}%");

                    if ($request->context->allowsComponents()) {
                        $where->orWhereHas('component', function (Builder $component) use ($token): void {
                            $component->where('specification', 'like', "%{$token}%")
                                ->orWhere('interface_type', 'like', "%{$token}%");
                        });
                    }
                }
            });
        }

        $candidates = $narrowed->orderBy('id')->limit($candidateLimit)->get();
        $this->metricQueries++;
        if ($candidates->count() < min(10, $candidateLimit)) {
            $fallback = $query->orderBy('id')->limit($candidateLimit)->get();
            $this->metricQueries++;
            $candidates = $candidates->concat($fallback)->unique('id')->take($candidateLimit)->values();
        }

        if ($request->context->requiresSellableStock() && $request->warehouseId) {
            $sellableIds = ProductInventory::query()->where('warehouse_id', $request->warehouseId)
                ->whereIn('product_id', $candidates->pluck('id'))
                ->whereRaw('(available_quantity - reserved_quantity) > 0')
                ->pluck('product_id');
            $this->metricQueries++;
            $candidates = $candidates->whereIn('id', $sellableIds)->values();
        }

        return $candidates;
    }

    /** @return Collection<int, Collection<int, SalesConfiguration>> */
    private function configurations(ProductMatchRequest $request, ParsedProductQuery $parsed, Collection $products): Collection
    {
        if ($request->context->allowsComponents() || ($parsed->ramMb === null && $parsed->storageGb === null) || $products->isEmpty()) {
            return collect();
        }

        $configurations = SalesConfiguration::query()->current()->where('active', true)
            ->select(['id', 'product_id', 'hardware_profile_version', 'display_name', 'target_ram_mb', 'target_storage_total_gb', 'target_storage_layout', 'active'])
            ->whereIn('product_id', $products->pluck('id'))
            ->with([
                'product' => fn ($query) => $query->select(['id', 'name', 'sku', 'inventory_item_type', 'status']),
                'product.hardwareProfile' => fn ($query) => $query->select(['id', 'product_id', 'profile_version', 'ram_upgradeable', 'max_supported_ram_mb', 'storage_upgradeable']),
                'product.hardwareProfile.slots' => fn ($query) => $query->select(['id', 'product_hardware_profile_id', 'subsystem', 'slot_key', 'interface_type', 'is_soldered', 'is_occupied', 'base_component_id', 'base_capacity_value', 'base_capacity_unit', 'position']),
                'product.hardwareProfile.slots.baseComponent' => fn ($query) => $query->select(['id', 'product_id', 'component_type', 'specification', 'capacity_value', 'capacity_unit', 'interface_type', 'attributes']),
                'product.hardwareProfile.slots.baseComponent.product' => fn ($query) => $query->select(['id', 'name', 'sku', 'inventory_item_type', 'status']),
                'recipes' => fn ($query) => $query->select(['id', 'sales_configuration_id', 'hardware_profile_version', 'name', 'preferred', 'priority', 'active'])->where('active', true)->orderByDesc('preferred')->orderBy('priority'),
                'recipes.lines' => fn ($query) => $query->select(['id', 'upgrade_recipe_id', 'sequence', 'operation', 'source_slot_key', 'target_slot_key', 'install_component_id', 'recovered_component_id', 'quantity_per_laptop']),
                'recipes.lines.installComponent' => fn ($query) => $query->select(['id', 'product_id', 'component_type', 'specification', 'capacity_value', 'capacity_unit', 'interface_type', 'attributes']),
                'recipes.lines.installComponent.product' => fn ($query) => $query->select(['id', 'name', 'sku', 'inventory_item_type', 'status']),
                'recipes.lines.recoveredComponent' => fn ($query) => $query->select(['id', 'product_id', 'component_type', 'specification', 'capacity_value', 'capacity_unit', 'interface_type', 'attributes']),
                'recipes.lines.recoveredComponent.product' => fn ($query) => $query->select(['id', 'name', 'sku', 'inventory_item_type', 'status']),
            ])
            ->get();
        $this->metricQueries += 12;

        $configurations->each(function (SalesConfiguration $configuration): void {
            $configuration->recipes->each(fn (UpgradeRecipe $recipe) => $recipe->setRelation('salesConfiguration', $configuration));
        });

        return $configurations->groupBy('product_id');
    }

    private function matchingConfiguration(ParsedProductQuery $parsed, Product $product, Collection $configurations): ?SalesConfiguration
    {
        $base = $this->interpreter->interpret($product->name, $this->candidateAttributes($product), [$product->displayBrandName()]);
        $baseMatches = ($parsed->ramMb === null || $parsed->ramMb === $base->ramMb)
            && ($parsed->storageGb === null || $parsed->storageGb === $base->storageGb);
        if ($baseMatches) {
            return null;
        }

        return $configurations->first(function (SalesConfiguration $configuration) use ($parsed, $product): bool {
            $configured = $this->interpreter->interpret(
                $product->name,
                $this->candidateAttributes($product, $configuration),
                [$product->displayBrandName()],
            );

            return ($parsed->ramMb === null || $configured->ramMb === $parsed->ramMb)
                && ($parsed->storageGb === null || $configured->storageGb === $parsed->storageGb);
        });
    }

    /** @return array<string, mixed> */
    private function candidateAttributes(Product $product, ?SalesConfiguration $configuration = null): array
    {
        return [
            'brand' => $product->displayBrandName(),
            'model' => $product->model,
            'processor' => $product->processor,
            'ram' => $configuration?->target_ram_mb ? ($configuration->target_ram_mb / 1024).'GB RAM' : $product->ram,
            'storage' => $configuration?->target_storage_total_gb ? $configuration->target_storage_total_gb.'GB Storage' : $product->storage,
            'graphics' => $product->graphics,
            'screen_size' => $product->screen_size,
        ];
    }

    /** @return array<int, int> */
    private function stock(ProductMatchRequest $request, Collection $products, Collection $configurations): array
    {
        if (! $request->warehouseId) {
            return [];
        }

        $componentProductIds = $configurations->flatten()->flatMap(fn (SalesConfiguration $configuration) => $configuration->recipes)
            ->flatMap(fn (UpgradeRecipe $recipe) => $recipe->lines)
            ->filter(fn ($line): bool => $line->operation === UpgradeRecipeOperation::Install && $line->installComponent !== null)
            ->pluck('installComponent.product_id');

        $stock = ProductInventory::query()->where('warehouse_id', $request->warehouseId)
            ->whereIn('product_id', $products->pluck('id')->merge($componentProductIds)->unique())
            ->get(['product_id', 'available_quantity', 'reserved_quantity'])
            ->mapWithKeys(fn (ProductInventory $inventory): array => [$inventory->product_id => max(0, $inventory->sellableQuantity())])->all();
        $this->metricQueries++;

        return $stock;
    }

    /** @param array<int, int> $stock */
    private function buildableQuantity(Product $product, UpgradeRecipe $recipe, array $stock): int
    {
        $buildable = max(0, (int) ($stock[$product->id] ?? 0));
        $requirements = $recipe->lines
            ->filter(fn ($line): bool => $line->operation === UpgradeRecipeOperation::Install && $line->installComponent !== null)
            ->groupBy(fn ($line): int => $line->installComponent->product_id)
            ->map(fn (Collection $lines): float => $lines->sum(fn ($line): float => (float) $line->quantity_per_laptop));

        foreach ($requirements as $componentProductId => $perUnit) {
            if ($perUnit <= 0) {
                return 0;
            }
            $buildable = min($buildable, (int) floor(((int) ($stock[$componentProductId] ?? 0)) / $perUnit));
        }

        return max(0, $buildable);
    }

    private function authorized(ProductMatchRequest $request): bool
    {
        if ($request->user->employee?->status !== true) {
            return false;
        }

        return match ($request->context) {
            ProductMatchContext::Order => $this->orders->allows($request->user, OrderPermission::Create) || $this->orders->allows($request->user, OrderPermission::UpdateDraft),
            ProductMatchContext::WebSales => $this->webSales->allows($request->user, WebSalesPermission::Create) || $this->webSales->allows($request->user, WebSalesPermission::Update),
            ProductMatchContext::Purchase => $this->purchases->allows($request->user, PurchasePermission::Create) || $this->purchases->allows($request->user, PurchasePermission::View),
            ProductMatchContext::Receiving => $this->purchases->allows($request->user, PurchasePermission::Receive) || $this->purchases->allows($request->user, PurchasePermission::QuickReceive),
            ProductMatchContext::Quotation => $this->quotations->allows($request->user, QuotationPermission::Create) || $this->quotations->allows($request->user, QuotationPermission::Update),
            ProductMatchContext::ProductCreation => $this->products->allows($request->user, ProductPermission::Create) && $this->products->allows($request->user, ProductPermission::View),
            ProductMatchContext::ProductUpdate => $this->products->allows($request->user, ProductPermission::Update) && $this->products->allows($request->user, ProductPermission::View),
        };
    }

    private function millisecondsSince(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
