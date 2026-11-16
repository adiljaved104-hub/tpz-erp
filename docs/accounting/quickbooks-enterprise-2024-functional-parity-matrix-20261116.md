# QuickBooks Desktop Enterprise 2024 — Functional Parity Matrix

**Status date:** 16 Nov 2026  
**Evidence detail:** [Live findings and evidence boundaries](quickbooks-enterprise-2024-live-findings-20261116.md)  
**ERP branch:** `codex/accounting-b2-quickbooks-alignment-20261116`  
**Posting boundary:** B2 source records remain draft-first. No automatic journals or operational stock changes are enabled.

## Classification key

- **Verified in QuickBooks** — directly observed in the isolated Enterprise 24.0 UI or returned by an earlier successful, read-only SDK response.
- **Partially verified in QuickBooks** — a menu, form or report was observed, but transaction behavior or accounting effects were not.
- **Implemented in TPZ** — present in the inspected B1/B2 code; this label is not runtime verification.
- **Partially implemented in TPZ** — only part of the workflow/data model exists.
- **Missing in TPZ** — no native capability found in the inspected B1/B2 scope.
- **Owner approval required** — policy, legal, accounting, access or posting decision must be approved before production behavior is enabled.
- **Not verified in QuickBooks** — evidence is not available yet; do not infer a QuickBooks result.

## Matrix

