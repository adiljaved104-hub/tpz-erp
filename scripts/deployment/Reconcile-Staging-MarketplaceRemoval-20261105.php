<?php

/**
 * SINGLE-CASE, STAGING-ONLY, OWNER-APPROVED HISTORICAL RECONCILIATION.
 *
 * Case: Amazon UAE inventory #77, SKU TPZ-000016, allocation account #1.
 * Stock movement #244 received 5 and allocation event #61 allocated 5.
 * Stock movement #245 dispatched 5 for marketplace return-removal item #1
 * WITHOUT decrementing that allocation. In-transit transfer item #3's
 * transition #14 is distinct and MUST remain intact.
 *
 * This script is NOT a general-purpose reconciliation command.
 * Dry run: php scripts/deployment/Reconcile-Staging-MarketplaceRemoval-20261105.php
 * Apply only after VERIFIED DATABASE BACKUP, approved deployment SHA and
 * exact-match dry run. Run using the guarded Staging SSH procedure.
 *
 * No physical stock, transfer rows or immutable historical events are
 * modified. One materialized allocation balance is decremented and one
 * immutable correction event is appended inside a single DB transaction.
 */
declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$apply = ($argv[1] ?? '--dry-run') === '--apply';
if (! in_array(($argv[1] ?? '--dry-run'), ['--dry-run', '--apply'], true) || count($argv) > 2) {
    throw new RuntimeException('Usage: php .../Reconcile-Staging-MarketplaceRemoval-20261105.php [--dry-run|--apply]');
}
if (DB::getDriverName() !== 'mysql'
    || DB::connection()->getConfig('database') !== 'tpz_erp_staging'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'tpz_erp_staging') {
    throw new RuntimeException('Wrong database. STAGING ONLY. STOP.');
}

if ($apply) {
    if (getenv('TPZ_OWNER_APPROVED_CASE') !== 'OWNER_APPROVED_2026_11_05_TRF_000003') {
        throw new RuntimeException('Owner-approved case confirmation is missing. STOP.');
    }

    $backup = (string) getenv('TPZ_VERIFIED_BACKUP');
    $backupRoot = realpath(storage_path('app/backups'));
    $realBackup = $backup === '' ? false : realpath($backup);
    if (! is_string($backupRoot) || ! is_string($realBackup)
        || ! str_starts_with($realBackup, $backupRoot.DIRECTORY_SEPARATOR)
        || ! is_dir($realBackup)) {
        throw new RuntimeException('Approved backup path is missing or outside protected storage. STOP.');
    }
    $manifestPath = $realBackup.'/manifest.json';
    if (! is_file($manifestPath) || time() - filemtime($manifestPath) > 3600) {
        throw new RuntimeException('A fresh backup manifest is required (within 60 minutes). STOP.');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['status'] ?? null) !== 'successful'
        || ($manifest['database_driver'] ?? null) !== 'mysql') {
        throw new RuntimeException('Backup manifest status/database mismatch. STOP.');
    }
    $database = collect($manifest['files'] ?? [])->first(fn ($file): bool => ($file['kind'] ?? null) === 'database');
    if (! is_array($database)
        || ($database['filename'] ?? null) !== 'database.sql'
        || ! preg_match('/^[a-f0-9]{64}$/', (string) ($database['sha256'] ?? ''))
        || ! is_file($realBackup.'/database.sql')
        || ! hash_equals((string) $database['sha256'], hash_file('sha256', $realBackup.'/database.sql'))) {
        throw new RuntimeException('Backup database SHA-256 verification failed. STOP.');
    }
    echo "VERIFIED_FRESH_DATABASE_BACKUP=PASS\n";
}

$caseEvent = 'marketplace_removal_legacy_correction';
$caseMarker = '2026-11-05-TRF-2026-000003-MRV-ITEM-1';

