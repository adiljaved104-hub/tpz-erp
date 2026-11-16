<?php

namespace Tests\Feature\Accounting;

use App\Enums\EmployeeRole;
use App\Models\AccountingImportBatchItem;
use App\Models\AccountingImportLink;
use App\Models\Employee;
use App\Models\User;
use App\Services\Accounting\QuickBooksImportPreviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuickBooksImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_ap_export_preview_preserves_original_fields_and_links_and_deduplicates_without_creating_erp_transactions(): void
    {
        Storage::fake('local');
        $owner = $this->user(EmployeeRole::Owner);
        $record = [
            'source_object_type' => 'BillPaymentCheck',
            'source_record_id' => 'QB-TXN-1912',
            'record_payload' => [
                'TxnID' => 'QB-TXN-1912',
                'EditSequence' => '17',
                'TxnDate' => '2026-11-16',
                'DueDate' => '2026-11-26',
                'CurrencyCode' => 'AED',
                'ExchangeRate' => '1.000000',
                'RefNumber' => 'CHECK-1912',
                'AppliedToTxnRet' => [
                    'TxnID' => 'QB-BILL-1911',
                    'TxnType' => 'Bill',
                    'TxnLineID' => '0',
                    'PaymentAmount' => '10.00',
                ],
            ],
        ];
        $source = json_encode(['records' => [$record]], JSON_THROW_ON_ERROR);
        $service = app(QuickBooksImportPreviewService::class);
        $first = $service->createBatch(UploadedFile::fake()->createWithContent('ap-export.json', $source), 'isolated-qb-ap-fixture', $owner);
        $service->preview($first, $owner);

        $item = AccountingImportBatchItem::query()->where('batch_id', $first->id)->sole();
        $link = AccountingImportLink::query()->where('batch_item_id', $item->id)->sole();
        $this->assertSame('previewed', $item->status);
        $this->assertSame('2026-11-16', $item->source_transaction_date->format('Y-m-d'));
        $this->assertSame('2026-11-26', $item->source_due_date->format('Y-m-d'));
        $this->assertSame('AED', $item->source_currency);
        $this->assertSame('17', $item->source_edit_sequence);
        $this->assertSame('QB-BILL-1911', $link->linked_source_record_id);
        $this->assertSame('10.00', (string) $link->applied_amount);
        $this->assertSame('CHECK-1912', $item->record_payload['RefNumber']);
        $this->assertDatabaseCount('accounting_supplier_bills', 0);
        $this->assertDatabaseCount('accounting_supplier_payments', 0);
        $this->assertDatabaseCount('accounting_journals', 0);

        $second = $service->createBatch(UploadedFile::fake()->createWithContent('ap-export-repeat.json', $source), 'isolated-qb-ap-fixture', $owner);
        $service->preview($second, $owner);
        $this->assertSame(1, $second->refresh()->duplicate_count);
        $this->assertDatabaseCount('accounting_supplier_bills', 0);
        $this->assertDatabaseCount('accounting_supplier_payments', 0);
        $this->assertDatabaseCount('accounting_journals', 0);
    }

    public function test_staff_cannot_stage_quickbooks_exports(): void
    {
        Storage::fake('local');
        $staff = $this->user(EmployeeRole::Staff);

        $this->expectException(AuthorizationException::class);
        app(QuickBooksImportPreviewService::class)->createBatch(
            UploadedFile::fake()->createWithContent('ap-export.json', '{"records":[]}'),
            'isolated-qb-ap-fixture',
            $staff,
        );
    }

    private function user(EmployeeRole $role): User
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create([
            'role' => $role,
            'status' => true,
            'email' => $user->email,
        ]);

        return $user;
    }
}