| Module / capability | QuickBooks classification and actual evidence | TPZ classification and current counterpart | Required improvement / priority |
|---|---|---|---|
| Chart of Accounts | **Verified in QuickBooks** — earlier bounded AccountQuery returned hierarchy, account types, currency refs, active flag, balances and tax-line fields; product info showed account count. | **Implemented in TPZ B1** — book-scoped accounts, types, normal balance, active/posting controls and COA page. | Add parent/subaccount UX, account currency policy, include-inactive behavior, control-account validation, report/register drill-through. **P1** |
| General Journal | **Partially verified in QuickBooks** — journal form fields and Reverse/Delete UI were observed earlier; no journal was saved and no posted journal/report relationship queried. | **Implemented in TPZ B1** — draft/post/reversal ledger model and permissions; posting gate remains disabled. | Verify QuickBooks line types, adjustment behavior, transaction journal, reversal date/link, approvals and audit history. Require Owner/accountant policy before activating posting. **P1 / Owner approval required** |
| General Ledger | **Partially verified in QuickBooks** — accrual GL showed the test invoice's AED 100.00 debit to A/R for 16 Nov; a full transaction-line export and all account postings were not inspected in GL. Invoice Transaction Journal showed both A/R/Sales and COGS/Inventory Asset. | **Implemented in TPZ B1** — posted journal line query/page. | Verify same-date account drill-down and subledger control totals; add TPZ filters, running balances, export and source link when approved. **P1** |
| Trial Balance | **Verified in QuickBooks for test date** — accrual report for 1–16 Nov refreshed after invoice; displayed debit/credit totals agreed and Sales/COGS rows reflected the test invoice. Company-wide totals intentionally omitted. | **Implemented in TPZ B1** — derived from posted journal lines. | Verify date basis, zero-account inclusion, signs, rounding and tie to GL and subledgers across additional periods. **P1** |
| Accounting periods / closing | **Not verified in QuickBooks** — no close-date setting inspected. | **Implemented in TPZ B1** — fiscal-period and lock foundation. | Confirm close/reopen roles, adjustments and audit evidence; owner approves policy. **P1 / Owner approval required** |
| Customer and customer-job master data | **Verified in QuickBooks** — earlier CustomerQuery returned source IDs, hierarchy schema, currencies, tax-code refs, balances and timestamps. Customer-job relationship was not demonstrated in returned records. | **Partially implemented in TPZ** — operational customer data exists; no complete Accounting customer/job subledger. | Map QB parent/customer-job identities without duplicating ERP customer records; define source ID and inactive mapping. **P1** |
| Sales invoices, payments, credits and refunds | **Partially verified in QuickBooks** — one invoice `TPZQB-INV01` was saved for the synthetic customer; Customer Center showed AED 100.00 open balance and Transaction Journal verified A/R, sales, COGS and inventory postings. Receive Payments loaded the invoice but UI focus prevented reliable amount entry; no payment, credit or refund was saved. | **Missing in TPZ Accounting B1/B2** — operational Sales/Tax Invoice/Returns data exists, but no complete AR accounting subledger. | B3: invoice lines, tax, partial/unapplied receipts, application links, credit/refund and aging; keep one operational invoice and link accounting source rather than creating duplicate sales documents. **P1** |
| Supplier/vendor master | **Verified in QuickBooks** — Vendor Center filter/search/list/history observed; earlier VendorQuery returned IDs, currency, active flag and timestamps. | **Implemented operationally; partial for Accounting** — ERP supplier entities exist; B2 links by supplier ID. | Preserve one supplier identity; map source IDs, terms, currency and inactive status. **P1** |
| Supplier bill entry | **Verified in QuickBooks UI** — blank Bill/Credit form exposed supplier, reference, dates, terms, class, memo, expenses/items tabs and line-level controls; no save/posting tested. | **Partially implemented in TPZ B2** — draft bills with multiple line storage and VAT math; this branch adds a repeatable multi-line form. Account/item mapping, purchase link in UI, terms, class and attachment workflow are absent. | Add account/item line types, receipt/PO selector, supplier document duplicate guard, terms, class, tax mapping, attachment and audit details. Keep bill and receipt as linked source events; do not create duplicate purchases/GRNs. **P0** |
| Bill payment and allocation | **Partially verified in QuickBooks** — Pay Bills menu and Vendor Center transaction history observed; payment form/application and GL effect not tested. | **Partially implemented in TPZ B2** — draft payments, explicit allocations and over-allocation checks. No bank/check/method/clearing link. | Test partial/full/overpayments and unapplied cash; add payment method and bank account/reference and reverse/void workflow after accounting design approval. **P0** |
| Supplier credits/debit notes | **Partially verified in QuickBooks** — Bill/Credit selector, Enter Bills & Vendor Credits menu path and report category observed; no credit application tested. | **Partially implemented in TPZ B2** — draft credit/debit adjustment records. Aging does not yet apply credits to bill-level balances. | Add adjustment-to-bill allocation, apply/unapplied credit balances, returns linkage and audit trail; test before changing AP control. **P0** |
| Supplier statement | **Not verified in QuickBooks** — no statement generated or statement menu behavior inspected. | **Missing in TPZ Accounting B2.** | Inspect statement date range, open-item detail, payments/credits and ending balance; implement supplier statement/ledger export after rules are verified. **P2** |
| AP Aging | **Verified in QuickBooks UI** — Summary and Detail reports populated; current/1–30/31–60/61–90/>90 buckets, as-of date, interval, vendor filter, sort, search, collapse/expand and Excel/print actions observed. | **Partially implemented in TPZ B2** — selected-supplier open-bill detail with as-of date; this branch adds the same bucket labels. No all-supplier summary/bucket totals; credits do not reduce bill-level aging. | Add all-supplier summary, bucket totals, report filters/exports, credit application and AP-control reconciliation. **P0** |
| PO, receiving and supplier bill relationship | **Partially verified in QuickBooks** — Create Purchase Orders form and its item-receipt/close/approval controls were observed; synthetic vendor selected, but grid cells were not safely targetable, so no PO was saved and no receiving/bill relationship was tested. | **Partially implemented in TPZ** — operational Purchases and PurchaseReceipts remain source records; B2 service supports a `purchase_id`, but the AP screen does not link it. | Specify and test received-not-billed accrual; link purchase, receipt and supplier bill by stable IDs; exactly-once event key prevents duplicate inventory/AP. **P0 / Owner approval required for posting rule** |
| Inventory accounting and COGS | **Verified for the test sale** — Transaction Journal posted AED 291.99 to COGS and credited Inventory Asset by the same amount; Inventory Valuation Detail showed invoice quantity -1, unit cost AED 291.99 and post-sale on-hand 48 (picker showed 49 pre-sale). Purchase-side valuation/receipt costing remains untested. | **Partially implemented operationally; missing accounting bridge** — inventory/purchase/sales flows are separate from B1 GL. | Verify receipt, bill, fulfillment, return, cost and COGS sequence. Preserve operational stock events and account from their IDs; do not replay or mutate inventory. **P0 / Owner approval required** |
| Banking, deposits, transfers and reconciliation | **Partially verified in QuickBooks** — Home Page and Reports menu expose banking/deposit/reconcile workflows; no bank form or reconciliation report opened. | **Missing in Accounting B1/B2** — no bank subledger/reconciliation implementation in scope. | B4: bank accounts, payment/deposit linkage, statement import, match/split, cleared state, reconciliation lock and correction history. **P1** |
| Expenses and petty cash | **Partially verified in QuickBooks** — expense/check/Banking workflows are visible; no petty-cash behavior or posting inspected. | **Missing in B1/B2 Accounting** — operational office finance remains a separate cashbook. | Design business cash/expense capture and accounting-book boundaries; do not silently merge PKR cashbook with AED accounting. **P2 / Owner approval required** |
| VAT / tax | **Partially verified in QuickBooks** — tax code/item master objects were returned earlier; no tax liability report or purchase-tax posting inspected. | **Partially implemented operationally; missing VAT accounting subledger** — tax invoices exist, no verified GL VAT bridge. | Verify purchase tax codes, recoverability, tax reports, credit notes and UAE-specific mapping with accountant. Do not copy US QuickBooks tax behavior as UAE policy. **P0 / Owner approval required** |
| Multicurrency and FX | **Verified in QuickBooks master data** — earlier queries returned AED/CAD/EUR/JPY/USD currencies and account/item currency refs; journal screen showed currency/rate fields in earlier UI review. No FX settlement/revaluation tested. | **Partially implemented** — B2 records currency but currently books use a single book currency for payment validation; transaction FX and realized/unrealized gain/loss subledger absent. | Preserve source and home currency amounts and effective rate per transaction; decide revaluation, rounding and settlement policies with accountant. **P1 / Owner approval required** |
| Financial statements / management reports | **Verified in QuickBooks for test date** — accrual P&L (Nov 1–16) reflected Sales 100.00, COGS 291.99 and test-period net income -191.99; Balance Sheet as of Nov 16 showed the same net income and balanced assets vs liabilities/equity. Inventory Valuation Detail independently showed the test sale quantity/cost. Company totals omitted. | **Partially implemented in TPZ B1** — GL and TB; P&L, Balance Sheet, Cash Flow and management reports missing. | Implement B6 with equivalent basis/date controls, account drill-down, source links, subledger tie-outs, exports and reconciliation. **P1** |
| Transaction journal/history and audit trail | **Partially verified in QuickBooks UI** — Transaction Journal for `TPZQB-INV01` was opened and showed balanced invoice and inventory-cost entries. Transaction History and edit/void/delete audit behavior remain untested. | **Partially implemented in TPZ B1/B2** — source/audit fields and activity log exist; operational-to-accounting transaction linkage and full old/new audit screen are incomplete. | Extract `TxnID`, `EditSequence`, line/link IDs, statuses and modification timestamps; support immutable corrections, reversals and migration audit. **P0** |
| Historical data migration | **Partially verified in QuickBooks** — read-only master data qbXML returned earlier; no historical transaction export executed. | **Missing in Accounting B1/B2** — no complete transaction import/migration tool. | Prototype qbXML source extraction; preserve IDs, lines, allocations, currency, tax, status and timestamps. Use Excel/CSV as reconciled fallback, not source of truth. Treat IIF as non-primary; Intuit recommends SDK rather than IIF. **P0** |

