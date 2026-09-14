<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\CustomerReturnPermission;
use App\Enums\OrderPermission;
use App\Enums\WarrantyRepairPermission;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Mobile\MobileInventoryService;
use App\Services\Mobile\MobileWorkspaceCapabilities;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Responsibilities\ResponsibilityReadService;
use App\Services\Returns\CustomerReturnReadService;
use App\Services\Tasks\TaskQueryService;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly ResponsibilityReadService $responsibilities,
        private readonly OrderAuthorization $orderAuthorization,
        private readonly OrderResponsibilityScopeService $orderScope,
        private readonly ErpDashboardService $dashboard,
        private readonly CustomerReturnAuthorization $returnAuthorization,
        private readonly CustomerReturnReadService $returnReads,
        private readonly WarrantyRepairAuthorization $warrantyAuthorization,
        private readonly TaskQueryService $tasks,
    ) {}

    public function modules(Request $request): JsonResponse
    {
        return response()->json(['data' => app(MobileWorkspaceCapabilities::class)->modules($request->user())]);
    }

    public function inventory(Request $request): JsonResponse
    {
        return response()->json(app(MobileInventoryService::class)->page($request));
    }

    public function products(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($this->orderScope->requiresScope($user)) {
            $items = $this->responsibilities
                ->myInventory($user)
                ->unique('product_id')
                ->take(100)
                ->map(fn (object $row): array => $this->item(
                    $row->product_id,
                    (string) $row->name,
                    collect([
                        $row->sku ? 'SKU: '.$row->sku : null,
                        $row->brand ?? null,
                        $row->category ?? null,
                    ])->filter()->implode(' Â· '),
                    $row->model ? 'Model: '.$row->model : null,
                    (string) $row->stock_status,
                ))
                ->values();

            return $this->respond($items);
        }

        $items = Product::query()
            ->active()
            ->with(['brandRelation:id,name', 'categoryRelation:id,name'])
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (Product $product): array => $this->item(
                $product->id,
                $product->name,
                collect([
                    $product->sku ? 'SKU: '.$product->sku : null,
                    $product->displayBrandName(),
                    $product->displayCategoryName(),
                ])->filter()->implode(' Â· '),
                $product->model ? 'Model: '.$product->model : null,
                $this->enumValue($product->status),
            ))
            ->values();

        return $this->respond($items);
    }

    public function orders(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->orderAuthorization->allows($user, OrderPermission::View)) {
            return $this->noAccess();
        }

        $query = Order::query()
            ->with(['platform:id,name', 'handledBy:id,name'])
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        $this->orderScope->applyOrders($query, $user);

        $items = $query
            ->limit(100)
            ->get()
            ->map(fn (Order $order): array => $this->item(
                $order->id,
                'Order #'.$order->id,
                collect([
                    $order->platform?->name,
                    $this->enumValue($order->source),
                    $order->handledBy?->name,
                ])->filter()->implode(' Â· '),
                collect([
                    $this->date($order->order_date),
                    'AED '.number_format((float) $order->grand_total, 2),
                ])->filter()->implode(' Â· '),
                $this->enumValue($order->status),
            ))
            ->values();

        return $this->respond($items);
    }

    public function responsibilities(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $items = $this->responsibilities
            ->myResponsibilities($user)
            ->take(100)
            ->map(fn (array $row): array => $this->item(
                $row['reference'],
                $row['type'],
                collect([
                    $row['scope'],
                    $row['platform'] ?? null,
                ])->filter()->implode(' Â· '),
                $row['quantity'] !== null
                    ? 'Assigned: '.$row['quantity'].' Â· Remaining: '.$row['remaining']
                    : null,
                'active',
            ))
            ->values();

        return $this->respond($items);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $this->dashboard->forUser(
            user: $user,
            period: 'today',
            widgetKeys: ['attention'],
        );

        $items = collect($data['attention'] ?? [])
            ->take(100)
            ->values()
            ->map(function (mixed $row, int $index): array {
                return $this->item(
                    $index + 1,
                    (string) (
                        data_get($row, 'title')
                        ?? data_get($row, 'label')
                        ?? 'ERP Alert'
                    ),
                    null,
                    ($value = data_get($row, 'value') ?? data_get($row, 'count')) !== null
                        ? 'Count: '.$value
                        : null,
                    'attention',
                );
            });

        return $this->respond(
            $items,
            $items->isEmpty() ? 'No ERP alerts require your attention.' : null,
        );
    }

    public function returns(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->returnAuthorization->allows($user, CustomerReturnPermission::View)) {
            return $this->noAccess();
        }

        $items = $this->returnReads
            ->query($user)
            ->with(['platform:id,name', 'displayItems'])
            ->orderByDesc('reported_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function ($return): array {
                $names = $return->displayItems
                    ->pluck('product_name_snapshot')
                    ->filter()
                    ->take(3)
                    ->implode(', ');

                return $this->item(
                    $return->id,
                    'Return #'.$return->id,
                    collect([
                        $return->platform?->name,
                        $names ?: null,
                    ])->filter()->implode(' Â· '),
                    $this->date($return->reported_at),
                    $this->enumValue($return->status),
                );
            })
            ->values();

        return $this->respond($items);
    }

    public function warranty(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if (! $this->warrantyAuthorization->allows($user, WarrantyRepairPermission::View)) {
            return $this->noAccess();
        }

        $query = $this->warrantyAuthorization->scopeQuery(
            WarrantyRepair::query(),
            $user,
        );

        $items = $query
            ->with(['product:id,name,sku', 'platform:id,name'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (WarrantyRepair $repair): array => $this->item(
                $repair->id,
                $repair->product?->name ?? 'Warranty Case #'.$repair->id,
                collect([
                    $repair->product?->sku ? 'SKU: '.$repair->product->sku : null,
                    $repair->platform?->name,
                    'Qty: '.(int) $repair->quantity,
                ])->filter()->implode(' Â· '),
                $repair->expected_return_at
                    ? 'Expected: '.$this->date($repair->expected_return_at)
                    : $this->date($repair->received_at),
                $this->enumValue($repair->status),
            ))
            ->values();

        return $this->respond($items);
    }

    public function tasks(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $items = $this->tasks
            ->personalDashboardTasks($user, 100)
            ->map(fn ($task): array => $this->item(
                $task->id,
                $task->title ?? 'Task #'.$task->id,
                'Priority: '.str($this->enumValue($task->priority) ?? 'normal')->headline(),
                $this->tasks->personalDueLabel($task),
                $this->tasks->personalStatus($task),
            ))
            ->values();

        return $this->respond($items);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('employee');

        return $user;
    }

    private function item(
        int|string $id,
        string $title,
        ?string $subtitle,
        ?string $meta,
        ?string $status,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'subtitle' => filled($subtitle) ? $subtitle : null,
            'meta' => filled($meta) ? $meta : null,
            'status' => filled($status) ? $status : null,
        ];
    }

    private function respond(Collection $items, ?string $message = null): JsonResponse
    {
        return response()->json([
            'data' => $items->values(),
            'message' => $message,
        ]);
    }

    private function noAccess(): JsonResponse
    {
        return response()->json([
            'data' => [],
            'message' => 'You do not have access to this module.',
        ]);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value === null ? null : (string) $value;
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d M Y');
        }

        return filled($value) ? (string) $value : null;
    }
}