$report = DB::transaction(function () use ($apply, $caseEvent, $caseMarker): array {
    $inventory = DB::table('product_inventories as i')
        ->join('products as p', 'p.id', '=', 'i.product_id')
        ->where('i.id', 77)
        ->select(['i.*', 'p.sku'])
        ->when($apply, fn ($q) => $q->lockForUpdate())
        ->first();

    if (! $inventory || $inventory->sku !== 'TPZ-000016'
        || (int) $inventory->warehouse_id !== 2 || (int) $inventory->available_quantity !== 0
        || (int) $inventory->reserved_quantity !== 0) {
        throw new RuntimeException('Target inventory identity / physical balance changed. STOP.');
    }

    $balances = DB::table('inventory_allocation_balances')
        ->where('product_inventory_id', 77)->orderBy('id')
        ->when($apply, fn ($q) => $q->lockForUpdate())->get();
    $positive = $balances->filter(fn ($b) => (int) $b->allocated_quantity > 0);
    $total = (int) $balances->sum('allocated_quantity');
    $reserved = (int) $balances->sum('reserved_quantity');

    $previousCorrection = DB::table('inventory_allocation_events')
        ->where('product_inventory_id', 77)->where('event_type', $caseEvent)
        ->where('source_type', 'marketplace_return_removal_item')->where('source_id', 1)
        ->exists();
    if ($previousCorrection) {
        if ($total !== 0 || $reserved !== 0) {
            throw new RuntimeException('Prior correction exists but balances differ. STOP.');
        }
        return ['status' => 'ALREADY_CORRECTED', 'before' => 0, 'after' => 0];
    }

    if ($total !== 5 || $reserved !== 0 || $positive->count() !== 1
        || (int) $positive->first()->account_id !== 1
        || (int) $positive->first()->allocated_quantity !== 5) {
        throw new RuntimeException('Expected obsolete allocation account/amount not present. STOP.');
    }

    $transfer = DB::table('stock_transfers')
        ->where('reference', 'TRF-2026-000003')->first();
    $item = DB::table('stock_transfer_items')
        ->where('id', 3)->first();
    if (! $transfer || ! $item
        || (int) $item->stock_transfer_id !== (int) $transfer->id
        || (int) $item->product_id !== (int) $inventory->product_id
        || (int) $item->quantity !== 5 || (int) $item->dispatched_quantity !== 5
        || (int) $item->received_quantity !== 0
        || (int) $item->returned_quantity !== 0 || (int) $item->lost_quantity !== 0) {
        throw new RuntimeException('Current in-transit transfer evidence changed. STOP.');
    }

    $transition = DB::table('inventory_allocation_transition_lines')
        ->where('id', 14)->first();
    if (! $transition || $transition->context_type !== 'transfer'
        || (int) $transition->context_id !== 3 || (int) $transition->account_id !== 1
        || (int) $transition->remaining_quantity !== 5 || (int) $transition->quantity !== 5
        || $transition->status !== 'dispatched') {
        throw new RuntimeException('In-transit allocation hold changed. STOP.');
    }

    $receipt = DB::table('stock_movements')->where('id', 244)->first();
    $removal = DB::table('stock_movements')->where('id', 245)->first();
    $originalAllocation = DB::table('inventory_allocation_events')->where('id', 61)->first();
    $removalItem = DB::table('marketplace_return_removal_items')->where('id', 1)->first();

    if (! $receipt || ! $removal || ! $originalAllocation || ! $removalItem
        || (int) $receipt->product_inventory_id !== 77
        || $receipt->movement_type !== 'transfer_receipt'
        || (int) $receipt->quantity !== 5 || (int) $receipt->available_delta !== 5
        || $receipt->source_type !== 'stock_transfer_item' || (int) $receipt->source_id !== 2
        || (int) $removal->product_inventory_id !== 77
        || $removal->movement_type !== 'marketplace_return_removal_dispatch'
        || (int) $removal->quantity !== 5 || (int) $removal->available_delta !== -5
        || (int) $removal->available_before !== 5 || (int) $removal->available_after !== 0
        || $removal->source_type !== 'marketplace_return_removal_item' || (int) $removal->source_id !== 1
        || (int) $originalAllocation->product_inventory_id !== 77
        || $originalAllocation->event_type !== 'warehouse_transfer_receipt'
        || (int) $originalAllocation->quantity !== 5 || (int) $originalAllocation->to_account_id !== 1
        || (int) $removalItem->source_product_inventory_id !== 77
        || $removalItem->source_stock_type !== 'sellable'
        || (int) $removalItem->quantity !== 5 || (int) $removalItem->dispatched_quantity !== 5) {
        throw new RuntimeException('Historical movement and removal provenance mismatch. STOP.');
    }

    if ((int) DB::table('stock_movements')->where('product_inventory_id', 77)->max('id') !== 245
        || (int) DB::table('inventory_allocation_events')->where('product_inventory_id', 77)->max('id') !== 61) {
        throw new RuntimeException('Inventory or allocation history advanced since the forensic audit. STOP.');
    }

    $overallocated = DB::table('product_inventories as pi')
        ->leftJoin('inventory_allocation_balances as ab', 'ab.product_inventory_id', '=', 'pi.id')
        ->select('pi.id')->groupBy('pi.id', 'pi.available_quantity')
        ->havingRaw('COALESCE(SUM(ab.allocated_quantity), 0) > pi.available_quantity')
        ->pluck('pi.id')->map(fn ($id): int => (int) $id)->all();
    if ($overallocated !== [77]) {
        throw new RuntimeException('Inventory-wide over-allocation scope changed; no write allowed. STOP.');
    }

    if (! $apply) {
        return ['status' => 'DRY_RUN_APPROVED_SCOPE', 'before' => 5, 'after' => 0];
    }

    $updated = DB::table('inventory_allocation_balances')
        ->where('product_inventory_id', 77)->where('account_id', 1)
        ->where('allocated_quantity', 5)->where('reserved_quantity', 0)
        ->update(['allocated_quantity' => 0, 'updated_at' => now()]);
    if ($updated !== 1) {
        throw new RuntimeException('Exact allocation balance conditional update failed. ROLLBACK.');
    }

    DB::table('inventory_allocation_events')->insert([
        'event_key' => (string) Str::uuid(),
        'event_type' => $caseEvent,
        'product_inventory_id' => 77,
        'from_account_id' => 1,
        'to_account_id' => null,
        'quantity' => 5,
        'source_type' => 'marketplace_return_removal_item',
        'source_id' => 1,
        'performed_by_user_id' => null,
        'reason' => 'Owner-approved historical allocation-only correction for physical marketplace removal movement #245; no physical stock adjustment.',
        'metadata' => json_encode([
            'case' => $caseMarker,
            'approval' => 'TPZ ERP Owner explicitly approved Staging-only reconciliation on 2026-11-05',
            'execution' => 'Guarded SSH maintenance by tpzadmin; no web user impersonation',
            'root_cause' => 'Legacy sellable marketplace removal did not debit the allocation ledger',
            'receipt_movement_id' => 244,
            'physical_removal_movement_id' => 245,
            'original_allocation_event_id' => 61,
            'transfer_item_id' => 3,
            'held_transition_id' => 14,
            'physical_change' => 0,
        ], JSON_THROW_ON_ERROR),
        'created_at' => now(),
    ]);

    $physicalAfter = (int) DB::table('product_inventories')->where('id', 77)->value('available_quantity');
    $allocationAfter = (int) DB::table('inventory_allocation_balances')
        ->where('product_inventory_id', 77)->sum('allocated_quantity');
    $heldAfter = (int) DB::table('inventory_allocation_transition_lines')->where('id', 14)->value('remaining_quantity');
    $events = (int) DB::table('inventory_allocation_events')
        ->where('product_inventory_id', 77)->where('event_type', $caseEvent)
        ->where('source_type', 'marketplace_return_removal_item')->where('source_id', 1)->count();

    if ($physicalAfter !== 0 || $allocationAfter !== 0 || $heldAfter !== 5 || $events !== 1) {
        throw new RuntimeException('Post-correction invariant check failed. ROLLBACK.');
    }

    return ['status' => 'APPLIED', 'before' => 5, 'after' => 0];
}, 1);

echo "STAGING_RECONCILIATION=".$report['status']."\n";
echo "ALLOCATED_BEFORE=".$report['before']."\n";
echo "ALLOCATED_AFTER=".$report['after']."\n";
echo "PHYSICAL_STOCK_UNCHANGED=0\n";
echo "IN_TRANSIT_HOLD_UNCHANGED=5\n";
echo "APPEND_ONLY_EVENT=".(($report['status'] === 'APPLIED') ? 'CREATED' : 'NOT_REQUIRED')."\n";
echo "CORRECTION_ATOMICITY=PASS\n";
