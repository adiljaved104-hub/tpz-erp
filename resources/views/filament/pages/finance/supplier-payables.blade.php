<x-accounting.workspace current="payables" title="Supplier Payables" description="Manage supplier bills, payments, credits and account aging. Draft records remain non-posting." :books="$books" :book="$book">
    <div class="tpz-accounting-payables">
        @if(!$postingEnabled)
            <div class="tpz-accounting-notice" role="status"><strong>Draft-only accounting.</strong> Bills, payments and adjustments are auditable, but do not post journals or change inventory.</div>
        @endif

        <section class="tpz-accounting-panel tpz-payables-context" aria-label="Supplier Payables filters">
            <label class="tpz-accounting-field"><span>Supplier</span><select wire:model.live="supplierId" aria-label="Supplier"><option value="">Select a supplier</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select></label>
            <label class="tpz-accounting-field"><span>Accounting book</span><span class="tpz-accounting-readonly">{{ $book?->name ?? 'No accounting book configured' }} @if($book)· {{ $book->currency }}@endif</span></label>
            @if($supplierId !== '' && $supplierBalance !== null)
                <div class="tpz-payables-balance"><span>Supplier statement balance</span><strong>{{ $book?->currency }} {{ number_format((float) $supplierBalance, 2) }}</strong><small>Approved and posted supplier activity</small></div>
            @endif
        </section>

        <div class="tpz-payables-entry-grid">
            @if($canCreateBill)
                <section class="tpz-accounting-panel tpz-payables-bill-entry">
                    <div class="tpz-accounting-section-head"><div><h2>New supplier bill</h2><p>Enter a bill as a draft for review.</p></div><span class="tpz-accounting-status is-draft">Draft</span></div>
                    <form wire:submit="saveBill" class="tpz-payables-form">
                        <label class="tpz-accounting-field"><span>Supplier bill reference</span><input wire:model="billNumber" placeholder="Supplier's invoice number" autocomplete="off"><small class="tpz-accounting-error">@error('billNumber'){{ $message }}@enderror</small></label>
                        <div class="tpz-payables-two-col"><label class="tpz-accounting-field"><span>Bill date</span><input type="date" wire:model="billDate"></label><label class="tpz-accounting-field"><span>Due date</span><input type="date" wire:model="dueDate"><small class="tpz-accounting-error">@error('dueDate'){{ $message }}@enderror</small></label></div>
                        <div class="tpz-accounting-table-wrap tpz-payables-lines-wrap"><table class="tpz-accounting-data-table tpz-payables-lines"><colgroup><col class="tpz-bill-description-column"><col class="tpz-bill-quantity-column"><col class="tpz-bill-unit-cost-column"><col class="tpz-bill-vat-column"><col class="tpz-bill-remove-column"></colgroup><thead><tr><th>Description</th><th>Qty</th><th>Unit price · {{ $book?->currency }}</th><th>VAT %</th><th><span class="sr-only">Remove line</span></th></tr></thead><tbody>
                            @foreach($billLines as $index => $line)
                                <tr wire:key="supplier-bill-line-{{ $index }}"><td><input wire:model="billLines.{{ $index }}.description" aria-label="Line {{ $index + 1 }} description" placeholder="Item description"></td><td><input wire:model.live.debounce.250ms="billLines.{{ $index }}.quantity" inputmode="decimal" aria-label="Line {{ $index + 1 }} quantity" placeholder="1"></td><td><input wire:model.live.debounce.250ms="billLines.{{ $index }}.unit_cost" inputmode="decimal" aria-label="Line {{ $index + 1 }} unit price" placeholder="0.00"></td><td><input wire:model.live.debounce.250ms="billLines.{{ $index }}.vat_rate" inputmode="decimal" aria-label="Line {{ $index + 1 }} VAT rate" placeholder="0"></td><td><button type="button" class="tpz-accounting-icon-btn" wire:click="removeBillLine({{ $index }})" @disabled(count($billLines) <= 1) aria-label="Remove line {{ $index + 1 }}">×</button></td></tr>
                            @endforeach
                        </tbody></table></div>
                        <div class="tpz-payables-bill-totals" aria-live="polite" aria-label="Draft bill totals">
                            @if($draftBillTotals)
                                <div><span>Subtotal</span><strong>{{ $book?->currency }} {{ number_format((float) $draftBillTotals['subtotal'], 2) }}</strong></div>
                                <div><span>VAT</span><strong>{{ $book?->currency }} {{ number_format((float) $draftBillTotals['vat'], 2) }}</strong></div>
                                <div class="is-total"><span>Grand total</span><strong>{{ $book?->currency }} {{ number_format((float) $draftBillTotals['total'], 2) }}</strong></div>
                            @else
                                <p>Enter valid quantities, unit prices and VAT rates to preview totals.</p>
                            @endif
                        </div>
                        <div class="tpz-accounting-form-error">@error('billLines'){{ $message }}@enderror @foreach($errors->get('billLines.*') as $messages){{ implode(' ', $messages) }} @endforeach</div>
                        <div class="tpz-accounting-form-actions"><button type="button" class="tpz-accounting-btn" wire:click="addBillLine">＋ Add line</button><button type="submit" class="tpz-accounting-btn is-primary" wire:loading.attr="disabled" wire:target="saveBill"><span wire:loading.remove wire:target="saveBill">Save draft bill</span><span wire:loading wire:target="saveBill">Saving…</span></button></div>
                    </form>
                </section>
            @endif

            <div class="tpz-payables-compact-entries">
            @if($canRecordPayment)
                <section class="tpz-accounting-panel">
                    <div class="tpz-accounting-section-head"><div><h2>New supplier payment</h2><p>Record payment details without posting.</p></div><span class="tpz-accounting-status is-draft">Draft</span></div>
                    <form wire:submit="savePayment" class="tpz-payables-form">
                        <label class="tpz-accounting-field"><span>Payment date</span><input type="date" wire:model="paymentDate"></label>
                        <label class="tpz-accounting-field"><span>Amount · {{ $book?->currency }}</span><input wire:model="paymentAmount" inputmode="decimal" placeholder="0.00"><small class="tpz-accounting-error">@error('paymentAmount'){{ $message }}@enderror</small></label>
                        <label class="tpz-accounting-field"><span>Payment reference</span><input wire:model="paymentReference" placeholder="Bank or payment reference"><small class="tpz-accounting-error">@error('paymentReference'){{ $message }}@enderror</small></label>
                        <div class="tpz-accounting-form-actions"><span class="tpz-accounting-subtle">Available to allocate after saving.</span><button type="submit" class="tpz-accounting-btn is-primary" wire:loading.attr="disabled" wire:target="savePayment"><span wire:loading.remove wire:target="savePayment">Save draft payment</span><span wire:loading wire:target="savePayment">Saving…</span></button></div>
                    </form>
                </section>
            @endif

            @if($canManageCredits)
                <section class="tpz-accounting-panel">
                    <div class="tpz-accounting-section-head"><div><h2>Credit or debit note</h2><p>Record a supplier adjustment as a draft.</p></div><span class="tpz-accounting-status is-draft">Draft</span></div>
                    <form wire:submit="saveAdjustment" class="tpz-payables-form">
                        <label class="tpz-accounting-field"><span>Adjustment type</span><select wire:model="adjustmentKind"><option value="credit">Credit note</option><option value="debit">Debit note</option></select></label>
                        <label class="tpz-accounting-field"><span>Adjustment date</span><input type="date" wire:model="adjustmentDate"></label>
                        <label class="tpz-accounting-field"><span>Amount · {{ $book?->currency }}</span><input wire:model="adjustmentAmount" inputmode="decimal" placeholder="0.00"><small class="tpz-accounting-error">@error('adjustmentAmount'){{ $message }}@enderror</small></label>
                        <label class="tpz-accounting-field"><span>Reference</span><input wire:model="adjustmentReference" placeholder="Supplier reference"></label>
                        <label class="tpz-accounting-field"><span>Reason</span><input wire:model="adjustmentReason" placeholder="Reason for adjustment"><small class="tpz-accounting-error">@error('adjustmentReason'){{ $message }}@enderror</small></label>
                        <div class="tpz-accounting-form-actions"><span></span><button type="submit" class="tpz-accounting-btn is-primary" wire:loading.attr="disabled" wire:target="saveAdjustment"><span wire:loading.remove wire:target="saveAdjustment">Save draft note</span><span wire:loading wire:target="saveAdjustment">Saving…</span></button></div>
                    </form>
                </section>
            @endif
            </div>
        </div>

        @if($canAllocatePayment)
            <section class="tpz-accounting-panel tpz-payables-allocation">
                <div class="tpz-accounting-section-head"><div><h2>Payment allocation</h2><p>Choose a saved payment and bill for the same supplier, book and currency.</p></div><span class="tpz-accounting-status is-neutral">No posting</span></div>
                <form wire:submit="allocatePayment" class="tpz-allocation-form">
                    <div class="tpz-accounting-field tpz-allocation-picker" x-data="{open:false}" @click.outside="open=false">
                        <label for="allocation-payment-search"><span>Payment</span></label>
                        <input id="allocation-payment-search" type="search" wire:model.live.debounce.200ms="allocationPaymentSearch" @focus="open=true" @keydown.escape="open=false" autocomplete="off" placeholder="Search payment reference or supplier" role="combobox" aria-autocomplete="list" aria-controls="allocation-payment-options" aria-expanded="{{ $allocationPaymentId === '' && $allocationPaymentSearch !== '' ? 'true' : 'false' }}">
                        @if($allocationPaymentId === '')
                            @if($supplierId === '')<small class="tpz-accounting-subtle">Select a supplier to find eligible payments.</small>@elseif($allocationPaymentSearch !== '')
                                <div class="tpz-allocation-options" id="allocation-payment-options" role="listbox">
                                    @forelse($allocationPayments->take(8) as $payment)
                                        <button type="button" role="option" wire:click="selectAllocationPayment({{ $payment->id }})" @click="open=false"><strong>{{ $payment->reference }}</strong><span>{{ $payment->currency }} {{ number_format((float) $payment->available_amount, 2) }} available · {{ ucfirst($payment->status) }}</span></button>
                                    @empty<span class="tpz-allocation-option-empty">No matching unallocated payments.</span>@endforelse
                                </div>
                            @endif
                        @else
                            <div class="tpz-allocation-selected"><span><strong>{{ $selectedPayment?->reference ?? $allocationPaymentSearch }}</strong><small>Available {{ $selectedPayment?->currency }} {{ $selectedPayment ? number_format((float) $selectedPayment->available_amount, 2) : '' }} · {{ ucfirst($selectedPayment?->status ?? '') }}</small></span><button type="button" wire:click="$set('allocationPaymentSearch', '')" aria-label="Change selected payment">Change</button></div>
                        @endif
                        <small class="tpz-accounting-error">@error('allocationPaymentId'){{ $message }}@enderror</small>
                    </div>
                    <div class="tpz-accounting-field tpz-allocation-picker" x-data="{open:false}" @click.outside="open=false">
                        <label for="allocation-bill-search"><span>Supplier bill</span></label>
                        <input id="allocation-bill-search" type="search" wire:model.live.debounce.200ms="allocationBillSearch" @focus="open=true" @keydown.escape="open=false" autocomplete="off" placeholder="Search bill reference or date" role="combobox" aria-autocomplete="list" aria-controls="allocation-bill-options" aria-expanded="{{ $allocationBillId === '' && $allocationBillSearch !== '' && $allocationPaymentId !== '' ? 'true' : 'false' }}" @disabled($allocationPaymentId === '')>
                        @if($allocationBillId === '')
                            @if($allocationPaymentId !== '' && $allocationBillSearch !== '')
                                <div class="tpz-allocation-options" id="allocation-bill-options" role="listbox">
                                    @forelse($allocationBills->take(8) as $bill)
                                        <button type="button" role="option" wire:click="selectAllocationBill({{ $bill->id }})" @click="open=false"><strong>{{ $bill->bill_number ?: 'Bill #'.$bill->id }}</strong><span>Outstanding {{ $bill->currency }} {{ number_format((float) $bill->outstanding_amount, 2) }} · {{ $bill->bill_date?->format('Y-m-d') }}</span></button>
                                    @empty<span class="tpz-allocation-option-empty">No matching outstanding bills.</span>@endforelse
                                </div>
                            @endif
                        @elseif($selectedBill)
                            <div class="tpz-allocation-selected"><span><strong>{{ $selectedBill->bill_number ?: 'Bill #'.$selectedBill->id }}</strong><small>Outstanding {{ $selectedBill->currency }} {{ number_format((float) $selectedBill->outstanding_amount, 2) }} · {{ $selectedBill->bill_date?->format('Y-m-d') }}</small></span><button type="button" wire:click="$set('allocationBillSearch', '')" aria-label="Change selected bill">Change</button></div>
                        @endif
                        <small class="tpz-accounting-error">@error('allocationBillId'){{ $message }}@enderror</small>
                    </div>
                    <label class="tpz-accounting-field"><span>Allocation amount · {{ $selectedPayment?->currency ?? $book?->currency }}</span><input wire:model="allocationAmount" inputmode="decimal" placeholder="0.00" @disabled($allocationBillId === '')><small class="tpz-accounting-error">@error('allocationAmount'){{ $message }}@enderror</small></label>
                    <div class="tpz-allocation-submit"><button type="submit" class="tpz-accounting-btn is-primary" @disabled(!$selectedPayment || !$allocationBillId) wire:loading.attr="disabled" wire:target="allocatePayment"><span wire:loading.remove wire:target="allocatePayment">Allocate payment</span><span wire:loading wire:target="allocatePayment">Saving…</span></button></div>
                    <div class="tpz-accounting-form-error">@error('allocations'){{ $message }}@enderror @error('payment'){{ $message }}@enderror</div>
                </form>
                @if($selectedPayment)<p class="tpz-allocation-summary">Available on selected payment: <strong>{{ $selectedPayment->currency }} {{ number_format((float) $selectedPayment->available_amount, 2) }}</strong>. The server validates remaining amount and bill balance when saved.</p>@endif
            </section>
        @endif

        <section class="tpz-accounting-panel">
            <div class="tpz-accounting-section-head"><div><h2>Supplier aging</h2><p>Approved or posted open bills, grouped by due date. Select a supplier to review.</p></div><label class="tpz-accounting-field tpz-payables-date"><span>As of date</span><input type="date" wire:model.live="asOfDate"></label></div>
            <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table"><thead><tr><th>Bill reference</th><th>Due date</th><th>Age bucket</th><th>Days overdue</th><th class="text-right">Outstanding · {{ $book?->currency }}</th></tr></thead><tbody>
                @forelse($ageing as $bill)<tr><td>{{ $bill->bill_number ?: '#'.$bill->id }}</td><td>{{ $bill->due_date?->format('Y-m-d') ?: '—' }}</td><td>{{ $bill->age_bucket }}</td><td>{{ $bill->days_overdue }}</td><td class="text-right">{{ $bill->currency }} {{ number_format((float) $bill->outstanding_amount, 2) }}</td></tr>
                @empty<tr><td colspan="5" class="tpz-accounting-empty-cell">{{ $supplierId === '' ? 'Select a supplier to view aging.' : 'No approved outstanding bills for this supplier.' }}</td></tr>@endforelse
            </tbody></table></div>
            @if(method_exists($ageing, 'links'))<div class="tpz-payables-pagination">{{ $ageing->links() }}</div>@endif
        </section>

        <section class="tpz-accounting-panel">
            <div class="tpz-accounting-section-head"><div><h2>Supplier bills</h2><p>Recent saved drafts and approved bills.</p></div><span class="tpz-accounting-count">{{ $bills->count() }} recent</span></div>
            <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table"><thead><tr><th>Supplier</th><th>Bill reference</th><th>Bill date</th><th>Due date</th><th class="text-right">Total</th><th>Status</th>@if($canApprove)<th>Action</th>@endif</tr></thead><tbody>
                @forelse($bills as $bill)<tr><td>{{ $bill->supplier?->name }}</td><td>{{ $bill->bill_number ?: '—' }}</td><td>{{ $bill->bill_date?->format('Y-m-d') }}</td><td>{{ $bill->due_date?->format('Y-m-d') ?: '—' }}</td><td class="text-right">{{ $bill->currency }} {{ number_format((float) $bill->grand_total, 2) }}</td><td><span class="tpz-accounting-status is-{{ $bill->status === 'approved' || $bill->status === 'posted' ? 'approved' : ($bill->status === 'voided' ? 'voided' : 'draft') }}">{{ ucfirst($bill->status) }}</span></td>@if($canApprove)<td>@if($bill->status === 'draft')<button type="button" class="tpz-accounting-text-btn" wire:click="approveBill({{ $bill->id }})">Approve</button>@else<span class="tpz-accounting-subtle">—</span>@endif</td>@endif</tr>
                @empty<tr><td colspan="{{ $canApprove ? 7 : 6 }}" class="tpz-accounting-empty-cell">No supplier bills have been saved yet.</td></tr>@endforelse
            </tbody></table></div>
        </section>

        <div class="tpz-payables-record-grid">
            <section class="tpz-accounting-panel">
                <div class="tpz-accounting-section-head"><div><h2>Supplier payments</h2><p>Recent payment records and draft status.</p></div><span class="tpz-accounting-count">{{ $payments->count() }} recent</span></div>
                <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table"><thead><tr><th>Reference</th><th>Supplier</th><th>Date</th><th class="text-right">Amount</th><th>Status</th>@if($canApprove)<th>Action</th>@endif</tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ $payment->reference }}</td><td>{{ $payment->supplier?->name }}</td><td>{{ $payment->payment_date?->format('Y-m-d') }}</td><td class="text-right">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td><td><span class="tpz-accounting-status is-{{ $payment->status === 'approved' || $payment->status === 'posted' ? 'approved' : ($payment->status === 'voided' ? 'voided' : 'draft') }}">{{ ucfirst($payment->status) }}</span></td>@if($canApprove)<td>@if($payment->status === 'draft')<button type="button" class="tpz-accounting-text-btn" wire:click="approvePayment({{ $payment->id }})">Approve</button>@else<span class="tpz-accounting-subtle">—</span>@endif</td>@endif</tr>@empty<tr><td colspan="{{ $canApprove ? 6 : 5 }}" class="tpz-accounting-empty-cell">No supplier payments have been saved yet.</td></tr>@endforelse</tbody></table></div>
            </section>
            <section class="tpz-accounting-panel">
                <div class="tpz-accounting-section-head"><div><h2>Credits and debit notes</h2><p>Recent supplier adjustments.</p></div><span class="tpz-accounting-count">{{ $adjustments->count() }} recent</span></div>
                <div class="tpz-accounting-table-wrap"><table class="tpz-accounting-data-table"><thead><tr><th>Reference</th><th>Supplier</th><th>Type</th><th class="text-right">Amount</th><th>Status</th>@if($canApprove)<th>Action</th>@endif</tr></thead><tbody>@forelse($adjustments as $adjustment)<tr><td>{{ $adjustment->reference ?: '—' }}</td><td>{{ $adjustment->supplier?->name }}</td><td>{{ ucfirst($adjustment->kind) }}</td><td class="text-right">{{ $adjustment->currency }} {{ number_format((float) $adjustment->amount, 2) }}</td><td><span class="tpz-accounting-status is-{{ $adjustment->status === 'approved' || $adjustment->status === 'posted' ? 'approved' : ($adjustment->status === 'voided' ? 'voided' : 'draft') }}">{{ ucfirst($adjustment->status) }}</span></td>@if($canApprove)<td>@if($adjustment->status === 'draft')<button type="button" class="tpz-accounting-text-btn" wire:click="approveAdjustment({{ $adjustment->id }})">Approve</button>@else<span class="tpz-accounting-subtle">—</span>@endif</td>@endif</tr>@empty<tr><td colspan="{{ $canApprove ? 6 : 5 }}" class="tpz-accounting-empty-cell">No supplier adjustments have been saved yet.</td></tr>@endforelse</tbody></table></div>
            </section>
        </div>
    </div>
</x-accounting.workspace>