## Accounting B2 recommendations

1. Keep bills, payments and credits draft-first. Approval should not post until the Owner/accountant signs the receipt-vs-bill accrual, inventory valuation, VAT and payment mapping policies.
2. Extend the new multi-line draft bill UI with Expense/Item line types, account/product, tax code, class, customer/job, PO/receipt references, attachments and duplicate-document protection.
3. Add explicit payment and credit allocation screens with partial, overpayment/unapplied and refund cases. Apply credits to open bill balances before producing aging totals.
4. Build AP Aging Summary across suppliers with the QuickBooks date buckets and a transaction-detail view; reconcile every report to supplier balances and a later AP control-account report.
5. Derive accounting records from existing purchase, receipt, supplier and inventory source IDs; never create a second operational purchase/GRN to represent accounting. Enforce unique `(book, source type, source ID, event)` keys.
6. Do not enable journal posting, stock mutation or production rollout. Follow Development → Staging → Owner UAT → Main as a separate approved promotion sequence.

## Remaining inspection blockers

- QuickBooks was open on the isolated test copy at the latest checkpoint, and Product Information confirmed the full `qb-isolated-test-20261116` path. The purchase-order item grid and Receive Payments amount control remain unsafe to automate: accessibility does not expose reliable editable cells and earlier coordinate entry did not verify focus.
- SDK transaction-level queries, current-session authorization state, report requests and exports have not been revalidated in this continuation. Do not claim SDK readiness based on earlier sessions.
- Supplier bill/payment/credit application, supplier statements, customer receipts/credits/refunds, banking and reconciliation, VAT, multicurrency settlements, journal corrections, report drill-down/history, and historical exports remain outstanding.

### Superseding observation — customer receipt and statement (16 Nov 2026)

