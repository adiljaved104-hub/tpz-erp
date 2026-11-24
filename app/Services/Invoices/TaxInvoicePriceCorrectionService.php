<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Models\TaxInvoicePriceCorrection;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaxInvoicePriceCorrectionService
{
    public function __construct(
        private readonly InvoiceAuthorization $authorization,
        private readonly InvoiceSettingsService $settings,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Issued rows are never edited: the original is voided and a newly
     * numbered replacement is issued atomically, retaining item identities,
     * quantities, document snapshots and the ORIGINAL invoice VAT rate.
     *
     * @param array<string, mixed> $data
     */
    public function replace(TaxInvoice $invoice, array $data, User $actor): TaxInvoice
    {
        $this->authorization->authorize($actor, InvoicePermission::EditPrices, $invoice);

        $validated = validator($data, [
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_including_vat' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($invoice, $validated, $actor): TaxInvoice {
            $locked = TaxInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->authorization->authorize($actor, InvoicePermission::EditPrices, $locked);

            if ($locked->status !== 'issued'
                || TaxInvoicePriceCorrection::query()->where('original_invoice_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'This Invoice has already been voided or replaced.']);
            }

            $oldItems = $locked->items()->get();
            $prices = [];
            foreach ($validated['items'] as $line) {
                $id = (int) $line['id'];
                if (array_key_exists($id, $prices)) {
                    throw ValidationException::withMessages(['items' => 'Duplicate Invoice line supplied.']);
                }
                $prices[$id] = bcadd((string) $line['unit_price_including_vat'], '0', 2);
            }
            if (count($prices) !== $oldItems->count()
                || $oldItems->pluck('id')->diff(array_keys($prices))->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'Invoice lines have changed. Reload and try again.']);
            }

            $changed = false;
            $gross = '0.00';
            $divisor = bcadd('1', bcdiv((string) $locked->vat_rate, '100', 8), 8);
            $preparedLines = [];

            foreach ($oldItems as $oldItem) {
                $newPrice = $prices[$oldItem->id];
                $changed = $changed || bccomp((string) $oldItem->unit_price_including_vat, $newPrice, 2) !== 0;
                $lineGross = bcmul((string) $oldItem->quantity, $newPrice, 2);
                $lineNet = bcadd(bcdiv($lineGross, $divisor, 4), '0.005', 2);
                $gross = bcadd($gross, $lineGross, 2);
                $preparedLines[] = [$oldItem, $newPrice, $lineGross, $lineNet];
            }
            if (! $changed) {
                throw ValidationException::withMessages(['items' => 'Change at least one unit price before submitting.']);
            }

            $net = bcadd(bcdiv($gross, $divisor, 4), '0.005', 2);
            $vat = bcsub($gross, $net, 2);
            $settings = $this->settings->settings();

            $replacement = $locked->replicate();
            $replacement->forceFill([
                'invoice_number' => $this->references->nextTaxInvoiceNumber(
                    $settings->invoice_prefix,
                    (int) $settings->starting_number,
                ),
                'invoice_date' => now()->toDateString(),
                'issued_at' => now(),
                'created_by_user_id' => $actor->id,
                'idempotency_key' => (string) Str::uuid(),
                'verification_token' => bin2hex(random_bytes(32)),
                'status' => 'issued',
                'voided_at' => null,
                'voided_by_user_id' => null,
                'void_reason' => null,
                'subtotal_excluding_vat' => $net,
                'vat_amount' => $vat,
                'grand_total' => $gross,
            ]);
            $replacement->save();

            foreach ($preparedLines as [$oldItem, $newPrice, $lineGross, $lineNet]) {
                $newItem = $oldItem->replicate();
                $newItem->forceFill([
                    'tax_invoice_id' => $replacement->id,
                    'unit_price_including_vat' => $newPrice,
                    'subtotal_excluding_vat' => $lineNet,
                    'vat_amount' => bcsub($lineGross, $lineNet, 2),
                    'total_including_vat' => $lineGross,
                ]);
                $newItem->save();
            }

            $locked->forceFill([
                'status' => 'void',
                'voided_at' => now(),
                'voided_by_user_id' => $actor->id,
                'void_reason' => 'Superseded by '.$replacement->invoice_number.': '.trim($validated['reason']),
            ])->save();

            TaxInvoicePriceCorrection::query()->create([
                'original_invoice_id' => $locked->id,
                'replacement_invoice_id' => $replacement->id,
                'actor_user_id' => $actor->id,
                'reason' => trim($validated['reason']),
            ]);

            $this->activity->log('tax_invoice.price_replaced', $actor, $locked, [
                'original_invoice_id' => $locked->id,
                'replacement_invoice_id' => $replacement->id,
                'replacement_reference' => $replacement->invoice_number,
                'reason' => trim($validated['reason']),
                'actor_id' => $actor->id,
            ]);

            return $replacement->load('items');
        }, 3);
    }
}
