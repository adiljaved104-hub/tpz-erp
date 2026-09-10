<?php

namespace App\Services\Responsibilities;

use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityPermission;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class BulkResponsibilityAssignmentService
{
    public function __construct(
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ResponsibilityAssignmentService $assignments,
        private readonly ResponsibilityScopeFingerprint $fingerprints,
    ) {}

    /** @return Collection<int, ResponsibilityAssignment> */
    public function create(CreateResponsibilityAssignmentBatchData $data, User $actor): Collection
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Assign);
        if (! in_array($data->scopeType, ['brand', 'category', 'platform', 'product'], true)) {
            throw ValidationException::withMessages(['scope_type' => 'Bulk creation supports Platform, Brand, Category, or Product scopes only.']);
        }

        $ids = array_values(array_unique(array_map('intval', $data->scopeIds)));
        if (in_array($data->scopeType, ['brand', 'product'], true) && ($ids === [] || count($ids) > 100 || in_array(0, $ids, true))) {
            throw ValidationException::withMessages(['scope_ids' => 'Select between 1 and 100 active records.']);
        }

        $platformIds = array_values(array_unique(array_map('intval', $data->platformIds)));
        if ($platformIds === [] && $data->platformId !== null) {
            $platformIds = [$data->platformId];
        }
        if (count($platformIds) > 100 || in_array(0, $platformIds, true)) {
            throw ValidationException::withMessages(['platform_ids' => 'Select between 1 and 100 active Platforms.']);
        }
        if ($data->scopeType === 'platform' && $platformIds === []) {
            throw ValidationException::withMessages(['platform_ids' => 'Select at least one active Platform.']);
        }
        if ($platformIds !== [] && MarketplacePlatform::query()->active()->whereKey($platformIds)->count() !== count($platformIds)) {
            throw ValidationException::withMessages(['platform_ids' => 'Every selected Platform must be active.']);
        }
        if ($data->categoryId !== null && ! ProductCategory::query()->active()->whereKey($data->categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Select an active Category.']);
        }
        if ($data->categoryId !== null && ! in_array($data->scopeType, ['brand', 'category'], true)) {
            throw ValidationException::withMessages(['scope_type' => 'Category intersections support Brand scopes only.']);
        }
        if ($data->scopeType === 'category' && $data->categoryId === null) {
            throw ValidationException::withMessages(['category_id' => 'Select an active Category.']);
        }

        $combinationCount = max(1, count($ids)) * max(1, count($platformIds));
        if ($combinationCount > 100) {
            throw ValidationException::withMessages(['scope_ids' => 'A bulk Responsibility submission may create at most 100 exact assignments.']);
        }

        $records = match ($data->scopeType) {
            'brand' => ProductBrand::query()->active()->whereKey($ids)->get(['id', 'name'])->keyBy('id'),
            'product' => Product::query()->products()->where('status', ProductStatus::Active->value)->whereKey($ids)->get(['id', 'sku', 'name'])->keyBy('id'),
            default => collect(),
        };
        if (in_array($data->scopeType, ['brand', 'product'], true) && $records->count() !== count($ids)) {
            throw ValidationException::withMessages(['scope_ids' => 'Every selected Brand or Product must be active.']);
        }

        $scopeIds = in_array($data->scopeType, ['brand', 'product'], true) ? $ids : [null];
        $platforms = $platformIds === [] ? [null] : $platformIds;
        $singlePlatform = count($platforms) === 1;
        $exact = collect($scopeIds)->crossJoin($platforms)->map(function (array $combination) use ($data, $singlePlatform): CreateResponsibilityAssignmentData {
            [$id, $platformId] = $combination;
            $keyScope = $id ?? $data->scopeType;
            if ($singlePlatform && in_array($data->scopeType, ['category', 'platform'], true)) {
                $idempotencyKey = $data->idempotencyKey;
            } else {
                $keySeed = "responsibility-batch:{$data->idempotencyKey}:{$data->scopeType}:{$keyScope}";
                if (! $singlePlatform) {
                    $keySeed .= ':platform:'.($platformId ?? 'none');
                }
                $idempotencyKey = Uuid::uuid5(Uuid::NAMESPACE_URL, $keySeed)->toString();
            }

            return new CreateResponsibilityAssignmentData(
                employeeId: $data->employeeId,
                mode: ResponsibilityAssignmentMode::Scope,
                brandId: $data->scopeType === 'brand' ? $id : null,
                platformId: $platformId,
                productId: $data->scopeType === 'product' ? $id : null,
                productInventoryId: null,
                assignedQuantity: null,
                effectiveAt: $data->effectiveAt,
                reason: $data->reason,
                notes: $data->notes,
                idempotencyKey: $idempotencyKey,
                categoryId: $data->categoryId,
            );
        });

        $existingByKey = ResponsibilityAssignment::query()->whereIn('idempotency_key', $exact->pluck('idempotencyKey'))->get();
        if ($existingByKey->count() === $exact->count()) {
            return $existingByKey->load(['brandScope.brand', 'categoryScope.category', 'productScope.product', 'platformScope.platform']);
        }
        if ($existingByKey->isNotEmpty()) {
            throw ValidationException::withMessages(['scope_ids' => 'This multi-selection was only partially recorded and requires review before retrying.']);
        }

        $fingerprints = $exact->map(fn (CreateResponsibilityAssignmentData $item): string => $this->fingerprints->make(
            $item->employeeId, $item->mode, $item->brandId, $item->platformId, $item->productId, null, $item->categoryId,
        ));
        $duplicates = ResponsibilityAssignment::query()->whereIn('active_fingerprint', $fingerprints)->get(['active_fingerprint']);
        if ($duplicates->isNotEmpty()) {
            $platformNames = MarketplacePlatform::query()->whereKey($platformIds)->pluck('name', 'id');
            $labels = $exact->filter(fn ($item, $index) => $duplicates->contains('active_fingerprint', $fingerprints[$index]))
                ->map(function ($item) use ($data, $records, $platformNames): string {
                    $scope = match ($data->scopeType) {
                        'brand' => $records[$item->brandId]->name,
                        'product' => $records[$item->productId]->sku,
                        'category' => 'Category',
                        default => 'Platform',
                    };
                    $platform = $item->platformId === null ? null : $platformNames[$item->platformId];

                    return $platform === null ? $scope : "{$scope} + {$platform}";
                })
                ->implode(', ');
            throw ValidationException::withMessages(['scope_ids' => "Active assignments already exist for: {$labels}. No assignments were created."]);
        }

        return DB::transaction(fn (): Collection => $exact->map(
            fn (CreateResponsibilityAssignmentData $item): ResponsibilityAssignment => $this->assignments->create($item, $actor),
        ));
    }
}
