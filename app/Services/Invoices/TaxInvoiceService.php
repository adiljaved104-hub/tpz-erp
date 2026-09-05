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
    public function __construct(private readonly InvoiceAuthorization $authorization, private readonly ReferenceSequenceService $references, private readonly CompanyProfileService $company, private readonly InvoiceSettingsService $settings, private readonly ActivityLogger $activity) {}

    public function create(array $data, User $actor): TaxInvoice
    {
        $this->authorization->authorize($actor, InvoicePermission::Create);
        $validated = validator($data, [
            'customer_name' => ['required', 'string', 'max:255'], 'customer_address' => ['required', 'string', 'max:2000'],
            'customer_trn' => ['nullable', 'string', 'max:50'], 'order_reference' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'], 'idempotency_key' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:255'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_including_vat' => ['required', 'decimal:0,2', 'gt:0'],
        ])->validate();
        if ($existing = TaxInvoice::query()->where('idempotency_key', $validated['idempotency_key'])->first()) {
            return $existing;
        }
        $settings = $this->settings->settings();
        $profile = $this->company->snapshot();
        $number = $this->references->nextTaxInvoiceNumber($settings->invoice_prefix, (int) $settings->starting_number);
        $totals = $this->calculateTotals($validated['items'], (string) $settings->vat_rate);
        ['vat_rate' => $vatRate, 'divisor' => $divisor, 'grand_total' => $gross, 'subtotal' => $net, 'vat' => $vat] = $totals;

        return DB::transaction(function () use ($validated, $actor, $settings, $profile, $number, $vatRate, $gross, $net, $vat, $divisor): TaxInvoice {
            $invoice = TaxInvoice::query()->create([
                'invoice_number' => $number, 'order_reference' => $validated['order_reference'] ?? null, 'invoice_date' => $validated['invoice_date'],
                'customer_name' => trim($validated['customer_name']), 'customer_address' => $validated['customer_address'] ?? null, 'customer_trn' => $validated['customer_trn'] ?? null,
                'vat_rate' => $vatRate, 'subtotal_excluding_vat' => $net, 'vat_amount' => $vat, 'grand_total' => $gross,
                'seller_snapshot' => $profile, 'terms_en_snapshot' => $settings->terms_en, 'terms_ar_snapshot' => $settings->terms_ar,
                'status' => 'issued', 'created_by_user_id' => $actor->id, 'issued_at' => now(), 'idempotency_key' => $validated['idempotency_key'],
                'verification_token' => bin2hex(random_bytes(32)),
            ]);
            foreach (array_values($validated['items']) as $index => $item) {
                $lineGross = bcmul((string) $item['quantity'], (string) $item['unit_price_including_vat'], 2);
                $lineNet = bcadd(bcdiv($lineGross, $divisor, 4), '0.005', 2);
                $invoice->items()->create(['description' => trim($item['description']), 'quantity' => $item['quantity'], 'unit_price_including_vat' => $item['unit_price_including_vat'], 'subtotal_excluding_vat' => $lineNet, 'vat_amount' => bcsub($lineGross, $lineNet, 2), 'total_including_vat' => $lineGross, 'line_number' => $index + 1]);
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
                return $locked;
            }
            $locked->forceFill(['status' => 'void', 'void_reason' => trim($reason), 'voided_at' => now(), 'voided_by_user_id' => $actor->id])->save();
            $this->activity->log('tax_invoice.voided', $actor, $locked, ['invoice_id' => $locked->id, 'actor_id' => $actor->id]);

            return $locked->refresh();
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
