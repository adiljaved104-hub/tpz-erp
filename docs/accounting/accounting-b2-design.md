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