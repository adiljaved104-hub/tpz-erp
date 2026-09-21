<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\CompanyProfileService;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxInvoiceService
{
    public function __construct(private readonly InvoiceAuthorization $authorization, private readonly ReferenceSequenceService $references, private readonly CompanyProfileService $company, private readonly InvoiceSettingsService $settings, private readonly ActivityLogger $activity, private readonly TaxInvoiceOrderImportService $orderImport) {}

    public function create(array $data, User $actor): TaxInvoice
    {
        $this->authorization->authorize($actor, InvoicePermission::Create);
        $validated = validator($data, [
            'customer_name' => ['required', 'string', 'max:255'], 'customer_address' => ['required', 'string', 'max:2000'],
            'customer_trn' => ['nullable', 'string', 'max:50'], 'order_reference' => ['required', 'string', 'max:100'],
            'source_order_id' => ['nullable', 'integer'],
            'invoice_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:2000'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_including_vat' => ['required', 'decimal:0,2', 'gt:0'],
            'items.*.source_order_item_id' => ['nullable', 'integer'],
        ])->validate();
        if ($existing = TaxInvoice::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }
        if (filled($validated['source_order_id'] ?? null)) {
            $source = $this->orderImport->authorizedOrder($actor, (int) $validated['source_order_id']);
            if ($source === null) {
                throw ValidationException::withMessages(['source_order_id' => 'The selected Order is not available for import.']);
            }
            $allowedItemIds = $source->items->pluck('id')->all();
            foreach ($validated['items'] as $index => $item) {
                if (filled($item['source_order_item_id'] ?? null) && ! in_array((int) $item['source_order_item_id'], $allowedItemIds, true)) {
                    throw ValidationException::withMessages(["items.{$index}.source_order_item_id" => 'The selected Order item is not available for import.']);
                }
            }
        } elseif (collect($validated['items'])->contains(fn (array $item): bool => filled($item['source_order_item_id'] ?? null))) {
            throw ValidationException::withMessages(['source_order_id' => 'Select an Order before linking its items.']);
        }
        $settings = $this->settings->settings();
        $profile = $this->company->snapshot();
        $number = $this->references->nextTaxInvoiceNumber($settings->invoice_prefix, (int) $settings->starting_number);
        $totals = $this->calculateTotals($validated['items'], (string) $settings->vat_rate);
        ['vat_rate' => $vatRate, 'divisor' => $divisor, 'grand_total' => $gross, 'subtotal' => $net, 'vat' => $vat] = $totals;

        return DB::transaction(function () use ($validated, $actor, $settings, $profile, $number, $vatRate, $gross, $net, $vat, $divisor): TaxInvoice {
            $invoice = TaxInvoice::query()->create([
                'invoice_number' => $number, 'order_reference' => $validated['order_reference'] ?? null, 'source_order_id' => $validated['source_order_id'] ?? null, 'invoice_date' => $validated['invoice_date'],
                'customer_name' => trim($validated['customer_name']), 'customer_address' => $validated['customer_address'] ?? null, 'customer_trn' => $validated['customer_trn'] ?? null,
                'vat_rate' => $vatRate, 'subtotal_excluding_vat' => $net, 'vat_amount' => $vat, 'grand_total' => $gross,
                'seller_snapshot' => $profile, 'terms_en_snapshot' => $settings->terms_en, 'terms_ar_snapshot' => $settings->terms_ar,
                'status' => 'issued', 'created_by_user_id' => $actor->id, 'issued_at' => now(), 'idempotency_key' => $validated['idempotency_key'],
                'verification_token' => bin2hex(random_bytes(32)),
            ]);
            foreach (array_values($validated['items']) as $index => $item) {
                $lineGross = bcmul((string) $item['quantity'], (string) $item['unit_price_including_vat'], 2);
                $lineNet = bcadd(bcdiv($lineGross, $divisor, 4), '0.005', 2);
                $invoice->items()->create(['description' => trim($item['description']), 'quantity' => $item['quantity'], 'unit_price_including_vat' => $item['unit_price_including_vat'], 'source_order_item_id' => $item['source_order_item_id'] ?? null, 'subtotal_excluding_vat' => $lineNet, 'vat_amount' => bcsub($lineGross, $lineNet, 2), 'total_including_vat' => $lineGross, 'line_number' => $index + 1]);
            }
            $this->activity->log('tax_invoice.issued', $actor, $invoice, ['invoice_id' => $invoice->id, 'item_count' => count($validated['items']), 'actor_id' => $actor->id]);

            return $invoice->load(['items', 'createdBy']);
        });
    }

    public function void(TaxInvoice $invoice, string $reason, User $actor): TaxInvoice
    {
        $this->authorization->authorize($actor, InvoicePermission::Void, $invoice);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): TaxInvoice {
            $locked = TaxInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status === 'void') {
                throw ValidationException::withMessages(['reason' => 'This Invoice has already been voided.']);
            }
            $previousStatus = $locked->status;
            $locked->forceFill(['status' => 'void', 'void_reason' => trim($reason), 'voided_at' => now(), 'voided_by_user_id' => $actor->id])->save();
            $this->activity->log('tax_invoice.voided', $actor, $locked, [
                'invoice_id' => $locked->id,
                'invoice_reference' => $locked->invoice_number,
                'previous_status' => $previousStatus,
                'new_status' => $locked->status,
                'reason' => trim($reason),
                'actor_id' => $actor->id,
            ]);

            return $locked->refresh();
        });
    }

    public function updateCustomerDetails(TaxInvoice $invoice, array $data, User $actor): TaxInvoice
    {
        $validated = validator($data, [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_trn' => ['nullable', 'string', 'max:50'],
            'customer_address' => ['required', 'string', 'max:2000'],
            'amendment_reason' => ['required', 'string', 'max:2000'],
        ])->validate();

        $this->authorization->authorize($actor, InvoicePermission::EditCustomerDetails, $invoice);

        return DB::transaction(function () use ($invoice, $validated, $actor): TaxInvoice {
            $locked = TaxInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $this->authorization->authorize($actor, InvoicePermission::EditCustomerDetails, $locked);

            if ($locked->status === 'void') {
                throw ValidationException::withMessages([
                    'amendment_reason' => 'Void Invoices cannot be amended.',
                ]);
            }

            $previous = [
                'customer_name' => $locked->customer_name,
                'customer_trn' => $locked->customer_trn,
                'customer_address' => $locked->customer_address,
            ];
            $updated = [
                'customer_name' => trim($validated['customer_name']),
                'customer_trn' => filled($validated['customer_trn'] ?? null) ? trim($validated['customer_trn']) : null,
                'customer_address' => trim($validated['customer_address']),
                'updated_at' => now(),
            ];

            DB::table('tax_invoices')->where('id', $locked->id)->update($updated);
            $locked->refresh();

            $this->activity->log('tax_invoice.customer_details_updated', $actor, $locked, [
                'invoice_id' => $locked->id,
                'invoice_reference' => $locked->invoice_number,
                'previous_customer_name' => $previous['customer_name'],
                'new_customer_name' => $locked->customer_name,
                'previous_customer_trn' => $previous['customer_trn'],
                'new_customer_trn' => $locked->customer_trn,
                'previous_customer_address' => $previous['customer_address'],
                'new_customer_address' => $locked->customer_address,
                'reason' => trim($validated['amendment_reason']),
                'actor_id' => $actor->id,
            ]);

            return $locked;
        });
    }

    /** @param array<int|string, array<string, mixed>> $items @return array{subtotal: string, vat: string, grand_total: string, vat_rate: string} */
    public function previewTotals(array $items): array
    {
        $totals = $this->calculateTotals(array_values($items), (string) $this->settings->settings()->vat_rate);

        return array_intersect_key($totals, array_flip(['subtotal', 'vat', 'grand_total', 'vat_rate']));
    }

    /** @param array<int, array<string, mixed>> $items @return array{subtotal: string, vat: string, grand_total: string, vat_rate: string, divisor: string} */
    private function calculateTotals(array $items, string $vatRate): array
    {
        $divisor = bcadd('1', bcdiv($vatRate, '100', 8), 8);
        $gross = '0.00';
        foreach ($items as $item) {
            if (! is_numeric($item['quantity'] ?? null) || ! is_numeric($item['unit_price_including_vat'] ?? null)) {
                continue;
            }
            $gross = bcadd($gross, bcmul((string) $item['quantity'], (string) $item['unit_price_including_vat'], 2), 2);
        }
        $net = bcadd(bcdiv($gross, $divisor, 4), '0.005', 2);

        return ['subtotal' => $net, 'vat' => bcsub($gross, $net, 2), 'grand_total' => $gross, 'vat_rate' => $vatRate, 'divisor' => $divisor];
    }
}
