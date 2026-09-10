<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Pages;

use App\Actions\Responsibilities\CreateResponsibilityAssignment as CreateResponsibilityAssignmentAction;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentBatchData;
use App\DTOs\Responsibilities\CreateResponsibilityAssignmentData;
use App\Enums\ResponsibilityAssignmentMode;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use App\Services\Responsibilities\BulkResponsibilityAssignmentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateResponsibilityAssignment extends CreateRecord
{
    protected static string $resource = ResponsibilityAssignmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $scope = self::normalizedScope($data);
        $mode = $scope['mode'];

        if (in_array($scope['type'], ['brand', 'brand_platform', 'category_brand', 'category_brand_platform', 'product', 'product_platform'], true)) {
            $kind = in_array($scope['type'], ['brand', 'brand_platform', 'category_brand', 'category_brand_platform'], true) ? 'brand' : 'product';

            return app(BulkResponsibilityAssignmentService::class)->create(new CreateResponsibilityAssignmentBatchData(
                employeeId: (int) $data['employee_id'],
                scopeType: $kind,
                scopeIds: $kind === 'brand' ? $scope['brand_ids'] : $scope['product_ids'],
                categoryId: $scope['category_id'],
                platformId: $scope['platform_id'],
                effectiveAt: (string) $data['effective_at'],
                reason: $data['reason'],
                notes: $data['notes'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ), auth()->user())->firstOrFail();
        }

        return app(CreateResponsibilityAssignmentAction::class)->handle(new CreateResponsibilityAssignmentData(
            employeeId: (int) $data['employee_id'],
            mode: $mode,
            brandId: null,
            platformId: $scope['platform_id'],
            productId: null,
            productInventoryId: $scope['product_inventory_id'],
            assignedQuantity: $scope['assigned_quantity'],
            effectiveAt: (string) $data['effective_at'],
            reason: $data['reason'],
            notes: $data['notes'] ?? null,
            idempotencyKey: $data['idempotency_key'],
            categoryId: $scope['category_id'],
        ), auth()->user());
    }

    /** @return array{type:string,mode:ResponsibilityAssignmentMode,brand_ids:array<int,int>,category_id:?int,platform_id:?int,product_ids:array<int,int>,product_inventory_id:?int,assigned_quantity:?int} */
    public static function normalizedScope(array $data): array
    {
        $type = (string) ($data['scope_type'] ?? '');
        abort_unless(in_array($type, ['brand', 'platform', 'brand_platform', 'category', 'category_platform', 'category_brand', 'category_brand_platform', 'product', 'product_platform', 'quantity', 'quantity_platform'], true), 422);
        $mode = str_starts_with($type, 'quantity') ? ResponsibilityAssignmentMode::Quantity : ResponsibilityAssignmentMode::Scope;

        return [
            'type' => $type,
            'mode' => $mode,
            'brand_ids' => in_array($type, ['brand', 'brand_platform', 'category_brand', 'category_brand_platform'], true) ? array_values(array_unique(array_map('intval', $data['brand_ids'] ?? []))) : [],
            'category_id' => in_array($type, ['category', 'category_platform', 'category_brand', 'category_brand_platform'], true) && filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
            'platform_id' => in_array($type, ['platform', 'brand_platform', 'category_platform', 'category_brand_platform', 'product_platform', 'quantity_platform'], true) && filled($data['platform_id'] ?? null) ? (int) $data['platform_id'] : null,
            'product_ids' => in_array($type, ['product', 'product_platform'], true) ? array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))) : [],
            'product_inventory_id' => in_array($type, ['quantity', 'quantity_platform'], true) && filled($data['product_inventory_id'] ?? null) ? (int) $data['product_inventory_id'] : null,
            'assigned_quantity' => in_array($type, ['quantity', 'quantity_platform'], true) && filled($data['assigned_quantity'] ?? null) ? (int) $data['assigned_quantity'] : null,
        ];
    }
}
