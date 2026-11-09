<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\CustomerReturnPermission;
use App\Enums\DamagedStockPermission;
use App\Enums\OrderPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\WarrantyRepairPermission;
use App\Exceptions\WarrantyRepairException;
use App\Models\CustomerReturn;
use App\Models\DamagedStockEvent;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\SafetClaim;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Inventory\DamagedItemsQueueService;
use App\Services\ServiceCases\WarrantyRepairService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DamagedInventoryController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(app(DamagedStockAuthorization::class)->allows($request->user(), DamagedStockPermission::View), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'source' => ['nullable', 'string', 'max:60'],
            'platform_id' => ['nullable', 'integer', 'exists:marketplace_platforms,id'],
            'status' => ['nullable', 'in:damaged,awaiting_classification,resolved'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $service = app(DamagedItemsQueueService::class);
        $paginator = $service->paginate($request->user(), [
            'search' => $data['q'] ?? null,
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'source' => $data['source'] ?? null,
            'platform_id' => $data['platform_id'] ?? null,
            'status' => $data['status'] ?? null,
        ], $data['per_page'] ?? 25);

        return response()->json([
            ...$paginator->toArray(),
            'summary' => $service->summary($request->user(), [
                'search' => $data['q'] ?? null,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'source' => $data['source'] ?? null,
                'platform_id' => $data['platform_id'] ?? null,
                'status' => $data['status'] ?? null,
            ]),
            'filters' => $service->filterOptions($request->user()),
        ]);
    }

    public function show(Request $request, int $damage): JsonResponse
    {
        abort_unless(app(DamagedStockAuthorization::class)->allows($request->user(), DamagedStockPermission::View), 403);
        $item = app(DamagedItemsQueueService::class)->find($request->user(), $damage);
        abort_if($item === null, 404);

        $context = new WarrantyRepair([
            'product_id' => $item['product_id'],
            'warehouse_id' => $item['warehouse_id'],
            'marketplace_platform_id' => $item['marketplace_platform_id'],
        ]);
        $canRepair = $item['status'] === 'damaged'
            && (int) $item['available_repair_quantity'] > 0
            && app(WarrantyRepairAuthorization::class)->allows($request->user(), WarrantyRepairPermission::Create, $context);
        $fields = collect($item)->except([
            'id', 'title', 'subtitle', 'meta', 'actions', 'product_id', 'warehouse_id',
            'marketplace_platform_id', 'row_kind', 'available_repair_quantity', 'order_id', 'return_id',
            'claim_id', 'return_reference', 'order_reference', 'claim_reference', 'related_reference',
        ])->all();
        $return = $item['return_id'] === null ? null : CustomerReturn::query()->find($item['return_id']);
        $order = $item['order_id'] === null ? null : Order::query()->find($item['order_id']);
        $claim = $item['claim_id'] === null ? null : SafetClaim::query()->find($item['claim_id']);
        $related = collect([
            $return === null || ! app(CustomerReturnAuthorization::class)->allows($request->user(), CustomerReturnPermission::View, $return) ? null : [
                'label' => $item['return_reference'] ?? 'Customer Return',
                'target' => ['module' => 'returns', 'id' => (int) $item['return_id']],
            ],
            $order === null || ! app(OrderAuthorization::class)->allows($request->user(), OrderPermission::View, $order) ? null : [
                'label' => $item['order_reference'] ?? 'Order',
                'target' => ['module' => 'orders', 'id' => (int) $item['order_id']],
            ],
            $claim === null || ! app(SafetClaimAuthorization::class)->allows($request->user(), SafetClaimPermission::View, $claim) ? null : [
                'label' => $item['claim_reference'] ?? 'Safe-T Claim',
                'target' => ['module' => 'cases/claims', 'id' => (int) $item['claim_id']],
            ],
        ])->filter()->values();

        return response()->json(['data' => [
            'id' => $item['id'],
            'title' => $item['reference'] ?? $item['sku'],
            'subtitle' => $item['product_name'],
            'meta' => collect([$item['location'], $item['source_label']])->filter()->implode(' · '),
            'status' => $item['status'],
            'fields' => $fields,
            'field_schema' => $this->readFields($fields),
            'related' => $related,
            'actions' => $canRepair ? [$this->action('send-to-repair', 'Send to Internal Repair', [
                $this->field('quantity', 'Repair quantity', 'number', true, (int) $item['available_repair_quantity']),
                $this->field('issue_description', 'Repair issue / instructions', 'multiline', true, $item['reason']),
                $this->field('service_provider', 'Technician / service provider'),
                $this->field('expected_return_at', 'Expected return', 'date'),
                $this->field('notes', 'Notes', 'multiline'),
            ], confirmationMessage: 'This creates an audited Internal Repair. Damaged stock remains unchanged until the existing repair and QC workflow completes.')] : [],
        ]]);
    }

    public function sendToRepair(Request $request, int $damage): JsonResponse
    {
        abort_unless(app(DamagedStockAuthorization::class)->allows($request->user(), DamagedStockPermission::View), 403);
        $item = app(DamagedItemsQueueService::class)->find($request->user(), $damage);
        abort_if($item === null, 404);
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'issue_description' => ['required', 'string', 'max:5000'],
            'service_provider' => ['nullable', 'string', 'max:255'],
            'expected_return_at' => ['nullable', 'date', 'after_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $repair = $damage > 0
                ? app(WarrantyRepairService::class)->createFromDamagedItem(DamagedStockEvent::query()->findOrFail($damage), $data, $request->user())
                : app(WarrantyRepairService::class)->createFromLegacyDamagedInventory(ProductInventory::query()->findOrFail(abs($damage)), $data, $request->user());
        } catch (WarrantyRepairException|ValidationException $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            abort(422, $exception->getMessage());
        } catch (AuthorizationException) {
            abort(403);
        }

        return response()->json(['data' => [
            'id' => $repair->id,
            'reference' => $repair->reference,
            'target' => ['module' => 'internal-repairs', 'id' => $repair->id],
        ]], 201);
    }
}
