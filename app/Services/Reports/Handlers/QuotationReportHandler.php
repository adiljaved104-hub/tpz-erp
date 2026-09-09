<?php

namespace App\Services\Reports\Handlers;

use App\Contracts\Reports\ReportHandler;
use App\DTOs\Reports\ReportDefinition;
use App\DTOs\Reports\ReportResult;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Reports\ReportQueryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationReportHandler implements ReportHandler
{
    public function __construct(private readonly QuotationAuthorization $authorization) {}

    public function run(User $user, ReportDefinition $definition, array $f, int $limit): ReportResult
    {
        $ids = $this->authorization->scope(Quotation::query(), $user)->select('quotations.id');
        $base = DB::table('quotations as q')->leftJoin('employees as e', 'e.id', '=', 'q.salesperson_employee_id')
            ->whereIn('q.id', $ids)->whereBetween('q.quotation_date', [$f['from'].' 00:00:00', $f['to'].' 23:59:59'])
            ->when($f['status'], fn (Builder $x) => $x->where('q.status', $f['status']))
            ->when($f['employee_id'], fn (Builder $x) => $x->where('q.salesperson_employee_id', $f['employee_id']))
            ->when($f['product_id'], fn (Builder $x) => $x->whereExists(fn ($s) => $s->selectRaw('1')->from('quotation_items as qi')->whereColumn('qi.quotation_id', 'q.id')
                ->where(fn ($product) => $product->where('qi.product_id', $f['product_id'])->orWhere('qi.materialized_product_id', $f['product_id']))));
        $periodExpression = DB::getDriverName() === 'sqlite' ? "strftime('%Y-%m', q.quotation_date)" : "DATE_FORMAT(q.quotation_date, '%Y-%m')";
        [$query,$columns,$sum] = match ($definition->key) {
            'sales.quotations' => $this->detail($base),
            'sales.quotations_by_status' => $this->group($base, 'q.status', 'status', 'Status'),
            'sales.quotations_by_employee' => $this->group($base, 'e.name', 'employee', 'Employee'),
            'sales.accepted_converted_quotations' => $this->detail($base->whereIn('q.status', [QuotationStatus::Accepted->value, QuotationStatus::Converted->value])),
            'sales.quotation_value_summary' => $this->group($base, $periodExpression, 'period', 'Period'),
        };
        $total = (clone $query)->reorder()->count();
        if ($limit > ReportQueryService::PREVIEW_LIMIT && $total > ReportQueryService::EXPORT_LIMIT) {
            throw ValidationException::withMessages(['export' => 'Narrow the filters before exporting more than '.number_format(ReportQueryService::EXPORT_LIMIT).' rows.']);
        }
        $rows = (clone $query)->limit(min($limit, ReportQueryService::EXPORT_LIMIT))->get()->map(function ($row): array {
            $data = (array) $row;
            foreach (['document_type', 'status'] as $key) {
                if (filled($data[$key] ?? null)) {
                    $data[$key] = str((string) $data[$key])->replace('_', ' ')->headline()->toString();
                }
            }

            return $data;
        });

        return new ReportResult($definition->key, $definition->title, $columns, $rows, ['Rows' => $total, 'Quotation Value' => (float) DB::query()->fromSub((clone $query)->reorder(), 'qr')->sum($sum)], $f, $total);
    }

    private function detail(Builder $q): array
    {
        $q->leftJoin('orders as o', 'o.id', '=', 'q.order_id')->leftJoin('tax_invoices as i', 'i.id', '=', 'q.tax_invoice_id')
            ->select(['q.reference', 'q.document_type', 'q.quotation_date', 'q.valid_until', 'q.customer_name', 'q.customer_company', 'q.status', 'q.grand_total', 'e.name as salesperson', 'o.reference as order_reference', 'i.invoice_number'])->orderByDesc('q.quotation_date');

        return [$q, $this->columns(['reference' => 'Reference', 'document_type' => 'Type', 'quotation_date' => 'Date', 'valid_until' => 'Valid Until', 'customer_name' => 'Customer', 'customer_company' => 'Company', 'status' => 'Status', 'grand_total' => 'Value', 'salesperson' => 'Salesperson', 'order_reference' => 'Order', 'invoice_number' => 'Invoice'], ['grand_total']), 'grand_total'];
    }

    private function group(Builder $q, string $expression, string $alias, string $label): array
    {
        $q->selectRaw("{$expression} as {$alias}, COUNT(*) as quotation_count, SUM(q.grand_total) as quotation_value")->groupByRaw($expression)->orderByDesc('quotation_value');

        return [$q, $this->columns([$alias => $label, 'quotation_count' => 'Quotations', 'quotation_value' => 'Value'], ['quotation_count', 'quotation_value']), 'quotation_value'];
    }

    private function columns(array $labels, array $numeric = []): array
    {
        return collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label] + (in_array($key, $numeric, true) ? ['type' => $key === 'quotation_count' ? 'number' : 'money'] : []))->values()->all();
    }
}
