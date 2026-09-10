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
        if (! in_array($data->scopeType, ['brand', 'product'], true)) {
            throw ValidationException::withMessages(['scope_type' => 'Bulk creation supports Brand or Product scopes only.']);
        }
        $ids = array_values(array_unique(array_map('intval', $data->scopeIds)));
        if ($ids === [] || count($ids) > 100 || in_array(0, $ids, true)) {
            throw ValidationException::withMessages(['scope_ids' => 'Select between 1 and 100 active records.']);
        }
        if ($data->platformId !== null && ! MarketplacePlatform::query()->active()->whereKey($data->platformId)->exists()) {
            throw ValidationException::withMessages(['platform_id' => 'Select an active Platform.']);
        }
        if ($data->categoryId !== null && ! ProductCategory::query()->active()->whereKey($data->categoryId)->exists()) {
            throw ValidationException::withMessages(['category_id' => 'Select an active Category.']);
        }
        if ($data->categoryId !== null && $data->scopeType !== 'brand') {
            throw ValidationException::withMessages(['scope_type' => 'Category intersections support Brand scopes only.']);
        }

        $records = $data->scopeType === 'brand'
            ? ProductBrand::query()->active()->whereKey($ids)->get(['id', 'name'])->keyBy('id')
            : Product::query()->products()->where('status', ProductStatus::Active->value)->whereKey($ids)->get(['id', 'sku', 'name'])->keyBy('id');
        if ($records->count() !== count($ids)) {
            throw ValidationException::withMessages(['scope_ids' => 'Every selected Brand or Product must be active.']);
        }

        $exact = collect($ids)->map(function (int $id) use ($data): CreateResponsibilityAssignmentData {
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, "responsibility-batch:{$data->idempotencyKey}:{$data->scopeType}:{$id}")->toString();

            return new CreateResponsibilityAssignmentData(
                employeeId: $data->employeeId,
                mode: ResponsibilityAssignmentMode::Scope,
                brandId: $data->scopeType === 'brand' ? $id : null,
                platformId: $data->platformId,
                productId: $data->scopeType === 'product' ? $id : null,
                productInventoryId: null,
                assignedQuantity: null,
                effectiveAt: $data->effectiveAt,
                reason: $data->reason,
                notes: $data->notes,
                idempotencyKey: $key,
                categoryId: $data->categoryId,
            );
        });

        $existingByKey = ResponsibilityAssignment::query()->whereIn('idempotency_key', $exact->pluck('idempotencyKey'))->get();
        if ($existingByKey->count() === $exact->count()) {
            return $existingByKey->load(['brandScope.brand', 'categoryScope.category', 'productScope.product', 'platformScope.platform']);
        }
        if ($existingByKey->isNotEmpty()) {
            throw ValidationException::withMessages(['scope_ids' => 'This batch was only partially recorded and requires review before retrying.']);
        }

        $fingerprints = $exact->map(fn (CreateResponsibilityAssignmentData $item): string => $this->fingerprints->make(
            $item->employeeId, $item->mode, $item->brandId, $item->platformId, $item->productId, null, $item->categoryId,
        ));
        $duplicates = ResponsibilityAssignment::query()->whereIn('active_fingerprint', $fingerprints)->get(['active_fingerprint']);
        if ($duplicates->isNotEmpty()) {
            $labels = $exact->filter(fn ($item, $index) => $duplicates->contains('active_fingerprint', $fingerprints[$index]))
                ->map(fn ($item) => $data->scopeType === 'brand' ? $records[$item->brandId]->name : $records[$item->productId]->sku)
                ->implode(', ');
            throw ValidationException::withMessages(['scope_ids' => "Active assignments already exist for: {$labels}. No assignments were created."]);
        }

        return DB::transaction(fn (): Collection => $exact->map(
            fn (CreateResponsibilityAssignmentData $item): ResponsibilityAssignment => $this->assignments->create($item, $actor),
        ));
    }
}
