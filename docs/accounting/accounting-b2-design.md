# Accounting B2 — Native AP and Procurement Accounting

This implementation is based on the verified read-only QuickBooks Desktop Enterprise 2024 review of Peera International Trader FZ-LLC. The file remains reference/testing data only; no records were modified or imported.

## Implemented foundation

- Book-scoped supplier bills with AED precision, supplier and purchase lineage.
- Bill lines linked to products and purchase items.
- Draft supplier payments with deterministic idempotency.
- Explicit payment-to-bill allocations with over-allocation checks.
- Supplier balance calculation for outstanding AP.
- Additive supplier adjustment schema for credits and debit notes; records remain draft-only pending posting policy approval.
- Existing ActivityLogger records source actions.
- No automatic journal posting or operational inventory mutation.

## QuickBooks Enterprise 2024 evidence update (16 Nov 2026)

- The isolated QuickBooks Vendor Center and bill form were inspected read-only. The Bill/Credit form has separate Expenses and Items lines, vendor reference, transaction/due dates, terms, class, memo, bill-received state and PO selection. The AP Aging Summary and Detail reports expose Current, 1–30, 31–60, 61–90 and over-90-day buckets, an as-of date and a configurable interval.
- The B2 draft-bill service already accepts multiple lines, but the form previously sent one line only. The form now supports adding/removing multiple description/quantity/unit-cost/VAT lines. Supplier aging now labels each open bill with those age buckets. These source-code changes are not runtime-tested in this turn.
- Account/item mappings, tax-item references, PO/receipt selection in the B2 form, supplier statements, all-supplier aging summaries, credit-to-bill allocations, bank settlement, transaction-level GL links and transaction exports remain unfinished. Do not infer QuickBooks posting effects from the screens; none were tested.
- Financial posting remains disabled. Before any posting design is enabled, Owner/accountant approval is required for receipt-versus-bill accrual, inventory/COGS timing, recoverable VAT, multicurrency and correction policy.

See [live findings](quickbooks-enterprise-2024-live-findings-20261116.md) and the [updated functional parity matrix](quickbooks-enterprise-2024-functional-parity-matrix-20261116.md).

## QuickBooks parity mapping

| QuickBooks concept | TPZ B2 seam |
|---|---|
| Vendor bill | accounting_supplier_bills and lines |
| Vendor credit / debit | accounting_supplier_adjustments |
| Bill payment / apply credits | payments and allocations |
| Accounts Payable ageing | bill due dates and outstanding balance query |
| Purchase order | existing purchases as non-posting source |
| Item receipt | existing purchase receipts linked by purchase_id |
| Inventory Asset / COGS | existing B1 accounts 1300 and 5000; posting disabled |
| Source transaction ID | source_type/source_key and idempotency keys |
| Historical import | versioned mapping to suppliers, refs, dates, currencies, lines and allocations |

## Posting boundary

B2 creates auditable source records only. It does not create journals, change stock balances, post GRNs, or enable B1 posting. A later Owner-approved policy must map approved bill and receipt events to AP, Inventory Asset, VAT, and COGS journals.
