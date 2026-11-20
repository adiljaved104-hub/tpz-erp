# Accounting B2 — Native AP and Procurement Accounting

This implementation is based on the QuickBooks Desktop Enterprise 2024 review of Peera International Trader FZ-LLC. The restored isolated QB test copy remains reference/testing data only. Under the Owner's explicit testing authorization, one clearly labeled synthetic customer and one invoice were added to that isolated copy; no records were changed in the original company. TPZ ERP code and financial records were not modified by the QuickBooks testing session.

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
- **Transaction-level evidence update:** the isolated copy's invoice `TPZQB-INV01` (AED 100.00) posted A/R debit and Sales credit of AED 100.00, plus COGS debit and Inventory Asset credit of AED 291.99. The accrual Trial Balance remained balanced and the GL showed the A/R debit. The corresponding existing inventory item's cost was higher than its test sale price; no post-sale valuation report was completed. A partial customer receipt was attempted only to investigate the UI; focus landed in an unrelated text field, QuickBooks displayed an invalid-character warning, and the blank receipt was closed without saving. Thus payment application behavior is still unverified.
- **Follow-up reports:** the accrual P&L (Nov 1–16) showed Sales AED 100.00, COGS AED 291.99 and net income -AED 191.99; the Balance Sheet as of Nov 16 showed the same net income and balanced. Inventory Valuation Detail showed the sold unit at AED 291.99 and on-hand quantity 48 (49 before sale). Thus the sales-side cost/quantity relief is independently corroborated; purchase-side receipt and AP valuation are still untested.
- **Purchase-order UI:** a PO form with the synthetic AED vendor selected was inspected, including item receipt and approval controls, but item-grid focus could not be verified. The blank PO was explicitly canceled at the save prompt. No PO, receipt or bill was created, and purchasing/accounting posting behavior remains unverified.

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

### QuickBooks continuation correction — partial receipt and statement preview (16 Nov 2026)

This section supersedes the earlier B2 evidence note saying no receipt was saved. In the isolated QuickBooks copy, a controlled AED 40.00 cash receipt was applied to synthetic customer invoice `TPZQB-INV01` (AED 100.00). QuickBooks showed AED 60.00 underpayment and then Customer Center showed a remaining customer balance of AED 60.00. The saved receipt's Transaction Journal showed debit Undeposited Funds AED 40.00 and credit Accounts Receivable AED 40.00; both sides balanced. Transaction number 1939 was visible. Accrual Trial Balance remained balanced. No bank deposit was made.

A Create Statements form was also inspected for that one synthetic customer only. It offered A/R account, statement date, period vs. all open transactions, per-customer output, template customization, invoice-item details, due date/PO display and suppression filters. Preview showed the AED 100.00 invoice and AED 40.00 payment and AED 60.00 current due. Preview was closed without printing, emailing, or saving a statement.

PO focus remains unreliable: a separate blank PO with the wrong vendor selected was discarded; no PO or purchasing record was saved. B2 should therefore add explicit payment allocation evidence, including undeposited funds as an intermediate asset, and customer/supplier statements or equivalent subledger views without duplicating operational documents. This is verified QuickBooks behavior in the isolated copy only; no TPZ posting was activated.

