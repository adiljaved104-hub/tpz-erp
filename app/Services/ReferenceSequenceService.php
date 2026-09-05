<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Product;
use App\Models\ReferenceSequence;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferenceSequenceService
{
    public function nextEmployeeReference(): string
    {
        $number = $this->next('employee', $this->firstEmployeeNumber());

        return 'TPZ-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function nextProductSku(): string
    {
        $number = $this->next('product_sku', $this->firstProductSkuNumber());

        return 'TPZ-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public function nextStockMovementReference(): string
    {
        return 'SM-'.str_pad((string) $this->next('stock_movement'), 6, '0', STR_PAD_LEFT);
    }

    public function nextOpeningStockReference(): string
    {
        return 'OS-'.str_pad((string) $this->next('opening_stock'), 6, '0', STR_PAD_LEFT);
    }

    public function nextInventoryReservationReference(): string
    {
        return 'RSV-'.str_pad((string) $this->next('inventory_reservation'), 6, '0', STR_PAD_LEFT);
    }

    public function nextPurchaseReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "PO-{$year}-".str_pad((string) $this->next("purchase:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextPurchaseReceiptReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "GRN-{$year}-".str_pad((string) $this->next("purchase_receipt:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextResponsibilityAssignmentReference(): string
    {
        return 'RA-'.str_pad((string) $this->next('responsibility_assignment'), 6, '0', STR_PAD_LEFT);
    }

    public function nextSalesOrderReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "SO-{$year}-".str_pad((string) $this->next("sales_order:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextOrderFulfillmentReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "SOF-{$year}-".str_pad((string) $this->next("order_fulfillment:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextStockTransferReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "TRF-{$year}-".str_pad((string) $this->next("stock_transfer:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextCustomerReturnReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "RTN-{$year}-".str_pad((string) $this->next("customer_return:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextMarketplaceReturnRemovalReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "MRV-{$year}-".str_pad((string) $this->next("marketplace_return_removal:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextDamagedStockReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "DMG-{$year}-".str_pad((string) $this->next("damaged_stock:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextSafetClaimReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "CLM-{$year}-".str_pad((string) $this->next("safet_claim:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextWarrantyRepairReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "WR-{$year}-".str_pad((string) $this->next("warranty_repair:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextComplaintReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "CMP-{$year}-".str_pad((string) $this->next("complaint:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextTaskReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "TSK-{$year}-".str_pad((string) $this->next("task:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextLeaveRequestReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "LVR-{$year}-".str_pad((string) $this->next("leave_request:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextCompensatoryOffReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "COF-{$year}-".str_pad((string) $this->next("compensatory_off:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextEmployeeWarningReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "WRN-{$year}-".str_pad((string) $this->next("employee_warning:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextHrNoticeReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "NTC-{$year}-".str_pad((string) $this->next("hr_notice:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextTaxInvoiceNumber(string $prefix, int $startingNumber = 1): string
    {
        return trim($prefix).' '.$this->next('tax_invoice', $startingNumber);
    }

    public function nextOfficeFinanceReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "PKF-{$year}-".str_pad((string) $this->next("office_finance:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function nextQuotationReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return "QT-{$year}-".str_pad((string) $this->next("quotation:{$year}"), 6, '0', STR_PAD_LEFT);
    }

    public function next(string $key, int $initialValue = 1): int
    {
        if (DB::connection()->transactionLevel() > 0 && ! app()->runningUnitTests()) {
            throw new RuntimeException('References must be reserved before the business transaction so allocated values cannot be reused after rollback.');
        }

        ReferenceSequence::query()->insertOrIgnore([
            'key' => $key,
            'next_value' => $initialValue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::transaction(function () use ($key): int {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('reference_sequences')
                    ->where('key', $key)
                    ->update([
                        'next_value' => DB::raw('next_value + 1'),
                        'updated_at' => now(),
                    ]);

                return (int) DB::table('reference_sequences')->where('key', $key)->value('next_value') - 1;
            }

            $sequence = ReferenceSequence::query()->lockForUpdate()->findOrFail($key);
            $allocated = $sequence->next_value;
            $sequence->forceFill(['next_value' => $allocated + 1])->save();

            return $allocated;
        }, 5);
    }

    private function firstEmployeeNumber(): int
    {
        return Employee::query()
            ->where('employee_id', 'like', 'TPZ-%')
            ->pluck('employee_id')
            ->map(function (string $reference): int {
                return preg_match('/^TPZ-(\d+)$/', $reference, $matches) === 1
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() + 1;
    }

    private function firstProductSkuNumber(): int
    {
        return Product::query()
            ->where('sku', 'like', 'TPZ-%')
            ->pluck('sku')
            ->map(function (string $sku): int {
                return preg_match('/^TPZ-(\d{6,})$/', $sku, $matches) === 1
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() + 1;
    }
}
