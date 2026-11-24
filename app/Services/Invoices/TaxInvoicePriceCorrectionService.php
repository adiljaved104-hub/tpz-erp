<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InvoiceAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxInvoicePriceCorrectionService
{
    public function __construct(private readonly InvoiceAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /**
     * Invoices already issued to customers must never be voided and reissued
     * merely to change a unit price. Request an adjustment review instead.
     *
     * @param array<string, mixed> $data
     */
    public function replace(TaxInvoice $invoice, array $data, User $actor): never
    {
        $this->authorization->authorize($actor, InvoicePermission::EditPrices, $invoice);
        throw ValidationException::withMessages([
            'reason' => 'Void-and-reissue price correction is disabled. Use Request VAT Adjustment Review.',
        ]);
    }

    /**
     * Create a non-posting, append-only proposal. This does NOT issue a tax
     * credit note, an additional invoice, a ledger journal or a VAT return
     * adjustment. Finance must review the correct documents and tax period.
     *
     * @param array<string, mixed> $data
     */
    public function propose(TaxInvoice $invoice, array $data, User $actor): TaxInvoicePriceAdjustmentProposal
    {
        $this->authorization->authorize($actor, InvoicePermission::EditPrices, $invoice);

        $validated = validator($data, [
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer', 'min:1'],
            'items.*.unit_price_including_vat' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
        ])->validate();

        return DB::transaction(function () use ($invoice, $validated, $actor): TaxInvoicePriceAdjustmentProposal {
            $locked = TaxInvoice::query()->lockForUpdate()->findOrFail($invoice->getKey());
            $this->authorization->authorize($actor, InvoicePermission::EditPrices, $locked);

            if ($locked->status !== 'issued') {
                throw ValidationException::withMessages(['reason' => 'Only issued invoices may be reviewed for a VAT price adjustment.']);
            }
            $hasPendingProposal = TaxInvoicePriceAdjustmentProposal::query()
                ->where('tax_invoice_id', $locked->id)
                ->where('status', 'pending_review')->exists();
            if ($hasPendingProposal) {
                throw ValidationException::withMessages(['reason' => 'This invoice already has a pending adjustment review.']);
            }

            $lines = $locked->items()->get();
            $prices = [];
            foreach ($validated['items'] as $line) {
                $id = (int) $line['id'];
                if (isset($prices[$id])) {
                    throw ValidationException::withMessages(['items' => 'Duplicate invoice item.']);
                }
                $prices[$id] = bcadd((string) $line['unit_price_including_vat'], '0', 2);
            }
            $itemIdsMismatch = $lines->pluck('id')->diff(array_keys($prices))->isNotEmpty();
            if (count($prices) !== $lines->count() || $itemIdsMismatch) {
                throw ValidationException::withMessages(['items' => 'Invoice lines do not match the issued document.']);
            }

            $original = [];
            $proposed = [];
            $documents = [
                'additional_tax_invoice' => ['gross' => '0.00', 'net' => '0.00', 'vat' => '0.00', 'lines' => []],
                'tax_credit_note' => ['gross' => '0.00', 'net' => '0.00', 'vat' => '0.00', 'lines' => []],
            ];
            $newGross = '0.00';
            $divisor = bcadd('1', bcdiv((string) $locked->vat_rate, '100', 8), 8);
            $changed = false;
            foreach ($lines as $line) {
                $oldPrice = (string) $line->unit_price_including_vat;
                $price = $prices[$line->id];
                $oldGross = (string) $line->total_including_vat;
                $newLineGross = bcmul((string) $line->quantity, $price, 2);
                $newLineNet = bcadd(bcdiv($newLineGross, $divisor, 4), '0.005', 2);
                $newLineVat = bcsub($newLineGross, $newLineNet, 2);
                $oldNet = (string) $line->subtotal_excluding_vat;
                $oldVat = (string) $line->vat_amount;
                $deltaGross = bcsub($newLineGross, $oldGross, 2);
                $deltaNet = bcsub($newLineNet, $oldNet, 2);
                $deltaVat = bcsub($newLineVat, $oldVat, 2);
                $newGross = bcadd($newGross, $newLineGross, 2);
                $changed = $changed || bccomp($oldPrice, $price, 2) !== 0;
                $original[] = ['item_id' => $line->id, 'description' => $line->description,
                    'quantity' => (int) $line->quantity, 'unit_price_including_vat' => $oldPrice,
                    'gross' => $oldGross, 'net' => $oldNet, 'vat' => $oldVat];
                $proposed[] = ['item_id' => $line->id, 'description' => $line->description,
                    'quantity' => (int) $line->quantity, 'unit_price_including_vat' => $price,
                    'gross' => $newLineGross, 'net' => $newLineNet, 'vat' => $newLineVat];
                if (bccomp($deltaGross, '0', 2) === 0) {
                    continue;
                }
                $kind = bccomp($deltaGross, '0', 2) > 0 ? 'additional_tax_invoice' : 'tax_credit_note';
                foreach (['gross' => $deltaGross, 'net' => $deltaNet, 'vat' => $deltaVat] as $field => $delta) {
                    $amount = ltrim($delta, '-');
                    $documents[$kind][$field] = bcadd($documents[$kind][$field], $amount, 2);
                }
                $documents[$kind]['lines'][] = [
                    'item_id' => $line->id, 'description' => $line->description,
                    'quantity' => (int) $line->quantity,
                    'original_price' => $oldPrice, 'proposed_price' => $price,
                    'difference_gross' => ltrim($deltaGross, '-'),
                    'difference_net' => ltrim($deltaNet, '-'),
                    'difference_vat' => ltrim($deltaVat, '-'),
                ];
            }
            if ($changed === false) {
                throw ValidationException::withMessages(['items' => 'At least one price must change.']);
            }

            $proposal = TaxInvoicePriceAdjustmentProposal::query()->create([
                'tax_invoice_id' => $locked->id,
                'requested_by_user_id' => $actor->id,
                'status' => 'pending_review',
                'reason' => trim($validated['reason']),
                'original_line_snapshot' => $original,
                'proposed_line_snapshot' => $proposed,
                'adjustment_documents_preview' => $documents,
                'old_gross' => $locked->grand_total,
                'new_gross' => $newGross,
                'gross_difference' => bcsub($newGross, (string) $locked->grand_total, 2),
            ]);
            $this->activity->log('tax_invoice.price_adjustment_review_requested', $actor, $locked, [
                'invoice_id' => $locked->id,
                'proposal_id' => $proposal->id,
                'actor_id' => $actor->id,
            ]);

            return $proposal;
        }, 3);
    }
}