A controlled partial customer payment is now verified: AED 40.00 applied to `TPZQB-INV01` for AED 100.00, remaining balance AED 60.00. Transaction Journal: Dr Undeposited Funds 40.00 / Cr A/R 40.00, transaction #1939; accrual Trial Balance remained balanced. This corrects the stale matrix rows above that said no payment was saved. QuickBooks Create Statements preview for the synthetic customer showed invoice 100.00, payment 40.00, ending/current balance AED 60.00. It was preview-only; no print/email/save action.

| Corrected QuickBooks capability | Current classification | TPZ parity action |
|---|---|---|
| Partial receipt applied to invoice | **Verified in QuickBooks** — AED 40 applied to AED 100 invoice; remaining A/R 60; debit Undeposited Funds, credit A/R; journal #1939. | **Partially implemented in TPZ B2** payment/allocation drafts; add auditable allocation history, settlement/clearing account policy and customer-side statement. Keep posting disabled pending policy approval. **P0** |
| Customer statement | **Verified in QuickBooks preview** — selected-customer date range, open-transaction option, template, detail/due-date/PO toggles, suppress filters; preview reconciled 100 invoice less 40 receipt to 60 due. | **Missing in TPZ Accounting B1/B2** — provide an AR statement/ledger view over canonical sales records and receipts without duplicate operational invoices. **P2** |
| Purchase order save/receipt | **Not verified** — wrong vendor selection/focus encountered; blank PO discarded, no record persisted. | Keep PO-to-receipt-to-bill links in B2; avoid claiming QuickBooks purchasing behavior until a safely targeted PO is completed. |

### Banking / journal / currency UI delta (16 Nov 2026)

| Area | QuickBooks result | TPZ parity and priority |
|---|---|---|
| Reconciliation setup | **Partially verified** — setup fields and Locate Discrepancies/Undo Last Reconciliation/Continue controls observed; canceled before proceeding. No reconciliation result tested. | **Missing B1/B2** — B4 requires statement import, matched/cleared states, beginning/ending balance proof, discrepancy review, close/lock and audit. **P1** |
| Bank transfer | **Partially verified** — blank form showed source/destination accounts, date, class, currency, amount, memo and exchange-rate fields; not saved. | **Missing B1/B2** — B4 must preserve both bank movements and apply FX policy once, without duplicate cashbook events. **P1** |
| General journal UI | **Partially verified** — currency/home-currency adjustment, date/rate/entry no/adjusting flag and Account/Debit/Credit/Memo/Name/Billable/Class line columns; Reverse action visible. No journal saved. | **Implemented foundation** in B1; add currency/rate detail, adjusting-entry policy, source links, approvals and reversal audit. Posting stays disabled pending approval. **P1** |
| Currency management | **Partially verified** — Download Latest Exchange Rates, Currency Calculator, Home Currency Adjustment and Currency List; list includes code/rate/as-of date and inactive filter. No rate change or revaluation. | **Partially implemented** B2 currency field; B5 needs effective-dated FX rates, source/home amounts, settlement and realized/unrealized FX reporting. **P1 / Owner-accountant policy** |

### Superseding evidence — full settlement and UI results (16 Nov 2026)

After Owner action-time approval, AED 60.00 Cash was applied to the remaining balance of `TPZQB-INV01`. Customer Center shows invoice AED 100.00, payments AED 40.00 and AED 60.00, and synthetic customer balance 0.00. Product Information reconfirmed the isolated file and count increased to 1,915. Second payment Transaction Journal #1940: Dr Undeposited Funds AED 60.00 / Cr A/R AED 60.00; balanced. No deposit occurred. The FY-to-date Trial Balance was opened through Nov 16, but its totals were not verified in the visible portion; do not claim a tie-out after the second payment. This supersedes any older claim that full payment was not tested.

| Workflow | Updated status | Evidence boundary |
|---|---|---|
| Partial customer receipt | **Verified** | AED 40 applied; remaining due 60; journal #1939; TB remained balanced after this first receipt. |
| Full settlement of invoice | **Verified** | AED 60 applied; customer balance zero; journal #1940 balanced; TB opened after payment but total tie-out pending. |
| Customer statement | **Verified in preview** | Synthetic-only preview showed invoice 100, receipt 40, balance 60; preview preceded final 60 receipt. No delivery/save. |
| Deposit / bank settlement / reconciliation | **Not verified** | Both receipts remain in Undeposited Funds; no deposit or reconciliation was performed. |

