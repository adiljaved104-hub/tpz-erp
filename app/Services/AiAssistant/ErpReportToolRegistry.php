<?php

namespace App\Services\AiAssistant;

use App\Enums\AiAssistantPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\InventoryPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\StockRequestStatus;
use App\Enums\WebSalesChannel;
use App\Models\AiAssistantAudit;
use App\Models\User;
use App\Services\Authorization\AiAssistantAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Inventory\StockRequestService;
use App\Services\Reports\ReportQueryService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;

class ErpReportToolRegistry
{
    public function __construct(
        private readonly ReportQueryService $reports,
        private readonly InventoryAuthorization $inventory,
        private readonly ResponsibilityAuthorization $responsibilities,
        private readonly StockRequestService $stockRequests,
        private readonly ResponsibilityReadService $responsibilityReads,
        private readonly AiAssistantAuthorization $assistantAuthorization,
        private readonly AiAssistantSettings $assistantSettings,
    ) {}

    public function definitions(): array
    {
        $definition = fn (string $name, string $description): array => [
            'type' => 'function', 'name' => $name, 'description' => $description,
            'parameters' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                'status' => ['type' => 'string'], 'channel' => ['type' => 'string'], 'employee_id' => ['type' => 'integer'],
                'platform_id' => ['type' => 'integer'], 'product_id' => ['type' => 'integer'], 'brand_id' => ['type' => 'integer'], 'warehouse_id' => ['type' => 'integer'],
            ], 'additionalProperties' => false], 'strict' => false,
        ];

        return [
            $definition('sales_orders_report', 'Read authorized order lifecycle records plus fulfilled sales summary. Dates follow existing ERP reports (order date); do not label this as net sales, VAT, or refund-adjusted revenue.'),
            $definition('web_sales_report', 'Read web sales report. Revenue is only included when the user has revenue permission.'),
            $definition('product_inventory_report', 'Read current product inventory quantities. Financial fields require the underlying inventory financial permission.'),
            $definition('employee_inventory_allocations', 'Read employee inventory allocations. Optional employee_id is an employee record ID.'),
            $definition('stock_requests_report', 'Read stock request statuses and references. No mutations are possible.'),
            $definition('customer_returns_report', 'Read customer return report. Financial refund fields are excluded.'),
            $definition('customer_return_refunds_report', 'Read authorized refund exposure report. Owner-only amounts remain unavailable to Admin unless separately authorized.'),
        ];
    }

    public function call(User $user, string $tool, array $input): array
    {
        abort_unless($this->assistantSettings->enabled() && $this->assistantAuthorization->allows($user, AiAssistantPermission::Use), 403);
        $allowed = match ($tool) {
            'sales_orders_report' => ['from', 'to', 'status', 'platform_id', 'product_id'],
            'web_sales_report' => ['from', 'to', 'status', 'channel', 'employee_id'],
            'product_inventory_report' => ['product_id', 'brand_id', 'warehouse_id'],
            'employee_inventory_allocations' => ['employee_id'],
            'stock_requests_report' => ['from', 'to', 'status', 'employee_id'],
            'customer_returns_report' => ['from', 'to', 'status', 'platform_id', 'product_id'],
            'customer_return_refunds_report' => ['from', 'to', 'status', 'platform_id', 'product_id'],
            default => throw ValidationException::withMessages(['tool' => 'Unknown reporting tool.']),
        };
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw ValidationException::withMessages(['tool' => 'Unsupported filters were requested.']);
        }
        $rules = [];
        foreach (['from', 'to'] as $field) {
            if (in_array($field, $allowed, true)) {
                $rules[$field] = ['sometimes', 'date_format:Y-m-d'];
            }
        }
        foreach (['employee_id', 'platform_id', 'product_id', 'brand_id', 'warehouse_id'] as $field) {
            if (in_array($field, $allowed, true)) {
                $rules[$field] = ['sometimes', 'integer', 'min:1'];
            }
        }
        if (in_array('status', $allowed, true)) {
            $rules['status'] = ['sometimes', 'string', 'max:40'];
        }
        if (in_array('channel', $allowed, true)) {
            $rules['channel'] = ['sometimes', 'string', 'max:30'];
        }
        Validator::make($input, $rules)->validate();
        AiAssistantAudit::query()->create(['user_id' => $user->id, 'event' => 'tool.requested', 'tool' => $tool, 'metadata' => ['filter_keys' => array_values(array_intersect(array_keys($input), $allowed))], 'ip_address' => request()->ip()]);
        $filters = $this->filters($input, $tool);
        $key = match ($tool) {
            'sales_orders_report' => 'orders',
            'web_sales_report' => 'web_sales.orders',
            'product_inventory_report' => 'current_inventory',
            'customer_returns_report' => 'returns',
            'customer_return_refunds_report' => 'refund_exposure',
            default => null,
        };
        if ($key !== null) {
            $report = $this->reports->run($user, $key, $filters, 30);
            $shipped = null;
            if ($tool === 'sales_orders_report') {
                $shipped = $this->reports->run($user, 'sales_summary', $filters, 30);
            }
            $currentSnapshot = $tool === 'product_inventory_report';

            return $this->validatedOutput(['tool' => $tool, 'title' => $report->title, 'period' => $currentSnapshot ? ['type' => 'snapshot', 'as_of' => now(config('app.timezone'))->toIso8601String(), 'timezone' => config('app.timezone')] : ['from' => $filters['from'], 'to' => $filters['to'], 'timezone' => config('app.timezone')], 'currency' => 'AED', 'filters' => $currentSnapshot ? array_intersect_key($filters, array_flip(['product_id', 'brand_id', 'warehouse_id'])) : $filters, 'total_rows' => $report->totalRows, 'summary' => $report->summary, 'rows' => $report->rows->all(), 'source' => $report->key, 'truncated' => $report->totalRows > count($report->rows), 'metric_note' => $tool === 'sales_orders_report' ? 'Order rows and order-line totals are not net sales. They do not combine VAT, refunds, cancellations, or fulfilment. Use row status to distinguish lifecycle state; limited rows are not full status counts.' : null, 'fulfilled_sales_summary' => $shipped === null ? null : ['source' => $shipped->key, 'metric_definition' => 'fulfilled units and selling value from the ERP sales summary; it is not a VAT- or refund-adjusted net figure', 'summary' => $shipped->summary, 'rows' => $shipped->rows->all()]]);
        }
        if ($tool === 'employee_inventory_allocations') {
            abort_unless($this->responsibilities->allows($user, ResponsibilityPermission::ViewAll)
                || $this->responsibilities->allows($user, ResponsibilityPermission::ViewOwn)
                || $this->responsibilities->allows($user, ResponsibilityPermission::ViewTeam), 403);
            $assignmentQuery = $this->responsibilityReads->assignmentsFor($user)->active()
                ->when(isset($input['employee_id']), fn ($q) => $q->where('employee_id', (int) $input['employee_id']));
            $totalRows = (clone $assignmentQuery)->count();
            $assignments = $assignmentQuery->limit(30)->get();

            return $this->validatedOutput(['tool' => $tool, 'period' => 'Current active allocations', 'timezone' => config('app.timezone'), 'currency' => 'AED', 'filters' => ['employee_id' => $input['employee_id'] ?? null], 'source' => 'authorized active responsibility assignments', 'total_rows' => $totalRows, 'truncated' => $totalRows > count($assignments), 'rows' => $assignments->map(fn ($assignment): array => [
                'reference' => $assignment->reference,
                'employee' => $assignment->employee?->name,
                'brand' => $assignment->brandScope?->brand?->name,
                'product' => $assignment->productScope?->product?->name,
                'category' => $assignment->categoryScope?->category?->name,
                'warehouse' => $assignment->warehouseScope?->warehouse?->name,
                'platform' => $assignment->platformScope?->platform?->name,
                'assigned_quantity' => $assignment->quantityScope?->assigned_quantity,
            ])->all()]);
        }
        if ($tool === 'stock_requests_report') {
            abort_unless($this->inventory->allows($user, InventoryPermission::ViewStockRequests), 403);
            $requestQuery = $this->stockRequests->visibleQuery($user)->from('stock_requests as sr')->leftJoin('employees as e', 'e.id', '=', 'sr.requested_by_employee_id')
                ->whereBetween('sr.created_at', [$filters['from'].' 00:00:00', $filters['to'].' 23:59:59'])
                ->when(isset($filters['status']), fn ($q) => $q->where('sr.status', $filters['status']))
                ->when(isset($filters['employee_id']), fn ($q) => $q->where('sr.requested_by_employee_id', $filters['employee_id']))
                ->select(['sr.reference', 'sr.status', 'sr.purpose', 'sr.created_at', 'e.name as requester'])->orderByDesc('sr.created_at');
            $totalRows = (clone $requestQuery)->count();
            $rows = $requestQuery->limit(30)->get();

            return $this->validatedOutput(['tool' => $tool, 'period' => ['from' => $filters['from'], 'to' => $filters['to'], 'timezone' => config('app.timezone')], 'currency' => 'AED', 'filters' => $filters, 'source' => 'stock_requests', 'total_rows' => $totalRows, 'truncated' => $totalRows > count($rows), 'rows' => $rows->map(fn ($row): array => $row instanceof Model ? $row->getAttributes() : (array) $row)->all()]);
        }
        throw ValidationException::withMessages(['tool' => 'Unknown reporting tool.']);
    }

    private function filters(array $input, string $tool): array
    {
        $timezone = config('app.timezone', 'Asia/Dubai');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $from = CarbonImmutable::parse($input['from'] ?? $today, $timezone);
        $to = CarbonImmutable::parse($input['to'] ?? $today, $timezone);
        if ($to->lt($from) || $from->diffInDays($to) > config('ai_assistant.max_date_range_days')) {
            throw ValidationException::withMessages(['period' => 'The report date range is invalid or too long.']);
        }
        $status = $input['status'] ?? null;
        $validStatuses = match ($tool) {
            'stock_requests_report' => array_map(fn (\BackedEnum $case): string => $case->value, StockRequestStatus::cases()),
            'customer_returns_report', 'customer_return_refunds_report' => array_map(fn (\BackedEnum $case): string => $case->value, CustomerReturnStatus::cases()),
            default => ['draft', 'confirmed', 'reserved', 'processing', 'fulfilled', 'cancelled', 'pending_review'],
        };
        if ($status !== null && ! in_array($status, $validStatuses, true)) {
            throw ValidationException::withMessages(['status' => 'The requested status filter is invalid.']);
        }
        $channel = $input['channel'] ?? null;
        if ($channel !== null && ! in_array($channel, array_map(fn (\BackedEnum $case): string => $case->value, WebSalesChannel::cases()), true)) {
            throw ValidationException::withMessages(['channel' => 'The requested sales channel is invalid.']);
        }

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => $status, 'channel' => $channel,
            'employee_id' => isset($input['employee_id']) ? (int) $input['employee_id'] : null,
            'platform_id' => isset($input['platform_id']) ? (int) $input['platform_id'] : null,
            'product_id' => isset($input['product_id']) ? (int) $input['product_id'] : null,
            'brand_id' => isset($input['brand_id']) ? (int) $input['brand_id'] : null,
            'warehouse_id' => isset($input['warehouse_id']) ? (int) $input['warehouse_id'] : null];
    }

    private function validatedOutput(array $result): array
    {
        foreach (['tool', 'period', 'currency', 'filters', 'source', 'rows'] as $key) {
            if (! array_key_exists($key, $result)) {
                throw new LogicException('AI reporting tool output is missing a required field.');
            }
        }
        if (! is_string($result['tool']) || ! is_string($result['currency']) || ! is_string($result['source'])
            || (! is_array($result['period']) && ! is_string($result['period']))
            || ! is_array($result['filters']) || ! is_array($result['rows']) || count($result['rows']) > 30
            || ! collect($result['rows'])->every(fn (mixed $row): bool => is_array($row))) {
            throw new LogicException('AI reporting tool output did not match the bounded row schema.');
        }

        return $result;
    }
}
