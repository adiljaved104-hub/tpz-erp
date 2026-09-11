<?php

namespace App\Services\Responsibilities;

use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
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
use App\Models\ResponsibilityAssignmentPlatform;
use App\Models\ResponsibilityAssignmentProduct;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReferenceSequenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResponsibilityAssignmentService
{
    public function __construct(
        private readonly ResponsibilityCapacityService $capacity,
        private readonly ResponsibilityScopeFingerprint $fingerprints,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly ResponsibilityAllocationService $allocations,
    ) {}

    public function create(CreateResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
        if ($existing = ResponsibilityAssignment::query()->where('idempotency_key', $data->idempotencyKey)->first()) {
            return $existing;
        }

        $prepared = $this->prepareCreate($data);
        $reference = $this->references->nextResponsibilityAssignmentReference();

        return $this->createPrepared($data, $actor, $reference, $prepared);
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

        return $this->createPrepared($data, $actor, $reference, $this->prepareCreate($data));
    }

    /** @return array{scope: array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory}, employee: Employee, fingerprint: string} */
    private function prepareCreate(CreateResponsibilityAssignmentData $data): array
    {
        $scope = $this->validatedScope($data);
        $employee = $this->activeEmployee($data->employeeId);
        $fingerprint = $this->fingerprints->make($employee->id, $data->mode, $scope['brand']?->id, $scope['platform']?->id, $scope['product']?->id, $scope['inventory']?->id, $scope['category']?->id);
        $this->assertFingerprintAvailable($fingerprint);

        return compact('scope', 'employee', 'fingerprint');
    }

    /** @param array{scope: array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory}, employee: Employee, fingerprint: string} $prepared */
    private function createPrepared(CreateResponsibilityAssignmentData $data, User $actor, string $reference, array $prepared): ResponsibilityAssignment
    {
        return DB::transaction(function () use ($data, $actor, $reference, $prepared): ResponsibilityAssignment {
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
            );
            $this->writeScopes($assignment, $prepared['scope'], $data->assignedQuantity);
            $this->activity->log('responsibility.created', $actor, $assignment, $this->safeProperties($assignment, $prepared['scope'], $data->assignedQuantity, $data->reason));

            return $assignment->load($this->relations());
        });
    }

    public function transfer(ResponsibilityAssignment $source, TransferResponsibilityAssignmentData $data, User $actor): ResponsibilityAssignment
    {
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
            $fingerprint = $this->fingerprints->make($employee->id, $source->assignment_mode, $scope['brand']?->id, $scope['platform']?->id, $scope['product']?->id, $scope['inventory']?->id, $scope['category']?->id);

            $this->end($source, ResponsibilityAssignmentStatus::Transferred, $actor);
            $this->assertFingerprintAvailable($fingerprint, lock: true);
            $successor = $this->createHeader($reference, $employee, $source->assignment_mode, $fingerprint, now()->toDateTimeString(), $actor, $data->reason, $source->notes, $data->idempotencyKey, $source->id);
            $this->writeScopes($successor, $scope, $quantity);
            $this->activity->log('responsibility.transferred', $actor, $successor, [
                ...$this->safeProperties($successor, $scope, $quantity, $data->reason),
                'predecessor_assignment_id' => $source->id,
                'from_employee_id' => $source->employee_id,
            ]);

            return $successor->load($this->relations());
        });
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
            $successor = $this->createHeader($reference, $employee, ResponsibilityAssignmentMode::Quantity, $fingerprint, now()->toDateTimeString(), $actor, $data->reason, $source->notes, $data->idempotencyKey, $source->id);
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

    /** @return array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory} */
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

        if ($brand !== null && $product !== null && $product->brand_id !== $brand->id) {
            throw ValidationException::withMessages(['product_id' => 'The selected Product does not belong to the selected Brand.']);
        }

        if ($data->mode === ResponsibilityAssignmentMode::Scope) {
            if ($inventory !== null || $data->assignedQuantity !== null || ($brand === null && $category === null && $platform === null && $product === null)
                || ($product !== null && ($brand !== null || $category !== null))) {
                throw new InvalidResponsibilityScopeException('Scope assignments require Brand, Category, Brand + Category, Platform, or Product dimensions and cannot contain quantity.');
            }
        } elseif ($inventory === null || $data->assignedQuantity === null || $data->assignedQuantity < 1 || $brand !== null || $category !== null || $product !== null) {
            throw new InvalidResponsibilityScopeException('Quantity assignments require exactly one Product Inventory and a positive quantity.');
        }

        if ($inventory !== null && ($inventory->product->status !== ProductStatus::Active || ! $inventory->warehouse->status)) {
            throw ValidationException::withMessages(['product_inventory_id' => 'The Product and Warehouse must both be active.']);
        }

        return compact('brand', 'category', 'platform', 'product', 'inventory');
    }

    private function activeEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()->with('team')->where('status', true)->whereNotNull('user_id')->find($employeeId);

        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active Employee linked to a User account.']);
        }

        return $employee;
    }

    private function createHeader(string $reference, Employee $employee, ResponsibilityAssignmentMode $mode, string $fingerprint, string $effectiveAt, User $actor, string $reason, ?string $notes, string $idempotencyKey, ?int $predecessorId = null): ResponsibilityAssignment
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
            'idempotency_key' => $idempotencyKey,
            'reason' => trim($reason),
            'notes' => $notes === null ? null : trim($notes),
        ]);
    }

    /** @param array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory} $scope */
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

    private function assertFingerprintAvailable(string $fingerprint, bool $lock = false): void
    {
        $query = ResponsibilityAssignment::query()->where('active_fingerprint', $fingerprint);
        if (($lock ? $query->lockForUpdate() : $query)->exists()) {
            throw new DuplicateActiveResponsibilityException('This exact active responsibility combination already exists.');
        }
    }

    /** @return array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory} */
    private function scopeFrom(ResponsibilityAssignment $assignment): array
    {
        return [
            'brand' => $assignment->brandScope?->brand,
            'category' => $assignment->categoryScope?->category,
            'platform' => $assignment->platformScope?->platform,
            'product' => $assignment->productScope?->product,
            'inventory' => $assignment->quantityScope?->inventory,
        ];
    }

    /** @param array{brand: ?ProductBrand, category: ?ProductCategory, platform: ?MarketplacePlatform, product: ?Product, inventory: ?ProductInventory} $scope */
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
            'assigned_quantity' => $quantity,
            'status' => $assignment->status->value,
            'effective_at' => $assignment->effective_at->toIso8601String(),
            'reason' => $reason,
        ];
    }

    private function relations(): array
    {
        return ['employee.team', 'brandScope.brand', 'categoryScope.category', 'platformScope.platform', 'productScope.product', 'quantityScope.inventory.product', 'quantityScope.inventory.warehouse'];
    }
}