- **Supplier bill UI update:** launching Enter Bills from the exact synthetic vendor in Vendor Center preselected that vendor in AED. In the maximized form, the Expenses grid allowed selection of existing AED Office Supplies and visible entry of a single AED 25.00 line with a synthetic memo; the header reference was `TPZQB-BILL01`. The displayed date was 18 Nov 2026 and due date 20 Nov 2026. The draft remains **unsaved** pending action-time confirmation. This improves UI-targeting evidence but does not verify AP posting or a supplier bill's Transaction Journal.
- **Superseding supplier bill result (16 Nov):** the earlier “unsaved” status and intended dates below are superseded. Synthetic bill `TPZQB-BILL01` was saved for AED 25.00 to Office Supplies. Transaction Journal #1941 showed Dr Office Supplies / Cr A/P AED 25.00; Vendor Center and synthetic-vendor A/P Aging each showed AED 25.00. The approved date request (18 Nov, due 20 Nov) did not persist: saved journal and Pay Bills showed 16 Nov and due 26 Nov. Pay Bills amount editing then raised a two-decimal warning and ambiguous cell text; no supplier payment was saved.

| New supplier evidence | QuickBooks classification | TPZ parity action |
|---|---|---|
| Supplier bill posting | **Verified** — synthetic AED25 bill, transaction #1941; Office Supplies debit and A/P credit, vendor balance and aging tied. Date-entry discrepancy remains. | B2 needs a pre-save confirmation summary of vendor, reference, currency, transaction/due dates, account/item lines, tax and total. **P0** |
| Supplier partial payment | **Pending** — Pay Bills displayed AED25 due, but the amount cell was not safely controllable; no payment saved. | Provide exact partial allocation and open-balance preview; validate before enabling posting. **P0** |
| A/P Aging after supplier bill | **Verified** for one synthetic vendor — Current AED25, older buckets zero, total AED25 as of 16 Nov. | Add all-supplier bucket totals, credit application and reconciliation to A/P control. **P0** |

### AP follow-up classification — 16 Nov 2026

| Capability | QuickBooks status from this continuation | B2 implementation requirement |
|---|---|---|
| Saved bill date/due date | **Partially verified** — journal date 16 Nov and Pay Bills due date 26 Nov conflict with the draft's intended 18/20 Nov. Cause remains unverified; no edit made. | Final review screen must display persisted dates and terms; after save, reopen/read back transaction and compare. Add date/timezone/terms regression cases before release. **P0** |
| Pay Bills setup | **UI verified, posting untested** — vendor bill selection, due 25, method Check, payment date and account Cash A/c fields observed; selection filter and discount/credit controls present. | B2 payment draft needs bill-level allocation grid, payment method/account, date/reference, applied/unapplied amounts, and remaining-balance preview. **P0** |
| Partial supplier payment | **Not verified** — malformed amount control showed `10.0025.00` and a 2-decimal warning; no transaction saved. | Don't mark partial-payment parity complete until amount entry/remainder and GL/AP effects tie out. **P0** |
| Vendor credits | **UI affordance only** — Bill/Credit selector and Set Credits button observed. No credit or application. | Implement supplier credit/debit-note draft and application ledger linked to bill, purchase return and AP aging; verify signs and unallocated balance. **P0** |
| Purchase order / receipt / inventory buy | **Navigation/form affordances only** — Create Purchase Orders, Receive Items, Enter Bill for Received Items and Select PO/Receive Items controls observed. No source links or posting effects tested. | Link existing purchase and receipt records by source IDs; define received-not-billed accrual and inventory valuation before posting. Avoid duplicate GRN/stock events. **P0** |
| A/P Aging | **Previously verified for one synthetic vendor** — post-bill Current 25 / Total 25. No refresh performed in this turn. | Build all-vendor summary/detail, as-of logic, credit treatment and AP-control reconciliation. **P0** |

### AP continuation update — current UI observation (16 Nov 2026)

| Capability | Updated QuickBooks evidence | TPZ parity requirement / priority |
|---|---|---|
| Bill date / due date / terms | **Inconsistent, unresolved.** Saved journal date was 16 Nov; prior Pay Bills observation showed due 26 Nov; current Pay Bills list shows due 28 Nov. Terms was visually blank on the previously opened saved bill. The Terms dropdown offered standard options, none selected. No edit made and no rule inferred. | Store transaction date, terms ID and whether due date is manual/derived; preview and read back the due date; flag mismatch. **P0** |
| Pay Bills amount and allocation | **UI verified, transaction untested.** Selected bill shows AED25 due; accessibility shows unsaved AED10.00 in amount edit; lower payment date/account fields are clipped. No payment or partial remainder/GL result claimed. | Show date, method, cash/bank account and bill-level allocations together; preview applied, unapplied and remaining amounts; reject ambiguous precision/text. **P0** |
| Discount and credits | **UI affordance only:** Set Discount and Set Credits and discount/credit-used columns. No amounts applied. | Model credits and discounts as separate allocations with source links, preview and audit. **P0** |
| AP Aging | **Previously verified** for one synthetic vendor post-bill; current report filters are Open, all A/P accounts, Today and multiple names, but no current result rows verified. | Explicit as-of date, all-vendor summary/detail, credit/unapplied treatment, buckets and AP-control reconciliation. **P0** |
| PO / goods receipt / inventory purchasing | **Not transactionally verified.** Current pass avoided navigation while transient payment amount was present; earlier controls are affordance-only. | Link existing PO and GRN via canonical IDs, prevent duplicate receipts/stock movement, reconcile quantities/costs, await received-not-billed policy. **P0** |