- **Superseding evidence:** QuickBooks partial-receipt workflow is verified for AED 40.00 against the synthetic AED 100.00 invoice, with AED 60.00 remaining; Transaction Journal debited Undeposited Funds and credited A/R equally (payment #1939), and Trial Balance remained balanced. Customer statement preview also reconciled invoice AED 100 less receipt AED 40 to AED 60 current due. These findings replace the earlier “no receipt saved / payment unverified” statements above. Full payment, overpayment, deposit and refunds remain untested.

### Additional verified customer receipt (16 Nov 2026)

With Owner action-time approval, a second AED 60.00 Cash receipt was applied to synthetic invoice `TPZQB-INV01`, closing its AED 60.00 due balance. Customer Center shows balance 0.00 with the AED 100 invoice and AED 40/AED 60 receipts, both in Undeposited Funds. Product Information confirms the isolated file and 1,915 transaction count. Transaction Journal #1940 is balanced: debit Undeposited Funds 60.00, credit A/R 60.00. No deposit or bank settlement took place. The subsequent FY-to-date Trial Balance was opened, but the bottom totals were not visible and therefore are not claimed verified after the second receipt.

### Additional verified supplier bill (16 Nov 2026)

Synthetic supplier bill `TPZQB-BILL01` was saved for AED 25.00 to Office Supplies in the isolated QuickBooks copy. Its Transaction Journal #1941 debited the expense and credited A/P equally; Vendor Center balance and filtered A/P Aging each showed AED 25.00. The approved intended dates (18 Nov transaction, 20 Nov due) did not persist: saved journal and Pay Bills showed 16 Nov and 26 Nov. This is a material B2 UX requirement: present a final immutable-looking review summary of vendor, reference, currency, transaction date, due date, account/item allocation, tax, and total before save, then verify those exact values after save. Pay Bills partial amount entry could not be safely verified and no payment was saved. No B2 posting or stock mutation was enabled by this test.

### AP read-only continuation and next B2 work

On 16 Nov, Product Information reconfirmed the isolated company. Pay Bills displayed `TPZQB-BILL01` due AED 25 on 26 Nov, vendor/reference filters, the due date/amount/discount/credit/allocation columns, and payment date/method/account controls. The payment amount edit held malformed `10.0025.00` and raised a two-decimal warning. No payment was submitted, so there is no verified AP settlement or partial-payment journal. The previous draft's 18/20 Nov dates versus saved 16/26 Nov remain unexplained; preserve the saved transaction and inspect its date/terms fields read-only before any corrective action.

The Vendors menu exposed Create Purchase Orders, Receive Items, Enter Bill for Received Items, and Inventory Activities. Enter Bills had Bill/Credit, Select PO, Receive Items, Expenses/Items and Pay Bill controls. These are navigation/form observations only: no vendor credit, purchase order, receipt, inventory purchase, or receiving accrual behavior was verified. In B2, prioritize: (1) pre-save and post-save date/terms validation; (2) payment allocation drafts with exact applied, unapplied, remaining, method and cash/bank account values; (3) supplier credit allocation and aging treatment; (4) purchase/receipt/bill linkage by canonical ERP source IDs; and (5) explicit policy for received-not-billed inventory/AP or clearing accrual. Keep financial posting and inventory mutation disabled pending accounting policy/UAT approval.

### AP testing checkpoint — date inconsistency and payment preview (16 Nov 2026)

The live Pay Bills list currently shows `TPZQB-BILL01` with AED25 due and due date 28 Nov 2026. Earlier UI read-back showed transaction date 16 Nov, due date 26 Nov and blank Terms; the approved draft had displayed 18/20 Nov. The Terms list includes common options, but none was applied. Cause and current saved header due date are unresolved; no saved record was edited.

The current payment amount control contains an unsaved AED10.00. Payment date, destination account and method are clipped from the current view. No supplier payment was saved, so amount allocation, remaining balance, journal and post-payment aging are unverified. Preserve the transient entry without navigating into save actions; obtain action-time approval only after all payment attributes are visible and unambiguous.

Next B2 increment: retain transaction date, terms ID and whether due date is derived or overridden; preview due date; read back after save and flag mismatches. Payment drafts should show one row per bill with applied, unapplied and remaining values, credits/discounts, date, method, account and reference; reject ambiguous currency precision. AP Aging should be as-of and posted/open scoped, include allocated/unallocated credits explicitly and reconcile to AP control. Link existing purchase, receipt/GRN and bill by canonical source IDs; do not duplicate operational inventory events. Keep received-not-billed accrual disabled pending accounting policy. See the [live findings](quickbooks-enterprise-2024-live-findings-20261116.md) and [parity matrix](quickbooks-enterprise-2024-functional-parity-matrix-20261116.md).

### Owner payment read-back — 16 Nov 2026

The Owner's supplier payment is reflected in QuickBooks: Product Information shows the verified isolated test path and total transactions increased 1,916→1,917; Vendor Center synthetic-vendor balance and refreshed A/P Aging Summary both show AED15 outstanding versus AED25 before payment. The AED10 payment amount is inferred from this balance movement; payment detail and journal have not yet been directly identified. The saved bill currently displays date 16 Nov and due date 26 Nov, while the earlier Pay Bills view displayed 28 Nov due. Preserve this mismatch as unresolved and do not edit or pay the bill again.

B2 parity acceptance should require payment-source read-back, amount, date, method, bank/cash account, reference, bill allocation, AP debit and account credit, and residual balance to reconcile. Maintain a payment status distinct from bill status and prevent duplicate application. Do not treat the AED10 inference as transaction-level proof. Use read-only transaction search/reporting to locate the payment before concluding AP payment testing.

### Verified supplier payment and receipt workflow findings — 16 Nov 2026

On the isolated file, the Owner's payment is directly verified: Vendor Center shows bill `TPZQB-BILL01` AED25 and Bill Pmt-Check AED10 on 16 Nov to Cash A/c; the payment detail allocates AED10 to this bill. Transaction Journal #1912 is balanced: debit A/P AED10, credit Cash A/c AED10. Vendor Center and A/P Aging Summary/Detail agree on AED15 outstanding; Aging Detail due date is 26 Nov. The stored bill and payment allocation also show 26 Nov, while an earlier Pay Bills list displayed 28 Nov. Keep the UI inconsistency documented and do not alter the bill.

Read-only inspection found no purchase orders or item receipts in the reference company under all-date lists. Blank PO and Item Receipt forms expose vendor/date/reference, item or expense lines, quantities/rates, bill-received state and receipt/close actions. The “Enter Bill for Received Items” selector for the synthetic supplier was empty and offers a “Use item receipt date for the bill date” option. The vendor-credit form offers credit amount, date, reference, class, memo and expense/item allocation tabs. These are UI/form observations only; no vendor credit, PO, receipt, inventory purchase or associated posting has been tested transactionally.

B2 priorities: persist bill payment metadata and allocations with unique references and source links; verify payment→bill→A/P journal and residual aging; model vendor credits and application status; link PO→existing GRN→bill without duplicate warehouse records; support partial receipt/matching; define and approve received-not-billed inventory/AP accrual before implementing GL posting. Any further save action must be reviewed and performed by the Owner at the final transaction commit step.

### Vendor credit read-back — 16 Nov 2026

The Owner-saved `TPZQB-CREDIT01` is verified in the isolated file. Credit AED5, dated 16 Nov, Office Supplies expense line AED5; Transaction Journal #1913 balances Dr A/P AED5 / Cr Office Supplies AED5. Vendor Center balance fell from AED15 to AED10. AP Aging Summary nets Current/Total to AED10; AP Aging Detail shows the credit separately at -AED5 and the bill still open at AED15 due 26 Nov. This proves the credit was not applied against the bill; QuickBooks nets the vendor and aging total while retaining the open bill and unapplied credit as separate detail lines.

The saved memo contains unrelated text from the previous investigation prompt due to a UI text-entry failure. I did not alter the saved credit. The Owner should review/correct the memo if needed, retaining its audit trail. B2 should show unapplied credits separately from open bills, provide an explicit bill-allocation preview before application, and reconcile both gross A/P and net supplier position. Credit application, credit refund, and tax/item-credit behavior remain untested.

**Current UI automation blocker — 16 Nov 2026:** attempting to select the saved vendor-credit memo with Ctrl+A caused QuickBooks to open Chart of Accounts, indicating focus had shifted off the credit child form. No record was changed. Defer memo correction and additional save-dependent AP scenarios until child-window focus can be confirmed and field targeting remains stable.

### Credit-application UI preview — 16 Nov 2026

The memo correction was explicitly deferred. On the verified isolated company, Pay Bills filtered to the synthetic vendor showed open bill `TPZQB-BILL01` at AED15. Set Credits showed saved credit `TPZQB-CREDIT01` at AED5 and proposed using AED5, leaving credit AED0 and cash/check amount AED10. After selecting Done, the unsaved Pay Bills grid showed AED5 Credits Used and AED10 Amt. to Pay. Footer values were payment date 16 Nov 2026, method Check, account Cash A/c and “To be printed.” No payment was saved, so the application remains a preview rather than a posted allocation.

B2 should support credit application as its own draft allocation record linked to both supplier credit and bill; display source IDs, available credit, bill open amount, amount allocated, remaining credit, remaining bill and any separate cash payment. Saving a credit-only application must not manufacture a cash payment; if the same workflow also prepares payment, require a distinct, fully visible payment summary. Refresh AP Aging and reconcile the supplier subledger/control account only after a posted test is available. Do not edit the QuickBooks memo or activate ERP posting from this preview evidence.

### Saved vendor-credit application and payment — 16 Nov 2026

The Owner saved the prepared Pay Bills selection. QuickBooks Payment Summary confirmed AED10.00 paid on 16 Nov 2026 by Check from Cash A/c against `TPZQB-BILL01` (due 26 Nov). The Set Credits preview immediately before save showed the full AED5.00 from `TPZQB-CREDIT01` applied, lowering the bill's AED15.00 remaining amount to AED10.00 cash due. Product Information confirmed the isolated test file and transaction count 1,919.

Saved payment detail linked AED10 to the bill; Transaction Journal #1914 balances Dr Accounts Payable AED10 / Cr Cash A/c AED10. Vendor Center showed zero balance after the original AED25 bill, previous AED10 payment, AED5 credit, and this AED10 payment. The refreshed all-vendor AP Aging Summary totaled AED37,215.23 and omitted the zero-balance synthetic vendor. This verifies the basic bill+credit+payment settlement path, but not separate credit-application journal behavior, post-payment AP Aging Detail, or Trial Balance/control reconciliation.

B2 design acceptance should preserve three distinct linked records/events: the supplier bill, vendor credit and allocation, and cash payment. The payment journal should link to the bill payment and cash account; the credit posting remains its own balanced journal. Store the allocation amount and source IDs so bill due, unused credit, applied credit, cash payment, and supplier balance can be reconstructed without treating the allocation as a duplicate expense or duplicate inventory event. Provide a post-save summary with these components and verify them against refreshed subledger and reports. The saved credit memo remains untouched; no TPZ code or posting configuration was changed by this test.

### AP close-out reconciliation for synthetic supplier — 16 Nov 2026

Read-only QuickBooks evidence explains the supplier's AED0.00 balance: one AED25.00 bill, two separately recorded AED10.00 Bill Pmt-Check transactions, and one AED5.00 vendor credit. Vendor Center's full visible transaction history shows exactly these four rows and a zero balance. The sole credit is AED5.00; no discount was used. Balance bridge: 25 - 10 - 5 - 10 = 0. The second AED10 payment accounts for the AED10 difference in the earlier abbreviated report. Payment journals #1912 and #1914 each debit A/P and credit Cash A/c AED10, balanced. The credit's previously captured journal #1913 debits A/P and credits Office Supplies AED5. The bill form is marked PAID.

The post-payment A/P Aging Detail is filtered to Open items and therefore excludes this zero-balance vendor. All-vendor A/P Aging Summary total and the accrual Trial Balance A/P credit balance both equal AED37,215.23 as of 16 Nov 2026. Trial Balance totals are AED460,705.36 debit and credit. This is a successful report-level A/P control tie-out; B2 should expose the same basis/as-of filters and report differences before close.

**Preserve date and number conflicts:** The bill form shows 18 Nov date and 20 Nov due date, but its Transaction Journal shows 16 Nov and current screen Trans #1911; the payment allocation/report showed 26 Nov due. Earlier documents reported bill journal #1941 and due 26 Nov. These discrepancies are unresolved. Do not infer or overwrite stored dates, and require transaction source identifiers/read-back when importing historical bills.
