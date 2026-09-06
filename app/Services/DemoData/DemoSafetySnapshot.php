<?php

namespace App\Services\DemoData;

use App\Enums\EmployeeRole;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class DemoSafetySnapshot
{
    private const TABLES = [
        'users', 'employees', 'teams', 'product_brands', 'product_categories',
        'marketplace_platforms', 'suppliers', 'warehouses', 'products', 'components',
        'product_inventories', 'stock_movements', 'inventory_reservations', 'purchases',
        'purchase_items', 'purchase_receipts', 'purchase_receipt_items', 'orders',
        'order_items', 'order_fulfillments', 'order_fulfillment_items', 'quotations',
        'quotation_items', 'customer_returns', 'customer_return_items', 'safet_claims',
        'warranty_repairs', 'complaints', 'tasks', 'task_assignments', 'expenses',
        'responsibility_assignments', 'employee_permission_overrides',
    ];

    /** @param array<string, array<string, string>> $fingerprints */
    private function __construct(
        private readonly int $ownerId,
        private readonly string $ownerFingerprint,
        private readonly array $fingerprints,
    ) {}

    public static function capture(): self
    {
        $owner = User::query()->whereHas('employee', fn ($query) => $query->where('role', EmployeeRole::Owner))->with('employee')->firstOrFail();
        $fingerprints = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }
            $fingerprints[$table] = self::nonDemoQuery($table)->orderBy('id')->get()->mapWithKeys(
                fn (object $row): array => [(string) $row->id => self::hash((array) $row)],
            )->all();
        }

        return new self($owner->id, self::hash([
            'user' => $owner->only(['id', 'name', 'email', 'password', 'email_two_factor_enabled_at']),
            'employee' => $owner->employee?->only(['id', 'user_id', 'employee_id', 'name', 'email', 'team_id', 'designation', 'role', 'status']),
        ]), $fingerprints);
    }

    public function assertPreserved(): void
    {
        $owner = User::query()->with('employee')->findOrFail($this->ownerId);
        $ownerFingerprint = self::hash([
            'user' => $owner->only(['id', 'name', 'email', 'password', 'email_two_factor_enabled_at']),
            'employee' => $owner->employee?->only(['id', 'user_id', 'employee_id', 'name', 'email', 'team_id', 'designation', 'role', 'status']),
        ]);
        if (! hash_equals($this->ownerFingerprint, $ownerFingerprint)) {
            throw new RuntimeException('Owner safety fingerprint changed during demo generation.');
        }

        foreach ($this->fingerprints as $table => $expected) {
            if ($expected === []) {
                continue;
            }
            $actual = DB::table($table)->whereIn('id', array_keys($expected))->orderBy('id')->get()->mapWithKeys(
                fn (object $row): array => [(string) $row->id => self::hash((array) $row)],
            )->all();
            if ($actual !== $expected) {
                throw new RuntimeException("Existing non-demo rows changed in [{$table}].");
            }
        }
    }

    private static function hash(array $value): string
    {
        ksort($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private static function nonDemoQuery(string $table): Builder
    {
        $query = DB::table($table);
        $marker = (string) config('demo.marker', '[DEMO:staging-v1]').'%';
        $emails = app(DemoIdentity::class)->emails();

        return match ($table) {
            'users' => $query->whereNotIn('email', $emails),
            'employees' => $query->whereNotIn('email', $emails),
            'teams' => $query->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', 'not like', $marker)),
            'suppliers' => $query->where(fn (Builder $q) => $q->whereNull('notes')->orWhere('notes', 'not like', $marker)),
            'marketplace_platforms' => $query->where('code', 'not like', 'demo_%'),
            'products' => $query->where(fn (Builder $q) => $q->whereNull('description')->orWhere('description', 'not like', $marker)),
            'components' => $query->where('specification', 'not like', $marker),
            'product_inventories', 'stock_movements', 'inventory_reservations' => $query->whereNotIn('product_id', self::demoIds('products', 'description', $marker)),
            'purchases' => $query->where(fn (Builder $q) => $q->whereNull('external_accounting_reference')->orWhere('external_accounting_reference', 'not like', 'DEMO-PO-v1-%')),
            'purchase_items', 'purchase_receipts' => $query->whereNotIn('purchase_id', self::demoIds('purchases', 'external_accounting_reference', 'DEMO-PO-v1-%')),
            'purchase_receipt_items' => $query->whereNotIn('purchase_receipt_id', DB::table('purchase_receipts')->select('id')->whereIn('purchase_id', self::demoIds('purchases', 'external_accounting_reference', 'DEMO-PO-v1-%'))),
            'orders' => $query->where(fn (Builder $q) => $q->whereNull('notes')->orWhere('notes', 'not like', '%'.config('demo.marker').'%')),
            'order_items' => $query->whereNotIn('order_id', self::demoOrderIds()),
            'order_fulfillments' => $query->whereNotIn('order_id', self::demoOrderIds()),
            'order_fulfillment_items' => $query->whereNotIn('order_fulfillment_id', DB::table('order_fulfillments')->select('id')->whereIn('order_id', self::demoOrderIds())),
            'quotations' => $query->where(fn (Builder $q) => $q->whereNull('external_reference')->orWhere('external_reference', 'not like', 'DEMO-QUO-v1-%')),
            'quotation_items' => $query->whereNotIn('quotation_id', self::demoIds('quotations', 'external_reference', 'DEMO-QUO-v1-%')),
            'customer_returns' => $query->where(fn (Builder $q) => $q->whereNull('notes')->orWhere('notes', 'not like', $marker)),
            'customer_return_items' => $query->whereNotIn('customer_return_id', self::demoIds('customer_returns', 'notes', $marker)),
            'safet_claims' => $query->whereNotIn('customer_return_id', self::demoIds('customer_returns', 'notes', $marker)),
            'warranty_repairs' => $query->where(fn (Builder $q) => $q->whereNull('notes')->orWhere('notes', 'not like', $marker)),
            'complaints' => $query->where('description', 'not like', $marker),
            'tasks' => $query->where('title', 'not like', $marker),
            'task_assignments' => $query->whereNotIn('task_id', self::demoIds('tasks', 'title', $marker)),
            'expenses' => $query->where(fn (Builder $q) => $q->whereNull('reference_note')->orWhere('reference_note', 'not like', $marker)),
            'responsibility_assignments', 'employee_permission_overrides' => $query->whereNotIn('employee_id', DB::table('employees')->select('id')->whereIn('email', $emails)),
            default => $query,
        };
    }

    private static function demoIds(string $table, string $column, string $pattern): Builder
    {
        return DB::table($table)->select('id')->where($column, 'like', $pattern);
    }

    private static function demoOrderIds(): Builder
    {
        return DB::table('orders')->select('id')->where('notes', 'like', '%'.config('demo.marker').'%');
    }
}