No supplier payment was created. The current due-date observation (28 Nov) differs from the earlier 26 Nov capture; preserve this as an unresolved discrepancy, not an assumed rule.

### Owner-saved supplier payment — verified balance effect (16 Nov 2026)

After the Owner reported paying the synthetic bill, Product Information verified the isolated company path and transaction count 1,917 (previously 1,916). Vendor Center for the synthetic vendor now shows AED15 balance; refreshed A/P Aging Summary as of 16 Nov shows Current15 / Total15. The prior bill balance was AED25, so AED10 settlement is inferred from the balance delta, not directly observed in payment detail. The payment is reflected in the vendor subledger and aging.

| Capability | Updated status | TPZ B2 requirement |
|---|---|---|
| Supplier partial settlement | **Balance effect verified; transaction detail partial.** Vendor balance/AP Aging 25→15; AED10 is inferred. | Reconcile payment source, applied amount, cash/bank credit and A/P debit to payment detail and journal before calling posting parity complete. |
| Payment date/method/account/reference | **Not directly verified.** | Require explicit payment metadata and immutable payment-to-bill allocations with audit references. |
| Bill due date | **Conflicting UI reads.** Saved Enter Bills shows 26 Nov; Pay Bills list previously showed 28 Nov. | Persist/read back stored due date and distinguish report refresh/date-basis behavior. |

No further payment was created by the investigator. The supplier payment's journal and metadata remain open verification items; do not recreate or duplicate it.

### Supplier payment and purchasing inspection update — 16 Nov 2026

| Capability | Verified QuickBooks evidence | TPZ parity gap / next priority |
|---|---|---|
| Supplier partial payment | **Verified.** Synthetic `TPZQB-BILL01` received Bill Pmt-Check #1912 for AED10 dated 16 Nov 2026 to Cash A/c; Vendor Center showed bill AED25 and payment AED10; the payment form showed AED10 applied to that bill. | Expose payment ID/reference, check number, date, method, account and per-bill allocation; show remainder and preserve immutable allocation history. **P0** |
| Payment journal | **Verified balanced.** Dr A/P AED10 / Cr Cash A/c AED10; both sides total AED10. | Link journal to payment and bill source IDs; reconcile subledger and control account before posting enablement. **P0** |
| AP Aging Summary and Detail | **Verified** as of 16 Nov 2026: Current AED15 / Total AED15; detail row due 26 Nov, open AED15. | Add all-vendor summaries/details and credits/unapplied treatment; date-bucket boundary and AP-control reconciliation tests. **P0** |
| Vendor credit | **Form inspected only:** Credit form contains vendor/date/ref/credit amount/class/memo and expense/item tabs. No credit saved or applied. | Draft credit and allocation ledger; clearly separate unused and applied credit; validate signs and audit trail. **P0** |
| Purchase orders | **No transaction exists in reference file** under all-date PO list. Blank form shows vendor, class, ship/drop-ship, date/PO number, item/description/quantity/rate/customer/amount, Mark As Closed and receipt actions. | Link to existing TPZ purchases without duplicating operational records; approval, amendments, partial receipt and closure states. **P1** |
| Item receipts / bill from receipt | **No item receipts exist** in all-date list. Synthetic vendor receipt selector was empty; date/reference/memo columns and use receipt date option observed. | Link an existing warehouse GRN by canonical ID; verify quantity/cost and bill matching; do not create another receipt. **P0** |
| Inventory purchasing and receipt GL | **Not verified.** No PO/receipt/inventory purchase was saved. | Owner/accountant policy for received-not-billed accrual, inventory valuation, VAT and duplicate stock prevention remains required. **P0 / policy** |
+
+The bill due date is corroborated as 26 Nov by saved bill form, payment allocation row and AP Aging Detail; a prior Pay Bills list showed 28 Nov. Preserve that display inconsistency for a UI regression investigation. No additional transaction was created.

