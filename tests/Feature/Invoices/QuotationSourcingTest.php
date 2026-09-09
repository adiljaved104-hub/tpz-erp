<?php

namespace Tests\Feature\Invoices;

use App\Actions\Orders\FulfillOrder;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationType;
use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\CompanyProfile;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Quotation;
use App\Models\QuotationSourcingPosting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\EmployeePermissionOverrideService;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Orders\OrderUpgradeService;
use App\Services\Orders\WebSalesReadService;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationService;
use App\Services\Quotations\QuotationSourcingService;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QuotationSourcingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Warehouse $warehouse;

    private Product $product;

    private ProductInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->actor(EmployeeRole::Owner);
        CompanyProfile::query()->create(['id' => 1, 'company_name_en' => 'Quotation Test Company', 'trn' => '100000000000001', 'address_en' => 'Dubai', 'updated_by_user_id' => $this->owner->id]);
        $this->warehouse = Warehouse::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => '200.00']);
        $this->inventory = ProductInventory::factory()->for($this->product)->for($this->warehouse)->create(['available_quantity' => 1, 'reserved_quantity' => 0, 'damaged_quantity' => 0, 'average_cost' => '100.0000']);
    }

    public function test_partial_sourcing_posts_real_stock_then_reserves_full_quantity_and_snapshots_cogs(): void
    {
        $quote = $this->accepted();
        $this->assertDatabaseCount('stock_movements', 0);
        $order = $this->convert($quote);
        $posting = QuotationSourcingPosting::query()->sole();
        $this->assertSame(2, $posting->quantity);
        $this->assertSame('400.0000', $posting->total_cost);
        $this->assertSame('166.6667', $this->inventory->refresh()->average_cost);
        $this->assertSame(3, $this->inventory->available_quantity);
        $this->assertSame(3, $this->inventory->reserved_quantity);
        $this->assertSame('200.00', $order->items->sole()->selling_price);
        $this->assertSame($quote->items->sole()->id, $order->items->sole()->quotation_item_id);
        $this->assertSame($posting->id, $posting->movement->source_id);
        $this->assertSame('quotation_sourcing_receipt', $posting->movement->movement_type->value);
        $this->assertDatabaseCount('purchases', 0);
        $this->assertSame($order->id, $quote->refresh()->order_id);
        app(FulfillOrder::class)->handle($order, (string) Str::uuid(), $this->owner);
        $line = $order->items->sole()->fulfillmentItem;
        $this->assertEqualsWithDelta(500.0001, (float) $line->cogs_total, .0001);
        $read = app(WebSalesReadService::class)->orders($this->owner)->whereKey($order->id)->firstOrFail();
        $this->assertEqualsWithDelta(99.9999, (float) $read->gross_profit, .0001);
        $this->assertSame(0, $this->inventory->refresh()->available_quantity);
    }

    public function test_invalid_order_locations_cannot_be_saved_through_quotation_services(): void
    {
        $service = app(QuotationService::class);
        $draft = $service->create($this->data(), $this->owner);
        $before = $this->fingerprint();
        $originalItems = $draft->items->modelKeys();
        $locations = [
            Warehouse::factory()->create(['location_type' => InventoryLocationType::Transit]),
            Warehouse::factory()->create([
                'location_type' => InventoryLocationType::MarketplaceFulfilment,
                'marketplace_platform_id' => MarketplacePlatform::factory()->create()->id,
            ]),
            Warehouse::factory()->inactive()->create(),
        ];
        foreach ($locations as $location) {
            foreach (['create', 'update'] as $operation) {
                $data = [...$this->data(), 'warehouse_id' => $location->id];
                try {
                    $operation === 'create'
                        ? $service->create($data, $this->owner)
                        : $service->updateDraft($draft, $data, $this->owner);
                    $this->fail('Invalid fulfilment warehouse was saved.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('warehouse_id', $exception->errors());
                    $this->assertSame($this->warehouse->id, $draft->refresh()->warehouse_id);
                    $this->assertSame($originalItems, $draft->items->modelKeys());
                    $this->assertDatabaseCount('quotations', 1);
                    $this->assertDatabaseCount('quotation_item_sourcing_instructions', 1);
                    $this->assertSame($before, $this->fingerprint());
                }
            }
        }
    }

    public function test_quotation_selector_reuses_platformless_order_locations(): void
    {
        $transit = Warehouse::factory()->create(['location_type' => InventoryLocationType::Transit]);
        $marketplace = Warehouse::factory()->create([
            'location_type' => InventoryLocationType::MarketplaceFulfilment,
            'marketplace_platform_id' => MarketplacePlatform::factory()->create()->id,
        ]);
        $inactive = Warehouse::factory()->inactive()->create();
        Livewire::actingAs($this->owner)->test(CreateQuotation::class)
            ->assertFormFieldExists('warehouse_id', function (Select $field) use ($transit, $marketplace, $inactive): bool {
                $options = $field->getOptions();
                $this->assertSame(app(OrderFulfillmentLocationService::class)->options(null), $options);
                $this->assertArrayHasKey($this->warehouse->id, $options);
                foreach ([$transit, $marketplace, $inactive] as $excluded) {
                    $this->assertArrayNotHasKey($excluded->id, $options);
                }

                return true;
            });
    }

    public function test_valid_warehouse_update_and_legacy_null_warehouse_conversion_remain_supported(): void
    {
        $service = app(QuotationService::class);
        $draft = $service->create($this->data(), $this->owner);
        $draft = $service->updateDraft($draft, $this->data(), $this->owner);
        $this->assertSame($this->warehouse->id, $draft->warehouse_id);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('inventory_reservations', 0);

        // Legacy drafts may still have no stored warehouse and no sourcing instruction.
        $legacyData = [...$this->data(['source_inventory' => false, 'purchase_unit_cost' => null]), 'warehouse_id' => null];
        $draft = $service->updateDraft($draft, $legacyData, $this->owner);
        $this->assertNull($draft->warehouse_id);
        $this->inventory->update(['available_quantity' => 3]);
        $service->transition($draft, QuotationStatus::Sent, $this->owner);
        $accepted = $service->transition($draft->fresh(), QuotationStatus::Accepted, $this->owner);
        $order = $this->convert($accepted);
        $this->assertSame($this->warehouse->id, $order->warehouse_id);
        $this->assertDatabaseCount('quotation_sourcing_postings', 0);
        $this->assertSame(3, $this->inventory->refresh()->reserved_quantity);
    }

    public function test_full_sourcing_and_retry_do_not_duplicate_inventory_or_order(): void
    {
        $this->inventory->update(['available_quantity' => 0, 'average_cost' => null]);
        $quote = $this->accepted();
        $order = $this->convert($quote);
        $before = $this->fingerprint();
        $again = $this->convert($quote->fresh());
        $this->assertSame($order->id, $again->id);
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame(3, QuotationSourcingPosting::query()->sole()->quantity);
        $this->assertSame('200.0000', $this->inventory->refresh()->average_cost);
    }

    public function test_sufficient_stock_requires_no_cost_and_posts_no_receipt(): void
    {
        $this->inventory->update(['available_quantity' => 5]);
        $quote = $this->accepted(['source_inventory' => true, 'purchase_unit_cost' => null]);
        $this->convert($quote);
        $this->assertDatabaseCount('quotation_item_sourcing_instructions', 0);
        $this->assertDatabaseCount('quotation_sourcing_postings', 0);
        $this->assertSame(5, $this->inventory->refresh()->available_quantity);
        $this->assertSame('100.0000', $this->inventory->average_cost);
    }

    public function test_actual_shortfall_is_recalculated_not_taken_from_advisory_snapshot(): void
    {
        $quote = $this->accepted();
        $this->assertSame(2, $quote->items->sole()->sourcingInstruction->planned_source_quantity_snapshot);
        $this->inventory->update(['available_quantity' => 2]);
        $this->convert($quote);
        $this->assertSame(1, QuotationSourcingPosting::query()->sole()->quantity);
        $this->assertSame('133.3333', $this->inventory->refresh()->average_cost);
    }

    public function test_new_stock_can_eliminate_sourcing_completely(): void
    {
        $quote = $this->accepted();
        $this->inventory->update(['available_quantity' => 3]);
        $this->convert($quote);
        $this->assertDatabaseCount('quotation_sourcing_postings', 0);
    }

    public function test_missing_or_invalid_cost_is_rejected_without_partial_quotation(): void
    {
        foreach ([null, '0', '-1', 'abc', '1.00001'] as $cost) {
            try {
                $this->accepted(['purchase_unit_cost' => $cost]);
                $this->fail('Invalid cost accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('quotations', 0);
                $this->assertDatabaseCount('quotation_item_sourcing_instructions', 0);
            }
        }
    }

    public function test_sourcing_permissions_default_to_owner_admin_only_and_direct_submission_is_denied(): void
    {
        $auth = app(QuotationAuthorization::class);
        foreach (EmployeeRole::cases() as $role) {
            $actor = $role === EmployeeRole::Owner ? $this->owner : $this->actor($role);
            foreach ([QuotationPermission::SourceInventory, QuotationPermission::ViewSourceCost] as $permission) {
                $this->assertSame(in_array($role, [EmployeeRole::Owner, EmployeeRole::Admin], true), $auth->allows($actor, $permission));
            }
        }
        $staff = $this->actor(EmployeeRole::Staff);
        $this->expectException(AuthorizationException::class);
        app(QuotationService::class)->create($this->data(), $staff);
    }

    public function test_unauthorized_cost_view_issues_no_sourcing_cost_query(): void
    {
        $quote = $this->accepted();
        $staff = $this->actor(EmployeeRole::Staff);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertSame([], app(QuotationSourcingService::class)->formInstructions($quote, $staff));
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('quotation_item_sourcing_instructions', $queries);
        $this->assertStringNotContainsString('purchase_unit_cost', json_encode($quote->toArray()));
    }

    public function test_failure_after_receipt_before_reservations_rolls_back_all_business_changes(): void
    {
        $quote = $this->accepted();
        $before = $this->fingerprint();
        $this->mock(OrderUpgradeService::class, function ($mock): void {
            $mock->shouldReceive('assertInstallStock')->once()->andReturnUsing(function (): void {
                $this->assertDatabaseCount('quotation_sourcing_postings', 1);
                $this->assertDatabaseCount('stock_movements', 1);
                $this->assertDatabaseCount('orders', 1);
                throw ValidationException::withMessages(['items' => 'Upgrade component stock unavailable.']);
            });
        });
        try {
            $this->convert($quote);
            $this->fail('Expected rollback.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->fingerprint());
            $this->assertSame(QuotationStatus::Accepted, $quote->refresh()->status);
            $this->assertNull($quote->order_id);
        }
    }

    public function test_posting_and_stock_movement_remain_immutable(): void
    {
        $this->convert($this->accepted());
        $posting = QuotationSourcingPosting::query()->sole();
        foreach ([fn () => $posting->update(['quantity' => 99]), fn () => $posting->delete(), fn () => $posting->movement->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('Immutable evidence was changed.');
            } catch (\LogicException|ImmutableInventoryRecordException) {
                $this->assertDatabaseCount('quotation_sourcing_postings', 1);
            }
        }
    }

    public function test_failure_after_base_reservation_rolls_back_receipt_and_reservation_together(): void
    {
        $quote = $this->accepted();
        $before = $this->fingerprint();
        $realInventory = app(InventoryService::class);
        $this->mock(InventoryService::class, function ($mock) use ($realInventory): void {
            $mock->shouldReceive('receiveQuotationSourcing')->once()->andReturnUsing(fn (...$args) => $realInventory->receiveQuotationSourcing(...$args));
            $mock->shouldReceive('reserveOrderItem')->once()->andReturnUsing(function (...$args) use ($realInventory): void {
                $realInventory->reserveOrderItem(...$args);
                $this->assertDatabaseCount('inventory_reservations', 1);
                $this->assertDatabaseCount('stock_movements', 2);
                throw ValidationException::withMessages(['items' => 'Simulated final reservation failure.']);
            });
        });
        try {
            $this->convert($quote);
            $this->fail('Expected rollback after reservation.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->fingerprint());
            $this->assertSame(QuotationStatus::Accepted, $quote->refresh()->status);
            $this->assertNull($quote->order_id);
        }
    }

    public function test_invoice_only_conversion_never_sources_inventory_or_exports_internal_cost(): void
    {
        $quote = $this->accepted(['source_note' => 'INTERNAL-SUPPLIER-NOTE']);
        $before = $this->fingerprint();
        $invoice = app(QuotationConversionService::class)->toInvoice($quote, $this->owner);
        $this->assertSame($before, $this->fingerprint());
        $this->assertStringNotContainsString('INTERNAL-SUPPLIER-NOTE', json_encode($invoice->toArray()));
        $response = $this->actingAs($this->owner)->get(route('quotations.pdf', ['quotation' => $quote, 'print' => 1]));
        $response->assertOk()->assertDontSee('INTERNAL-SUPPLIER-NOTE')->assertDontSee('Purchase Cost');
    }

    public function test_multiple_repeater_lines_keep_the_correct_source_cost_and_explicit_order_link(): void
    {
        $second = Product::factory()->create();
        $data = $this->data();
        $data['items'] = ['first-uuid' => $data['items'][0], 'second-uuid' => [...$data['items'][0], 'product_id' => $second->id, 'quantity' => 2, 'purchase_unit_cost' => '80.0000']];
        $service = app(QuotationService::class);
        $quote = $service->create($data, $this->owner);
        $service->transition($quote, QuotationStatus::Sent, $this->owner);
        $quote = $service->transition($quote->fresh(), QuotationStatus::Accepted, $this->owner)->load('items');
        $order = $this->convert($quote);
        $this->assertCount(2, $order->items);
        $this->assertSame([2, 2], QuotationSourcingPosting::query()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(['200.0000', '80.0000'], QuotationSourcingPosting::query()->orderBy('id')->get()->pluck('purchase_unit_cost')->all());
        $this->assertSame($quote->items->modelKeys(), $order->items->pluck('quotation_item_id')->all());
        $this->assertDatabaseCount('inventory_reservations', 2);
    }

    public function test_references_are_allocated_before_the_outer_business_transaction(): void
    {
        $quote = $this->accepted();
        $baseline = DB::transactionLevel();
        $referenceWrites = 0;
        DB::listen(function ($query) use ($baseline, &$referenceWrites): void {
            if (str_contains($query->sql, 'reference_sequences') && preg_match('/^update/i', $query->sql)) {
                $referenceWrites++;
                // ReferenceSequenceService itself uses a short isolated allocation transaction.
                $this->assertLessThanOrEqual($baseline + 1, DB::transactionLevel());
            }
        });
        $this->convert($quote);
        $this->assertGreaterThan(0, $referenceWrites);
    }

    public function test_internal_margin_is_authorized_before_selecting_average_cost(): void
    {
        $service = app(QuotationSourcingService::class);
        $staff = $this->actor(EmployeeRole::Staff);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull($service->marginPreview($this->data()['items'][0], $this->warehouse->id, $staff));
        $this->assertStringNotContainsString('average_cost', collect(DB::getQueryLog())->pluck('query')->implode(' '));
        DB::disableQueryLog();
        $this->assertSame('100.00', $service->marginPreview($this->data()['items'][0], $this->warehouse->id, $this->owner));
    }

    public function test_employee_overrides_remain_authoritative_and_do_not_grant_responsibility(): void
    {
        $staff = $this->actor(EmployeeRole::Staff);
        $overrides = app(EmployeePermissionOverrideService::class);
        foreach ([QuotationPermission::SourceInventory, QuotationPermission::ViewSourceCost] as $permission) {
            $overrides->change($staff->employee, $permission->value, EmployeePermissionEffect::Allow, 'Test authorized sourcing', $this->owner);
        }
        $this->assertTrue(app(QuotationSourcingService::class)->canManage($staff));
        $this->assertSame([], app(QuotationSourcingService::class)->availability($this->data()['items'], $this->warehouse->id, $staff));
        try {
            app(QuotationService::class)->create($this->data(), $staff);
            $this->fail('Permissions must not grant Responsibility.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('quotation_item_sourcing_instructions', 0);
        }
        $admin = $this->actor(EmployeeRole::Admin);
        $overrides->change($admin->employee, QuotationPermission::SourceInventory->value, EmployeePermissionEffect::Deny, 'Test explicit deny', $this->owner);
        $this->assertFalse(app(QuotationSourcingService::class)->canManage($admin));
    }

    public function test_reserved_stock_is_excluded_and_source_warehouse_cannot_be_changed_at_conversion(): void
    {
        $this->inventory->update(['available_quantity' => 3, 'reserved_quantity' => 2]);
        $quote = $this->accepted();
        $this->assertSame(2, $quote->items->sole()->sourcingInstruction->planned_source_quantity_snapshot);
        $other = Warehouse::factory()->create();
        try {
            app(QuotationConversionService::class)->toOrder($quote, $other->id, $this->owner);
            $this->fail('Quotation warehouse must be preserved.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('quotation_sourcing_postings', 0);
        }
        $this->convert($quote);
        $this->assertSame(5, $this->inventory->refresh()->reserved_quantity);
        $this->assertSame(2, QuotationSourcingPosting::query()->sole()->quantity);
    }

    public function test_sent_instruction_cannot_be_changed_deleted_or_reparented_to_a_draft(): void
    {
        $quote = $this->accepted();
        $draft = app(QuotationService::class)->create($this->data(['source_inventory' => false, 'purchase_unit_cost' => null]), $this->owner);
        foreach (['price', 'delete', 'reparent'] as $operation) {
            $instruction = $quote->items->sole()->sourcingInstruction()->firstOrFail();
            try {
                match ($operation) {
                    'price' => $instruction->update(['purchase_unit_cost' => '1.0000']),
                    'delete' => $instruction->delete(),
                    'reparent' => $instruction->update(['quotation_item_id' => $draft->items->sole()->id]),
                };
                $this->fail('Sent instructions must remain frozen.');
            } catch (\LogicException) {
                $this->assertSame('200.0000', $instruction->fresh()->purchase_unit_cost);
                $this->assertSame($quote->items->sole()->id, $instruction->fresh()->quotation_item_id);
            }
        }
    }

    public function test_source_cost_is_absent_from_unauthorized_livewire_view_and_server_query(): void
    {
        $quote = $this->accepted(['purchase_unit_cost' => '187.4321', 'source_note' => 'CONFIDENTIAL-SOURCE']);
        $admin = $this->actor(EmployeeRole::Admin);
        app(EmployeePermissionOverrideService::class)->change($admin->employee, QuotationPermission::ViewSourceCost->value, EmployeePermissionEffect::Deny, 'Test private cost', $this->owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::actingAs($admin)->test(ViewQuotation::class, ['record' => $quote->id])
            ->assertSuccessful()->assertDontSee('187.4321')->assertDontSee('CONFIDENTIAL-SOURCE');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();
        $this->assertStringNotContainsString('quotation_item_sourcing_instructions', $queries);
    }

    public function test_quotation_form_shows_inline_sourcing_and_saves_without_receiving_stock(): void
    {
        Livewire::actingAs($this->owner)->test(CreateQuotation::class)
            ->fillForm($this->data())->assertSee('Source for this quotation')->assertSee('Purchase Cost per Unit')
            ->assertSee('Missing: 2')->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseCount('quotations', 1);
        $this->assertDatabaseCount('quotation_item_sourcing_instructions', 1);
        $this->assertDatabaseCount('quotation_sourcing_postings', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_competing_conversions_of_the_same_quotation_create_only_one_receipt_and_order(): void
    {
        $quote = $this->accepted();
        $path = sys_get_temp_dir().'/tpz-source-race-'.Str::uuid().'.sqlite';
        touch($path);
        $workers = [];
        try {
            // Copy isolated test fixtures, NOT the active ERP database. No outer test transaction in workers.
            $copy = new \PDO('sqlite:'.$path);
            $copy->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $copy->exec('PRAGMA foreign_keys=OFF');
            $tables = DB::select("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($tables as $table) {
                $copy->exec($table->sql);
                foreach (DB::table($table->name)->get() as $record) {
                    $fields = array_keys((array) $record);
                    $statement = $copy->prepare('INSERT INTO "'.$table->name.'" ("'.implode('","', $fields).'") VALUES ('.implode(',', array_fill(0, count($fields), '?')).')');
                    $statement->execute(array_values((array) $record));
                }
            }
            foreach (DB::select("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND sql IS NOT NULL") as $object) {
                $copy->exec($object->sql);
            }
            $statement = null;
            $copy = null;
            foreach ([0, 1] as $slot) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/QuotationSourcingConversionWorker.php'), $path, (string) $quote->id, (string) $this->owner->id, (string) $slot], base_path(), timeout: 45);
                $worker->start();
                $workers[] = $worker;
            }
            $readinessStartedAt = microtime(true);
            $deadline = microtime(true) + 20;
            while ((! is_file($path.'.ready.0') || ! is_file($path.'.ready.1')) && microtime(true) < $deadline) {
                foreach ($workers as $slot => $worker) {
                    if (! is_file($path.'.ready.'.$slot) && ! $worker->isRunning()) {
                        break 2;
                    }
                }
                usleep(10000);
            }
            $readinessElapsed = microtime(true) - $readinessStartedAt;
            $readinessDiagnostics = $this->workerDiagnostics($workers, $path, $readinessElapsed);
            $this->assertFileExists($path.'.ready.0', $readinessDiagnostics);
            $this->assertFileExists($path.'.ready.1', $readinessDiagnostics);
            touch($path.'.go');
            $ids = [];
            foreach ($workers as $slot => $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $this->workerDiagnostics($workers, $path, microtime(true) - $readinessStartedAt));
                $ids[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['order_id'];
            }
            $this->assertSame($ids[0], $ids[1]);
            $copy = new \PDO('sqlite:'.$path);
            foreach (['orders', 'order_items', 'quotation_sourcing_postings', 'inventory_reservations'] as $table) {
                $this->assertSame(1, (int) $copy->query('SELECT count(*) FROM '.$table)->fetchColumn());
            }
            $this->assertSame(2, (int) $copy->query('SELECT count(*) FROM stock_movements')->fetchColumn());
            $this->assertSame(3, (int) $copy->query('SELECT available_quantity FROM product_inventories WHERE id='.$this->inventory->id)->fetchColumn());
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            $statement = null;
            $copy = null;
            foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal', $path.'.ready.0', $path.'.ready.1', $path.'.go'] as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    /** @param array<int, Process> $workers */
    private function workerDiagnostics(array $workers, string $path, float $elapsed): string
    {
        return json_encode([
            'readiness_elapsed_ms' => (int) round($elapsed * 1000),
            'workers' => collect($workers)->map(fn (Process $worker, int $slot): array => [
                'child_identifier' => $slot,
                'exit_code' => $worker->getExitCode(),
                'stdout' => $this->sanitizeWorkerOutput($worker->getOutput()),
                'stderr' => $this->sanitizeWorkerOutput($worker->getErrorOutput()),
                'readiness_file_created' => is_file($path.'.ready.'.$slot),
            ])->values()->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sanitizeWorkerOutput(string $output): string
    {
        $output = preg_replace(
            '/(?i)\b(password|passwd|secret|token|authorization|api[_-]?key|app_key|mail_password|smtp_password)\b(\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,;}]+)/',
            '$1$2[REDACTED]',
            $output,
        );
        $output = preg_replace('/(?i)\bBearer\s+[^\s,;}]+/', 'Bearer [REDACTED]', $output);
        $output = preg_replace('/(?i)\b(mysql|pgsql|postgres|sqlsrv):\/\/[^@\s]+@/', '$1://[REDACTED]@', $output);

        return mb_substr((string) $output, 0, 4096);
    }

    private function accepted(array $line = []): Quotation
    {
        $service = app(QuotationService::class);
        $quote = $service->create($this->data($line), $this->owner);
        $service->transition($quote, QuotationStatus::Sent, $this->owner);

        return $service->transition($quote->fresh(), QuotationStatus::Accepted, $this->owner)->load('items');
    }

    private function data(array $line = []): array
    {
        return ['customer_phone' => '+971500000000'] + $this->documentData($line);
    }

    private function documentData(array $line): array
    {
        return ['warehouse_id' => $this->warehouse->id, 'document_type' => 'quotation', 'quotation_date' => today()->toDateString(), 'valid_until' => today()->addDays(7)->toDateString(), 'customer_name' => 'Buyer', 'customer_address' => 'Dubai', 'idempotency_key' => (string) Str::uuid(), 'items' => [[...['product_id' => $this->product->id, 'description' => 'Laptop', 'quantity' => 3, 'unit_price_including_vat' => '200.00', 'discount_amount' => '0.00', 'vat_rate' => '5.0000', 'source_inventory' => true, 'purchase_unit_cost' => '200.0000'], ...$line]]];
    }

    private function convert(Quotation $quote): Order
    {
        return app(QuotationConversionService::class)->toOrder($quote, $this->warehouse->id, $this->owner);
    }

    private function actor(EmployeeRole $role): User
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        Employee::factory()->for($user)->role($role)->create(['email' => $user->email, 'status' => true]);

        return $user->refresh();
    }

    private function fingerprint(): array
    {
        return collect(['product_inventories', 'stock_movements', 'orders', 'order_items', 'inventory_reservations', 'quotation_sourcing_postings'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }
}
