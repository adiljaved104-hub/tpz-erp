<?php

namespace App\Services\Purchases;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PurchaseHandlerResolver
{
    /**
     * @param  array<int, int>  $productIds
     * @return array{status: 'matched'|'ambiguous'|'unmatched', employee_id: ?int, candidate_ids: array<int, int>}
     */
    public function resolve(array $productIds, int $warehouseId): array
    {
        $productIds = collect($productIds)->map(fn ($id): int => (int) $id)->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return $this->unmatched();
        }

        $platformId = Warehouse::query()->whereKey($warehouseId)->value('marketplace_platform_id');
        $products = Product::query()->whereKey($productIds)->get(['id', 'brand_id', 'category_id'])->keyBy('id');
        $candidateSets = $productIds->map(fn (int $productId) => $this->candidateEmployeeIds(
            $products->get($productId),
            $platformId === null ? null : (int) $platformId,
        ));
        $allCandidates = $candidateSets->flatten()->unique()->sort()->values()->all();

        if ($candidateSets->contains(fn ($ids): bool => count($ids) !== 1)) {
            return $allCandidates === []
                ? $this->unmatched()
                : ['status' => 'ambiguous', 'employee_id' => null, 'candidate_ids' => $allCandidates];
        }

        $resolved = $candidateSets->pluck(0)->unique()->values();

        return $resolved->count() === 1
            ? ['status' => 'matched', 'employee_id' => (int) $resolved->first(), 'candidate_ids' => [(int) $resolved->first()]]
            : ['status' => 'ambiguous', 'employee_id' => null, 'candidate_ids' => $allCandidates];
    }

    /** @param array<int, int> $productIds */
    public function handlerForCreate(array $productIds, int $warehouseId, ?int $requestedEmployeeId, User $actor): ?int
    {
        if ($requestedEmployeeId !== null) {
            $this->assertMayChoose($actor);

            return $this->activeEmployeeId($requestedEmployeeId);
        }

        return $this->resolve($productIds, $warehouseId)['employee_id'];
    }

    /** @param array<int, int> $productIds */
    public function handlerForUpdate(array $productIds, int $warehouseId, ?int $requestedEmployeeId, ?int $existingEmployeeId, User $actor): ?int
    {
        if ($requestedEmployeeId !== null && $requestedEmployeeId !== $existingEmployeeId) {
            $this->assertMayChoose($actor);

            return $this->activeEmployeeId($requestedEmployeeId);
        }

        if ($existingEmployeeId !== null) {
            return $existingEmployeeId;
        }

        return $this->resolve($productIds, $warehouseId)['employee_id'];
    }

    public function mayChoose(User $user): bool
    {
        return in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    /** @return array<int, string> */
    public function activeEmployeeOptions(?int $historicalEmployeeId = null): array
    {
        $query = Employee::query()->where('status', true);

        if ($historicalEmployeeId !== null) {
            $query->orWhereKey($historicalEmployeeId);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    private function candidateEmployeeIds(?Product $product, ?int $platformId): array
    {
        if ($product === null) {
            return [];
        }

        return ResponsibilityAssignment::query()
            ->active()
            ->whereHas('employee', fn (Builder $query) => $query->where('status', true))
            ->whereDoesntHave('quantityScope')
            ->where(function (Builder $query) use ($platformId): void {
                $query->whereDoesntHave('platformScope');

                if ($platformId !== null) {
                    $query->orWhereHas('platformScope', fn (Builder $scope) => $scope->where('marketplace_platform_id', $platformId));
                }
            })
            ->where(function (Builder $query) use ($product): void {
                $query->whereHas('productScope', fn (Builder $scope) => $scope->where('product_id', $product->id))
                    ->orWhere(function (Builder $dimensions) use ($product): void {
                        $dimensions->where(function (Builder $present): void {
                            $present->whereHas('brandScope')->orWhereHas('categoryScope');
                        })->where(function (Builder $brand) use ($product): void {
                            $brand->whereDoesntHave('brandScope');
                            if ($product->brand_id !== null) {
                                $brand->orWhereHas('brandScope', fn (Builder $scope) => $scope->where('product_brand_id', $product->brand_id));
                            }
                        })->where(function (Builder $category) use ($product): void {
                            $category->whereDoesntHave('categoryScope');
                            if ($product->category_id !== null) {
                                $category->orWhereHas('categoryScope', fn (Builder $scope) => $scope->where('product_category_id', $product->category_id));
                            }
                        });
                    });
            })
            ->distinct()
            ->orderBy('employee_id')
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function assertMayChoose(User $actor): void
    {
        if (! $this->mayChoose($actor)) {
            throw ValidationException::withMessages([
                'handled_by_employee_id' => 'Only an Owner or Admin may explicitly choose the Purchase handler.',
            ]);
        }
    }

    private function activeEmployeeId(int $employeeId): int
    {
        if (! Employee::query()->whereKey($employeeId)->where('status', true)->exists()) {
            throw ValidationException::withMessages([
                'handled_by_employee_id' => 'Handled By must be an active Employee.',
            ]);
        }

        return $employeeId;
    }

    /** @return array{status: 'unmatched', employee_id: null, candidate_ids: array<int, int>} */
    private function unmatched(): array
    {
        return ['status' => 'unmatched', 'employee_id' => null, 'candidate_ids' => []];
    }
}