### Vendor credit posting and aging behavior — verified (16 Nov 2026)

Owner-saved synthetic credit `TPZQB-CREDIT01` for AED5 dated 16 Nov 2026 appears in Vendor Center beside bill AED25 and bill payment AED10. Its Transaction Journal #1913 is balanced: Dr A/P 5, Cr Office Supplies 5. Vendor Center balance is AED10. Refreshed A/P Aging Summary shows Current/Total AED10; AP Aging Detail separates the credit as -AED5 open from the bill's AED15 open (due 26 Nov), so the credit is not applied to the bill even though the report/vendor summary nets them. This verifies credit entry and net Aging treatment; actual credit application is still untested.

| Capability | QuickBooks status | TPZ B2 parity requirement |
|---|---|---|
| Vendor credit posting | **Verified** — credit form expense line AED5 to Office Supplies; balanced Dr A/P / Cr expense journal #1913. | Preserve source credit and line allocations; produce balanced linked journal when posting policy is approved. |
| Unapplied credit aging | **Verified** — detail shows separate negative credit 5 and open bill 15; summary/vendor balance net 10. | Keep bill due amount and unapplied credit distinct; show both gross open AP and net supplier position; require explicit allocation to reduce bill open amount. **P0** |
| Credit application | **Not tested** — no application was made. | Add draft allocation, preview, remaining-credit and remaining-bill balances, and audit trail. **P0** |
| Credit memo quality | **Issue observed** — saved memo unexpectedly contains prior prompt text from unstable UI text entry. No correction made. | Validate/preview memo exactly before save; add audit-safe correction and preserve original memo history. |

No additional QuickBooks record was changed during read-back.

**Testing constraint:** Saved-credit memo correction is pending. Ctrl+A intended for the memo field opened Chart of Accounts due to MDI focus loss; no transaction was changed. Resume only after reliable child-window control is restored. Credit application, payment edge cases, PO/receipt/inventory postings, and broader modules remain pending.

### Credit application preview — 16 Nov 2026

The saved memo remains unchanged. In Pay Bills, filtered to the synthetic test vendor, the open bill `TPZQB-BILL01` showed AED15 due. Set Credits identified `TPZQB-CREDIT01` (AED5), offered the entire AED5 for use, and showed bill payment amount AED10 after allocation. After clicking Done, the unsaved list showed AED5 Credits Used and AED10 Amt. to Pay. The payment footer showed 16 Nov 2026, Check, Cash A/c, and “To be printed.” The payment was not saved, so no posted bill/credit balance or journal change is claimed.

| Functionality | QuickBooks classification | TPZ ERP status / required work |
|---|---|---|
| Select available vendor credit for a highlighted bill | **Verified in UI preview** | **Missing/partial:** implement a draft credit-allocation selector with source transaction, available credit and bill balance. **P0** |
| Allocate all AED5 against AED15 due | **Verified in UI preview:** AED5 used; credit remainder AED0; proposed payment AED10 | **Missing/partial:** show applied, unapplied, and residual values before save; prevent allocations beyond either open balance. **P0** |
| Persist credit application and refresh subledger/AP Aging/GL | **Not verified; unsaved** | Do not mark as posted parity until Owner performs save and report/journal tie-out is captured. **P0** |
| Save the accompanying AED10 check payment | **Not performed** | Owner final save required; capture payment reference and debit/credit effects after save. **P0** |

This updates the prior “Credit application: Not tested” status to **UI preview verified; saved financial application pending**. The memo correction remains deliberately deferred.

### Owner-saved credit application and payment — 16 Nov 2026

Superseding the preview-only status above: the Owner saved the selected bill payment after the AED5 credit was applied. Payment Summary showed date 16 Nov 2026, Cash A/c, Check, synthetic vendor, bill due date 26 Nov, AED10 paid and AED10 total. The preceding credit dialog showed all AED5 from `TPZQB-CREDIT01` used against `TPZQB-BILL01`, reducing the prior AED15 due to AED10. Product Information reconfirmed the isolated file and transaction count 1,919 (up from 1,918).

The saved Bill Payments (Check) form linked the AED10 payment to `TPZQB-BILL01` (original AED25, prior amount due AED10 after earlier payment and credit). Transaction Journal #1914 is balanced: Dr Accounts Payable AED10 / Cr Cash A/c AED10. Vendor Center showed synthetic vendor balance zero and four relevant history rows: bill AED25, prior payment AED10, credit AED5, new payment AED10. Refreshed A/P Aging Summary as of 16 Nov showed overall total AED37,215.23 and omitted the zero-balance synthetic vendor. No post-payment Aging Detail or Trial Balance/control-account reconciliation was completed.

