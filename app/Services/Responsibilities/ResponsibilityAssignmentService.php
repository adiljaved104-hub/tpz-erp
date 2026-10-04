<?php

namespace App\Services\Responsibilities;

use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Exceptions\InvalidResponsibilityScopeException;
use App\Models\Employee;
use App\Models\InventoryResponsibilityQuantity;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductInventory;
use App\Models\ResponsibilityAssignment;
use App\Models\ResponsibilityAssignmentBrand;
use App\Models\ResponsibilityAssignmentCategory;
use App\Models\ResponsibilityAssignmentCondition;
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\ResponsibilityAssignmentWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ActivityLogger;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResponsibilityAssignmentService
{
    public function __construct(
        private readonly ResponsibilityCapacityService $capacity,
        private readonly ResponsibilityScopeFingerprint $fingerprints,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly ResponsibilityAllocationService $allocations,
        private readonly ResponsibilityAuthorization $authorization,
        private readonly ResponsibilityScopeConflictEvaluator $scopeConflicts,
    ) {}

    public function setStockDefault(ResponsibilityAssignment $assignment, bool $enabled, string $reason, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Assign);
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason is required and may not exceed 2000 characters.']);
        }

        $assignment->load($this->relations());
        if ($assignment->assign_stock_by_default === $enabled) {
            return $assignment;
        }

        if ($assignment->assignment_mode !== ResponsibilityAssignmentMode::Scope) {
            throw ValidationException::withMessages(['enabled' => 'Default stock assignment is available only for operational scope assignments.']);
        }
        if ($enabled && $assignment->brandScope === null && $assignment->categoryScope === null && $assignment->productScope === null) {
            throw ValidationException::withMessages(['enabled' => 'Default stock assignment requires a Brand, Category, or Product scope.']);
        }

        $data = new ChangeResponsibilityScopeData(
            employeeId: $assignment->employee_id,
            brandId: $assignment->brandScope?->product_brand_id,
            productId: $assignment->productScope?->product_id,
            categoryId: $assignment->categoryScope?->product_category_id,
            condition: $assignment->conditionScope?->product_condition,
            warehouseId: $assignment->warehouseScope?->warehouse_id,
            platformId: $assignment->platformScope?->marketplace_platform_id,
            assignStockByDefault: $enabled,
            reason: $reason,
            idempotencyKey: (string) Str::uuid(),
        );

        $this->validateReason($reason);

        return $this->replaceScope($assignment, $data, $actor, 'responsibility.stock_default_changed', $assignment->assign_stock_by_default);
    }

    public function create(CreateResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $prepared = $this->prepareCreate($data);
        $reference = $this->references->nextResponsibilityAssignmentReference();

        return $this->createPrepared($data, $actor, $reference);
    }

    public function validateForCreate(CreateResponsibilityAssignmentData $data): void
    {
        if (! ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->exists()) {
            $this->prepareCreate($data);
        }
    }

    /** @internal Reserved references must originate from ReferenceSequenceService. */
    public function createWithReservedReference(CreateResponsibilityAssignmentData $data, User $actor, string $reference): ResponsibilityAssignment
    {
        if (preg_match('/^RA-\d{6,}$/', $reference) !== 1) {
            throw new \LogicException('A valid internally reserved Responsibility reference is required.');
        }

        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $this->prepareCreate($data);

        return $this->createPrepared($data, $actor, $reference);
    }

    /** @return array{scope: array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory, warehouse: ?Warehouse}, employee: Employee, fingerprint: string} */
    private function prepareCreate(CreateResponsibilityAssignmentData $data, array $exceptAssignmentIds = [], bool $checkOperational = true): array
    {
        $scope = $this->validatedScope($data);
        $employee = $this->activeEmployee($data->employeeId);
        $fingerprint = $this->fingerprints->make($employee->id, $data->mode, $scope['brand']?->id, $scope['platform']?->id, $scope['product']?->id, $scope['inventory']?->id, $scope['category']?->id, $scope['warehouse']?->id, $scope['condition']);
        $this->assertFingerprintAvailable($fingerprint, exceptAssignmentIds: $exceptAssignmentIds);
        $this->scopeConflicts->assertNoConflicts($data, $exceptAssignmentIds, checkOperational: $checkOperational);

        return compact('scope', 'employee', 'fingerprint');
    }

    private function createPrepared(CreateResponsibilityAssignmentData $data, User $actor, string $reference): ResponsibilityAssignment
    {
        return DB::transaction(function () use ($data, $actor, $reference): ResponsibilityAssignment {
            $this->lockResponsibilityScopeMutations();
            $prepared = $this->prepareCreate($data);
            $this->scopeConflicts->assertNoConflicts($data, lock: true);
            if ($prepared['scope']['inventory'] !== null) {
                $this->capacity->lockAndAssert($prepared['scope']['inventory']->id, $data->assignedQuantity ?? 0);
            }

            $this->assertFingerprintAvailable($prepared['fingerprint'], lock: true);
            $assignment = $this->createHeader(
                reference: $reference,
                employee: $prepared['employee'],
                mode: $data->mode,
                fingerprint: $prepared['fingerprint'],
                effectiveAt: $data->effectiveAt,
                actor: $actor,
                reason: $data->reason,
                notes: $data->notes,
                idempotencyKey: $data->idempotencyKey,
                assignStockByDefault: $data->assignStockByDefault,
            );
            $this->writeScopes($assignment, $prepared['scope'], $data->assignedQuantity);
            $this->activity->log('responsibility.created', $actor, $assignment, $this->safeProperties($assignment, $prepared['scope'], $data->assignedQuantity, $data->reason));

            return $assignment->load($this->relations());
        });
    }

    public function transfer(ResponsibilityAssignment $source, TransferResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $source);
        $this->validateReason($data->reason);
        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $source->load($this->relations());
        $employee = $this->activeEmployee($data->employeeId);

        if ($source->employee_id === $employee->id) {
            throw ValidationException::withMessages(['employee_id' => 'Select a different Employee for a transfer.']);
        }

        $reference = $this->references->nextResponsibilityAssignmentReference();

        return DB::transaction(function () use ($source, $data, $actor, $employee, $reference): ResponsibilityAssignment {
            $this->lockResponsibilityScopeMutations();
            $inventoryId = $source->quantityScope?->product_inventory_id;
            $quantity = $source->quantityScope?->assigned_quantity;

            if ($inventoryId !== null) {
                $this->capacity->lockAndAssert($inventoryId, $quantity, $source->id);
            }

            $source = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($source->id);
            $source->load($this->relations());
            $this->assertActive($source);
            if ($source->quantityScope !== null) {
                $this->allocations->assertMaySupersede($source);
            }
            $scope = $this->scopeFrom($source);
            $proposed = $this->createDataFromScope($scope, $employee->id, $source->assignment_mode, $source->quantityScope?->assigned_quantity, $source->assign_stock_by_default, $data->reason, $data->idempotencyKey);
            $prepared = $this->prepareCreate($proposed, [$source->id]);
            $this->scopeConflicts->assertNoConflicts($proposed, [$source->id], lock: true);
            $fingerprint = $prepared['fingerprint'];

            $this->end($source, ResponsibilityAssignmentStatus::Transferred, $actor);
            $this->assertFingerprintAvailable($fingerprint, lock: true);
            $successor = $this->createHeader($reference, $prepared['employee'], $source->assignment_mode, $fingerprint, now()->toDateTimeString(), $actor, $data->reason, $source->notes, $data->idempotencyKey, $source->id, $source->assign_stock_by_default);
            $this->writeScopes($successor, $scope, $quantity);
            $this->activity->log('responsibility.transferred', $actor, $successor, [
                ...$this->safeProperties($successor, $scope, $quantity, $data->reason),
                'predecessor_assignment_id' => $source->id,
                'from_employee_id' => $source->employee_id,
            ]);

            return $successor->load($this->relations());
        });
    }

    public function changeScope(ResponsibilityAssignment $source, ChangeResponsibilityScopeData $data, User $actor): ResponsibilityAssignment
    {
        $this->authorization->authorize($actor, ResponsibilityPermission::Reassign, $source);
        $this->validateReason($data->reason);

        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $source->load($this->relations());
        if ($source->assignment_mode !== ResponsibilityAssignmentMode::Scope) {
            throw ValidationException::withMessages(['assignment' => 'Use the existing quantity workflow for Product Inventory + Quantity assignments.']);
        }
        if ($this->sameScope($source, $data)) {
            throw ValidationException::withMessages(['scope' => 'Change at least one Responsibility value before continuing.']);
        }

        return $this->replaceScope($source, $data, $actor, 'responsibility.scope_changed');
    }

    public function previewScopeChange(ResponsibilityAssignment $source, ChangeResponsibilityScopeData $data): string
    {
        $source->load($this->relations());
        if ($source->assignment_mode !== ResponsibilityAssignmentMode::Scope) {
            return 'Quantity assignments use the dedicated quantity/reservation workflow.';
        }

        try {
            $candidate = $this->createDataFromChange($data);
            $this->prepareCreate($candidate, [$source->id]);

            return 'SAFE — No conflicting active Responsibility was found.';
        } catch (ValidationException|InvalidResponsibilityScopeException|DuplicateActiveResponsibilityException $exception) {
            return 'Cannot continue — '.collect($exception instanceof ValidationException ? $exception->errors() : ['scope' => [$exception->getMessage()]])->flatten()->join(' ');
        }
    }

    private function replaceScope(
        ResponsibilityAssignment $source,
        ChangeResponsibilityScopeData $data,
        User $actor,
        string $event,
        ?bool $previousDefaultStock = null,
    ): ResponsibilityAssignment {
        $candidate = $this->createDataFromChange($data, $source->notes);
        $checkOperational = $event !== 'responsibility.stock_default_changed';
        $this->prepareCreate($candidate, [$source->id], $checkOperational);
        $reference = $this->references->nextResponsibilityAssignmentReference();

        return DB::transaction(function () use ($source, $data, $candidate, $actor, $event, $reference, $previousDefaultStock, $checkOperational): ResponsibilityAssignment {
            $this->lockResponsibilityScopeMutations();
            $source = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($source->id);
            $source->load($this->relations());
            $this->assertActive($source);
            if ($source->assignment_mode !== ResponsibilityAssignmentMode::Scope) {
                throw ValidationException::withMessages(['assignment' => 'Only operational scope assignments can use this workflow.']);
            }
            if ($event === 'responsibility.stock_default_changed' && $source->assign_stock_by_default === $data->assignStockByDefault) {
                return $source;
            }

            $prepared = $this->prepareCreate($candidate, [$source->id], $checkOperational);
            $this->scopeConflicts->assertNoConflicts($candidate, [$source->id], lock: true, checkOperational: $checkOperational);
            $oldScope = $this->scopeFrom($source);
            $oldEmployeeId = $source->employee_id;
            $status = $oldEmployeeId === $prepared['employee']->id
                ? ResponsibilityAssignmentStatus::Superseded
                : ResponsibilityAssignmentStatus::Transferred;

            $this->end($source, $status, $actor);
            $this->assertFingerprintAvailable($prepared['fingerprint'], lock: true);
            $successor = $this->createHeader(
                $reference,
                $prepared['employee'],
                ResponsibilityAssignmentMode::Scope,
                $prepared['fingerprint'],
                now()->toDateTimeString(),
                $actor,
                $data->reason,
                $source->notes,
                $data->idempotencyKey,
                $source->id,
                $data->assignStockByDefault,
            );
            $this->writeScopes($successor, $prepared['scope'], null);
            $this->activity->log($event, $actor, $successor, [
                'assignment_id' => $successor->id,
                'predecessor_assignment_id' => $source->id,
                'reference' => $successor->reference,
                'from_employee_id' => $oldEmployeeId,
                'to_employee_id' => $successor->employee_id,
                'old_scope' => $this->safeProperties($source, $oldScope, null, $data->reason),
                'new_scope' => $this->safeProperties($successor, $prepared['scope'], null, $data->reason),
                'previous_assign_stock_by_default' => $previousDefaultStock ?? $source->assign_stock_by_default,
                'assign_stock_by_default' => $successor->assign_stock_by_default,
                'reason' => trim($data->reason),
            ]);

            return $successor->load($this->relations());
        });
    }

    private function createDataFromChange(ChangeResponsibilityScopeData $data, ?string $notes = null): CreateResponsibilityAssignmentData
    {
        return new CreateResponsibilityAssignmentData(
            employeeId: $data->employeeId,
            mode: ResponsibilityAssignmentMode::Scope,
            brandId: $data->brandId,
            platformId: $data->platformId,
            productId: $data->productId,
            productInventoryId: null,
            assignedQuantity: null,
            effectiveAt: now()->toDateTimeString(),
            reason: $data->reason,
            notes: $notes,
            idempotencyKey: $data->idempotencyKey,
            categoryId: $data->categoryId,
            warehouseId: $data->warehouseId,
            condition: $data->condition,
            assignStockByDefault: $data->assignStockByDefault,
        );
    }

    private function createDataFromScope(array $scope, int $employeeId, ResponsibilityAssignmentMode $mode, ?int $quantity, bool $assignStockByDefault, string $reason, string $idempotencyKey): CreateResponsibilityAssignmentData
    {
        return new CreateResponsibilityAssignmentData(
            employeeId: $employeeId,
            mode: $mode,
            brandId: $scope['brand']?->id,
            platformId: $scope['platform']?->id,
            productId: $scope['product']?->id,
            productInventoryId: $scope['inventory']?->id,
            assignedQuantity: $quantity,
            effectiveAt: now()->toDateTimeString(),
            reason: $reason,
            notes: null,
            idempotencyKey: $idempotencyKey,
            categoryId: $scope['category']?->id,
            warehouseId: $scope['warehouse']?->id,
            condition: $scope['condition'],
            assignStockByDefault: $assignStockByDefault,
        );
    }

    private function validateReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason is required and may not exceed 2000 characters.']);
        }
    }

    private function sameScope(ResponsibilityAssignment $source, ChangeResponsibilityScopeData $data): bool
    {
        return $source->employee_id === $data->employeeId
            && $source->brandScope?->product_brand_id === $data->brandId
            && $source->productScope?->product_id === $data->productId
            && $source->categoryScope?->product_category_id === $data->categoryId
            && $source->conditionScope?->product_condition === $data->condition
            && $source->warehouseScope?->warehouse_id === $data->warehouseId
            && $source->platformScope?->marketplace_platform_id === $data->platformId
            && $source->assign_stock_by_default === $data->assignStockByDefault;
    }

    private function lockResponsibilityScopeMutations(): void
    {
        DB::table('reference_sequences')->where('key', 'responsibility_assignment')->lockForUpdate()->first();
    }

    public function changeQuantity(ResponsibilityAssignment $source, ChangeResponsibilityQuantityData $data, User $actor): ResponsibilityAssignment
    {
        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        if ($data->quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Assigned quantity must be at least 1.']);
        }

        $source->load($this->relations());
        $inventoryId = $source->quantityScope?->product_inventory_id
            ?? throw new InvalidResponsibilityScopeException('Only quantity assignments can change quantity.');
        $reference = $this->references->nextResponsibilityAssignmentReference();

        return DB::transaction(function () use ($source, $data, $actor, $inventoryId, $reference): ResponsibilityAssignment {
            $this->capacity->lockAndAssert($inventoryId, $data->quantity, $source->id);
            $source = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($source->id);
            $source->load($this->relations());
            $this->assertActive($source);
            $this->allocations->assertMaySupersede($source);
            $scope = $this->scopeFrom($source);
            $fingerprint = $source->active_fingerprint;
            $employee = $source->employee;

            $this->end($source, ResponsibilityAssignmentStatus::Superseded, $actor);
            $successor = $this->createHeader($reference, $employee, ResponsibilityAssignmentMode::Quantity, $fingerprint, now()->toDateTimeString(), $actor, $data->reason, $source->notes, $data->idempotencyKey, $source->id, $source->assign_stock_by_default);
            $this->writeScopes($successor, $scope, $data->quantity);
            $this->activity->log('responsibility.quantity_changed', $actor, $successor, [
                ...$this->safeProperties($successor, $scope, $data->quantity, $data->reason),
                'predecessor_assignment_id' => $source->id,
                'previous_quantity' => $source->quantityScope->assigned_quantity,
            ]);

            return $successor->load($this->relations());
        });
    }

    public function deactivate(ResponsibilityAssignment $assignment, DeactivateResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        if (trim($data->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($assignment, $data, $actor): ResponsibilityAssignment {
            $assignment = ResponsibilityAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->status !== ResponsibilityAssignmentStatus::Active) {
                return $assignment;
            }

            $assignment->load($this->relations());
            if ($assignment->quantityScope !== null) {
                $this->allocations->assertMayDeactivate($assignment);
            }

            $this->end($assignment, ResponsibilityAssignmentStatus::Inactive, $actor);
            $this->activity->log('responsibility.deactivated', $actor, $assignment, [
                'assignment_id' => $assignment->id,
                'reference' => $assignment->reference,
                'employee_id' => $assignment->employee_id,
                'status' => ResponsibilityAssignmentStatus::Inactive->value,
                'ended_at' => $assignment->ended_at?->toIso8601String(),
                'reason' => $data->reason,
            ]);

            return $assignment;
        });
    }

    /**
     * @param  Collection<int, ResponsibilityAssignment>  $assignments
     * @return Collection<int, ResponsibilityAssignment>
     */
    public function deactivateLockedBatch(Collection $assignments, DeactivateResponsibilityAssignmentData $data, User $actor): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Bulk Responsibility deactivation requires an active transaction.');
        }

        if (trim($data->reason) === '' || mb_strlen($data->reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason is required and may not exceed 2000 characters.']);
        }

        $assignments->load($this->relations());
        $blocked = $assignments->map(function (ResponsibilityAssignment $assignment): ?string {
            if ($assignment->status !== ResponsibilityAssignmentStatus::Active || $assignment->ended_at !== null) {
                return "{$assignment->reference}: Assignment is no longer active.";
            }

            if ($assignment->quantityScope !== null && $this->allocations->usage($assignment, lock: true)['active'] > 0) {
                return "{$assignment->reference}: Active reservations must be released before deactivation.";
            }

            return null;
        })->filter()->values();

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages(['reason' => $blocked->all()]);
        }

        return $assignments->map(function (ResponsibilityAssignment $assignment) use ($data, $actor): ResponsibilityAssignment {
            $this->end($assignment, ResponsibilityAssignmentStatus::Inactive, $actor);
            $this->activity->log('responsibility.deactivated', $actor, $assignment, [
                'assignment_id' => $assignment->id,
                'reference' => $assignment->reference,
                'employee_id' => $assignment->employee_id,
                'status' => ResponsibilityAssignmentStatus::Inactive->value,
                'ended_at' => $assignment->ended_at?->toIso8601String(),
                'reason' => $data->reason,
            ]);

            return $assignment;
        })->values();
    }

    /** @return array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory, warehouse: ?Warehouse} */
    private function validatedScope(CreateResponsibilityAssignmentData $data): array
    {
        if (trim($data->reason) === '' || mb_strlen($data->reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason is required and may not exceed 2000 characters.']);
        }

        $effectiveAt = CarbonImmutable::parse($data->effectiveAt);
        if ($effectiveAt->isFuture()) {
            throw ValidationException::withMessages(['effective_at' => 'Future-dated activation is not supported in this release.']);
        }

        $brand = $data->brandId === null ? null : ProductBrand::query()->active()->findOrFail($data->brandId);
        $category = $data->categoryId === null ? null : ProductCategory::query()->active()->findOrFail($data->categoryId);
        $platform = $data->platformId === null ? null : MarketplacePlatform::query()->active()->findOrFail($data->platformId);
        $product = $data->productId === null ? null : Product::query()->products()->where('status', ProductStatus::Active->value)->findOrFail($data->productId);
        $inventory = $data->productInventoryId === null ? null : ProductInventory::query()->whereHas('product', fn ($query) => $query->products())->with(['product', 'warehouse'])->findOrFail($data->productInventoryId);
        $warehouse = $data->warehouseId === null ? null : Warehouse::query()->where('status', true)->find($data->warehouseId);
        $condition = $data->condition;

        if ($data->assignStockByDefault && ! $this->scopeConflicts->canAssignStockByDefault($data)) {
            throw ValidationException::withMessages(['assign_stock_by_default' => 'Default stock assignment requires a Brand, Category, or Product scope. Shared Platform-only and Platform + Category responsibilities cannot own stock by default.']);
        }

        if ($data->warehouseId !== null && $warehouse === null) {
            throw ValidationException::withMessages(['warehouse_id' => 'Select an active Warehouse.']);
        }

        if ($brand !== null && $product !== null && $product->brand_id !== $brand->id) {
            throw ValidationException::withMessages(['product_id' => 'The selected Product does not belong to the selected Brand.']);
        }
        if ($category !== null && $product !== null && $product->category_id !== $category->id) {
            throw ValidationException::withMessages(['product_id' => 'The selected Product does not belong to the selected Category.']);
        }

        if ($data->mode === ResponsibilityAssignmentMode::Scope) {
            if ($inventory !== null || $data->assignedQuantity !== null) {
                throw new InvalidResponsibilityScopeException('Scope responsibilities cannot include inventory quantity. Use the separate Quantity Responsibility workflow.');
            }
            if ($brand === null && $category === null && $platform === null && $product === null && $warehouse === null && $condition === null) {
                throw new InvalidResponsibilityScopeException('Select at least one Product, Brand, Category, Condition, Warehouse, or Platform.');
            }
        } elseif ($inventory === null || $data->assignedQuantity === null || $data->assignedQuantity < 1 || $brand !== null || $category !== null || $product !== null || $warehouse !== null || $condition !== null) {
            throw new InvalidResponsibilityScopeException('Quantity assignments require exactly one Product Inventory and a positive quantity.');
        }

        if ($inventory !== null && ($inventory->product->status !== ProductStatus::Active || ! $inventory->warehouse->status)) {
            throw ValidationException::withMessages(['product_inventory_id' => 'The Product and Warehouse must both be active.']);
        }

        return compact('brand', 'category', 'platform', 'product', 'inventory', 'warehouse', 'condition');
    }

    private function activeEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()->with('team')->where('status', true)->whereNotNull('user_id')->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active Employee linked to a User account.']);
        }

        return $employee;
    }

    private function createHeader(string $reference, Employee $employee, ResponsibilityAssignmentMode $mode, string $fingerprint, string $effectiveAt, User $actor, string $reason, ?string $notes, string $idempotencyKey, ?int $predecessorId = null, bool $assignStockByDefault = false): ResponsibilityAssignment
    {
        return ResponsibilityAssignment::query()->create([
            'reference' => $reference,
            'employee_id' => $employee->id,
            'team_id_at_assignment' => $employee->team_id,
            'team_name_at_assignment' => $employee->team?->name,
            'assignment_mode' => $mode,
            'status' => ResponsibilityAssignmentStatus::Active,
            'active_fingerprint' => $fingerprint,
            'effective_at' => CarbonImmutable::parse($effectiveAt),
            'ended_at' => null,
            'assigned_by_user_id' => $actor->id,
            'ended_by_user_id' => null,
            'predecessor_assignment_id' => $predecessorId,
            'assign_stock_by_default' => $assignStockByDefault,
            'idempotency_key' => $idempotencyKey,
            'reason' => trim($reason),
            'notes' => $notes === null ? null : trim($notes),
        ]);
    }

    /** @param array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory, warehouse: ?Warehouse} $scope */
    private function writeScopes(ResponsibilityAssignment $assignment, array $scope, ?int $quantity): void
    {
        if ($scope['brand'] !== null) {
            ResponsibilityAssignmentBrand::query()->create(['assignment_id' => $assignment->id, 'product_brand_id' => $scope['brand']->id]);
        }
        if ($scope['category'] !== null) {
            ResponsibilityAssignmentCategory::query()->create(['assignment_id' => $assignment->id, 'product_category_id' => $scope['category']->id]);
        }
        if ($scope['platform'] !== null) {
            ResponsibilityAssignmentPlatform::query()->create(['assignment_id' => $assignment->id, 'marketplace_platform_id' => $scope['platform']->id]);
        }
        if ($scope['product'] !== null) {
            ResponsibilityAssignmentProduct::query()->create(['assignment_id' => $assignment->id, 'product_id' => $scope['product']->id]);
        }
        if ($scope['inventory'] !== null) {
            InventoryResponsibilityQuantity::query()->create(['assignment_id' => $assignment->id, 'product_inventory_id' => $scope['inventory']->id, 'assigned_quantity' => $quantity]);
        }
        if ($scope['warehouse'] !== null) {
            ResponsibilityAssignmentWarehouse::query()->create(['assignment_id' => $assignment->id, 'warehouse_id' => $scope['warehouse']->id]);
        }
        if ($scope['condition'] !== null) {
            ResponsibilityAssignmentCondition::query()->create(['assignment_id' => $assignment->id, 'product_condition' => $scope['condition']]);
        }
    }

    private function end(ResponsibilityAssignment $assignment, ResponsibilityAssignmentStatus $status, User $actor): void
    {
        $assignment->forceFill(['active_fingerprint' => null, 'status' => $status, 'ended_at' => now(), 'ended_by_user_id' => $actor->id])->save();
    }

    private function assertActive(ResponsibilityAssignment $assignment): void
    {
        if ($assignment->status !== ResponsibilityAssignmentStatus::Active || $assignment->active_fingerprint === null) {
            throw ValidationException::withMessages(['assignment' => 'Only an active Responsibility Assignment can be changed.']);
        }
    }

    private function assertFingerprintAvailable(string $fingerprint, bool $lock = false, array $exceptAssignmentIds = []): void
    {
        $query = ResponsibilityAssignment::query()->where('active_fingerprint', $fingerprint);
        if ($exceptAssignmentIds !== []) {
            $query->whereNotIn('id', $exceptAssignmentIds);
        }
        if (($lock ? $query->lockForUpdate() : $query)->exists()) {
            throw new DuplicateActiveResponsibilityException('This exact active responsibility combination already exists.');
        }
    }

    /** @return array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory, warehouse: ?Warehouse} */
    private function scopeFrom(ResponsibilityAssignment $assignment): array
    {
        return [
            'brand' => $assignment->brandScope?->brand,
            'category' => $assignment->categoryScope?->category,
            'platform' => $assignment->platformScope?->platform,
            'product' => $assignment->productScope?->product,
            'inventory' => $assignment->quantityScope?->inventory,
            'warehouse' => $assignment->warehouseScope?->warehouse,
            'condition' => $assignment->conditionScope?->product_condition,
        ];
    }

    /** @param array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory, warehouse: ?Warehouse} $scope */
    private function safeProperties(ResponsibilityAssignment $assignment, array $scope, ?int $quantity, string $reason): array
    {
        return [
            'assignment_id' => $assignment->id,
            'reference' => $assignment->reference,
            'employee_id' => $assignment->employee_id,
            'assignment_mode' => $assignment->assignment_mode->value,
            'brand_id' => $scope['brand']?->id,
            'category_id' => $scope['category']?->id,
            'platform_id' => $scope['platform']?->id,
            'product_id' => $scope['product']?->id ?? $scope['inventory']?->product_id,
            'product_inventory_id' => $scope['inventory']?->id,
            'warehouse_id' => $scope['warehouse']?->id ?? $scope['inventory']?->warehouse_id,
            'product_condition' => $scope['condition']?->value,
            'assigned_quantity' => $quantity,
            'assign_stock_by_default' => $assignment->assign_stock_by_default,
            'status' => $assignment->status->value,
            'effective_at' => $assignment->effective_at->toIso8601String(),
            'reason' => $reason,
        ];
    }

    private function relations(): array
    {
        return ['employee.team', 'brandScope.brand', 'categoryScope.category', 'platformScope.platform', 'productScope.product', 'quantityScope.inventory.product', 'quantityScope.inventory.warehouse', 'warehouseScope.warehouse', 'conditionScope'];
    }
}