| Functionality | QuickBooks classification | TPZ ERP status / required work |
|---|---|---|
| Credit allocation applied to bill | **Verified in saved workflow:** AED5 credit applied to the AED15 open amount; proposed cash due AED10. | **Partial:** persist the credit-to-bill allocation relationship and retain applied/unapplied states. **P0** |
| Supplier payment | **Verified:** Bill Pmt-Check, journal #1914, date 16 Nov, Cash A/c, AED10, linked to `TPZQB-BILL01`; balanced Dr A/P / Cr cash. | **Partial:** ensure payment ID/reference, exact per-bill allocation, credit application, and post-save balance read-back are auditable. **P0** |
| Final vendor balance | **Verified:** Vendor Center shows zero after bill 25 less payment 10, credit 5, payment 10. | Prevent duplicate allocation; show gross bill and credit history as well as net balance. **P0** |
| AP Aging / control reconciliation | **Partially verified:** refreshed company summary is AED37,215.23; zero-balance test vendor is omitted. | Verify detail inclusion rules and reconcile full AP subledger to control/Trial Balance before parity sign-off. **P0** |

This supersedes “saved financial application pending” above. The credit memo remains unchanged. Overpayment, discounts, refunds, void/reversal, and PO/receipt/inventory tests remain pending.

### Supplier reconciliation — superseding AP balance bridge (16 Nov 2026)

Read-only Vendor Center history for the exact synthetic vendor shows balance AED0.00 and four entries: one AED25.00 bill `TPZQB-BILL01`, two separate AED10.00 Bill Pmt-Check entries from Cash A/c, and one AED5.00 credit `TPZQB-CREDIT01`. The visible history filters are All transaction types / All dates. Thus the previously summarized AED10 payment was incomplete: there is a second AED10 payment. The sole visible credit totals AED5; no additional vendor credit/adjustment appears in this history. Discount used is AED0.00. Reconciliation: AED25 bill - AED10 first payment - AED5 credit - AED10 second payment = AED0 open balance.

The earlier payment Transaction Journal #1912 was re-opened and shows Dr A/P AED10 / Cr Cash A/c AED10, balanced. The second payment Transaction Journal #1914 was previously verified with the same entry. The credit form shows AED5 and Office Supplies; its previously captured balanced journal is #1913 (Dr A/P 5 / Cr Office Supplies 5). The saved bill form displays PAID. No separate credit-application journal was identified.

Post-payment A/P Aging Detail is dated 16 Nov and filters Paid Status to Open, so the fully settled vendor is excluded. A/P Aging Summary total is AED37,215.23. Accrual Trial Balance through 16 Nov shows A/P credit AED37,215.23 and total debits/credits AED460,705.36 each. This supports a report-level tie-out between aging and the A/P control account, while no separate transaction-level subledger export was performed.

| Reconciled capability | QuickBooks status | TPZ action |
|---|---|---|
| Bill outstanding balance | **Verified zero** — Vendor Center shows 0, bill form shows PAID, and Open-only A/P Aging excludes the vendor. | Calculate open balance from original bill less applied payments, credits and discounts; retain allocation history. **P0** |
| Total vendor credit | **Verified AED5.00** — one Credit row in the filtered vendor history. | Prevent duplicate credit imports and distinguish total, applied and unapplied credit. **P0** |
| Total payments | **Verified AED20.00** — two separate AED10 Bill Pmt-Check entries. | Show every payment ID and allocation; don't collapse same-date/same-amount transactions. **P0** |
| Discounts | **Verified AED0.00** — no discount row; Pay Bills discount used was zero. | Keep discount separate from payment and credit allocations. **P1** |
| AP aging vs. control | **Tied at report level:** AED37,215.23 vs. AED37,215.23; Trial Balance totals balance at AED460,705.36 each. | Require the same as-of date, currency, basis and status; provide reconciliation exceptions. **P0** |

**Correction to earlier bill references:** The current bill Transaction Journal screen shows transaction #1911 and posting date 16 Nov, while the saved bill form shows date 18 Nov / due 20 Nov and PAID. The payment row showed bill due date 26 Nov. Earlier docs called the bill journal #1941 and reported 16/26 Nov; retain the conflicting reads and do not edit the bill. The amount and Dr Office Supplies / Cr A/P AED25 posting are directly verified. The credit journal was previously identified as #1913.
