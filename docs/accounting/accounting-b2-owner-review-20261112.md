# TPZ Accounting — Owner Review Deliverable

**Date:** 2026-11-12
**Prototype:** [Interactive Accounting prototype](prototype/index.html)
**Baseline:** `integration/accounting-b1-staging-20261111` at `246d1dbf8c8462d0351ab558fe9add3cfec33a84`
**Status:** Discovery and design only. No B2–B7 implementation, ledger activation, migration, financial-data change, deployment, or promotion is included.

## 1. QuickBooks investigation update

### Verified so far

- QuickBooks Desktop Enterprise Solutions Retail 24.0, build R22P, is installed.
- A verified backup was used to make the isolated Peera reference company copy. Owner instructions identify Peera as test/reference data, not TPZ live books. No original-company changes were made.
- A Windows SDK session connected to the isolated open company as `localQBD (1)` with `qbFileOpenDoNotCare (2)`. `HostQuery` reported qbXML versions 1.0 through 18.0.
- Initial read-only Company, Account, Customer, Vendor and Item queries returned status code 0. Follow-up read-only list queries reported 65 accounts, 18 customers/jobs, 19 vendors, an item query capped at 100, 4 classes, 5 currencies, 7 terms, 10 payment methods, 2 sales-tax codes and 1 sales-tax item. These are list counts from the isolated company at query time, not a claim about TPZ production data.
- The original review noted one synthetic customer master was added to the isolated copy under the Owner's test-record authorization. No invoice, bill, payment, credit, journal, banking or reconciliation transaction was successfully saved. No SDK write was run in this work session.
- Review of the separate QuickBooks analysis thread and Issue #21 records that a later invoice-form attempt was blocked by unstable MDI focus (typing reached the underlying Chart of Accounts). The direct UI walkthrough and redacted screenshots therefore remain incomplete.

### Evidence classification

The live read-only SDK observations above are **directly verified**. Transaction behaviors below are **requirements to inspect**, not claims that Peera contains or demonstrated them. Product documentation is useful as a discovery checklist but does not prove the installed company's preferences, templates, tax setup, transaction history, or feature activation.

### Remaining read-only analysis (before design sign-off)

For each transaction type, query representative headers and detail lines (where present), link references, status/void state, dates, currency/rate, tax fields and account references; redact names, addresses, emails, phone numbers, bank details, tax identifiers and credentials from any delivered artifact.

| Object family | Required observations |
|---|---|
| Sales | Invoice, sales receipt, credit memo, customer payment, deposit; AR/undeposited-funds/bank links, line item/account/tax fields, partial payment and applied-credit links |
| Purchasing | Purchase order, item receipt, supplier bill, vendor credit, bill payment/check; receipt/accrual behavior, AP/control account links, partial payment and application links |
| Inventory | Item receipt/adjustment, sales-linked quantity and valuation effects, inventory asset/COGS accounts, negative stock and return disposition behavior |
| General journal | Header and lines, account/class/customer/vendor references, debit/credit and currency, edit/void/reversal/history links |
| Banking | Bank register, transfers, deposits, checks, statement lines, cleared/reconciled flags and reconciliation report links |
| Other/ERP-specific | Expenses, refunds, marketplace deposits/fees, tax adjustments, returned/damaged goods and claim recovery; determine whether native QB objects or TPZ-only source events are appropriate |

For every family record the QBD transaction type and qbXML request/response shape, `TxnID`, `EditSequence`, `TxnDate`, `RefNumber`, `TxnLineID` and `LinkedTxn` availability where applicable; no transaction query has yet been captured, so field support remains unverified for this file. Determine whether report queries can return detail or only summary in the installed edition, and compare report output to register / transaction journal drill-down on the same date range.

### Reports to inspect

Capture report name, display category, filter/date basis, accrual/cash option, grouping/columns, totals, drill-through target, export format, and whether SDK exposes an equivalent query/report. First set: Balance Sheet, Profit & Loss, Cash Flow, Trial Balance, General Ledger, Journal, A/R and A/P Aging Detail/Summary, Open Invoices, Unpaid Bills, Inventory Valuation Summary/Detail, Sales by Customer/Item, Purchases by Vendor/Item, bank reconciliation and transaction audit/detail reports. Confirm actual report names and availability in QBD Enterprise 24.0 rather than treating this proposed inventory as observed UI.

### UI walkthrough and capture status

The requested walkthrough covers Accounting Home, Chart of Accounts/register, Customer and Vendor Centers, invoice/bill/journal forms, Banking, Reports Center, report drill-down, transaction history, linked records, menus/toolbars and shortcuts. **No new QuickBooks screenshots were captured in this session** because the approved computer-use runtime could not initialize (`failed to write kernel assets: The system cannot find the path specified`). The separate review thread additionally documents unstable focus. This is an environment limitation, not a claim that the screens were inspected. Resume only against the verified isolated copy; redact before saving; never write transactions without a separately explicit Owner authorization for SDK/UI writes. Existing Owner permissions for test records in Peera do not authorize altering original-company data or enabling TPZ posting.

## 2. Accounting UI specification

The prototype is a TPZ-branded, static standalone HTML app. All records are synthetic and all state is in-browser only. Every document carries a visible non-posting notice; actions named Post, Send, payment or statement import are inert notifications. It neither calls the ERP nor QuickBooks.

### Shared shell and interaction

- Persistent company/book context, global search (`Ctrl+K`), user indicator and left Accounting work-center navigation.
- Tab strip to keep multiple work areas open, with close controls and return to Accounting Home.
- Dense, scan-friendly tables; status pills; filters; responsive sidebar and form layouts.
- Keyboard-first interaction: global search, `Tab` across editable grid cells, `Enter` to append a line, `F2` selected-record affordance, `Esc` exits search. These are prototype affordances; production shortcut mapping must be validated against QBD users and browser accessibility behavior.
- Drill links show register/history/report affordance. Synthetic values are clearly marked; there are no copied QuickBooks logos, marks or proprietary assets.

### Screens

| Screen | Prototype scope | Production design requirement |
|---|---|---|
| Accounting Home | KPIs, work queue, quick create, recent activity | Permission-filtered summaries; source-aware alerts; no sensitive profit/stock figures to unauthorized roles |
| Chart of Accounts | Type/name filters, balances, account register drill | Hierarchy, control-account restrictions, inactive state, audit history and balance-as-of context |
| Customer Center | AR summary and synthetic customer balances/history | Contact profile, open/paid/overdue status, linked invoices/receipts/credits, aging and permitted financial data |
| Vendor Center | AP summary and synthetic supplier balances | Linked POs/receipts/bills/credits/payments and due-date/aging work queue |
| Sales Invoice | Header, editable lines, terms/reference, currency, totals | Validated tax/account defaults, source sales-order/web-order links, save draft vs approved posting workflow |
| Supplier Bill | Shared editable transaction grid pattern | PO/receipt match, duplicate supplier-invoice control, accrual policy, currency and bill-credit applications |
| General Journal | Two-line balanced sample preview and editable grid | Period guard, granular journal permissions, approver policy, immutable posted entries and reversal correction |
| Banking Center | Statement/reconcile cards and match candidates | Import preview, matching rationale, split/transfer handling, statement-to-register proof and locked reconciliation |
| Financial Reports Center | Report catalog, filters, saved-view/export affordance and P&L drill-down | Configurable date/basis/entity, reproducible saved settings, detail-to-source drill-through and permission-safe export |

This is for Owner review and workflow feedback; it is not final B2 UI, an acceptance of QuickBooks parity, or authorization to implement financial behavior.

## 3. Performance and advanced capability requirements

Targets below are proposed measurable acceptance criteria, not present performance claims. Measure against production-scale synthetic data in a representative staging-like environment, report p50/p95/p99 plus dataset, hardware, cold/warm cache, concurrency and network conditions.

| Capability | Proposed target | Verification |
|---|---|---|
| Global search | p95 ≤ 500 ms for first 20 results over 1M indexed source/ledger records; permission filtering included | Load test with 20 concurrent users; measure server and end-to-end latency, verify unauthorized fields/results never leak |
| Work-center/list first page | p95 ≤ 1.0 s for 50 rows at 250k transactions | APM request trace and browser timing over role-specific datasets |
| Report preview | p95 ≤ 3 s for 12-month/100k-line summary; detail drill ≤ 2 s for 1k rows | Repeat fixed report fixtures; verify totals equal independently calculated ledger control totals |
| Transaction form | p95 ≤ 300 ms for local grid row add/edit and default calculations; server validation ≤ 800 ms | Browser interaction timing; test 100-line document; measure save-draft separately from posting |
| Background imports/reports | enqueue ≤ 1 s; visible state and progress heartbeat at least every 5 s; retry without duplicate effects | Queue fault injection, worker restart, retry and idempotency-key assertions |
| Reconciliation | match candidate list p95 ≤ 2 s per 5k statement rows; no auto-accept below configured confidence | Fixed labeled dataset, precision/recall and false-match review; user must confirm posting/clearing |
| Availability/data correctness | No silent partial ledger writes; each posting transaction atomic and balanced; retry creates no second journal | Fault injection at each transaction boundary; unique source key and control-account reconciliation |

Advanced behavior includes saved report configurations (filters, basis, columns, grouping, sort, owner/share scope), intelligent defaults with provenance and override audit, exact-once source event processing, duplicate detection, audit history, background queueing and permission-controlled metrics/export. Posting activation remains false until separately approved.

## 4. ERP-to-accounting event design (not enabled)

Posting is designed around completed/immutable source events rather than screen saves. Each proposed mapping needs Owner/accountant approval for recognition timing, tax treatment and account rules before implementation. Use book + source object + source ID + event/version as a unique idempotency key. Persist mapping version, source snapshot/hash, actor, timestamps and journal lineage. Posted journal lines are immutable; correction uses a linked reversal/adjustment. Every event must honor open-period checks and transaction-level authorization.

| ERP area | Candidate posting trigger and accounting event (proposed) | Duplicate / control safeguards |
|---|---|---|
| Purchasing | PO approval is non-posting. At accepted receipt, recognize inventory and a receipt accrual only if approved policy is accrual-at-receipt; at matched supplier bill, clear accrual / recognize AP and tax | PO, receipt and bill remain distinct source events; bill match key includes vendor + normalized document ref + currency/amount; no bill duplicate of receipt accrual |
| Inventory | Only finalized, approved stock adjustments, write-offs or cost corrections post; regular movement inherits matched purchase/sale/return event | Do not infer average cost from product `cost_price`; post using immutable finalized stock valuation snapshot and movement ID |
| Sales orders / Web Sales | Order or reservation is non-posting. On completed fulfillment / legally issued invoice, recognize AR or settlement clearing and revenue/tax; at fulfilled stock movement recognize COGS/inventory | Order, invoice and fulfillment references linked; only one recognized invoice event per source; marketplace settlement reconciles independently |
| Returns | Approved return receipt/disposition plus customer credit/refund event; reverse revenue/tax and restore inventory only for accepted-good disposition; damaged stock to damaged/safe location with approved impairment policy | Return/QC decision ID; prevent both credit memo and cash refund being counted twice; link original invoice/line |
| Damaged Inventory / Safe-T | Damage recognition only when QC disposition is finalized; claim receivable/recovery only when approved recognition criterion met (not at claim submission) | Separate damage movement and claim lifecycle event; retain case, carrier and source sale IDs; recovery is not a duplicate inventory reversal |
| Business Expenses (AED) | Approved expense with accounting date and evidence; payment is separate cash/bank event when actually settled | Expense ID + revision; existing module data and permissions remain intact; no automatic import of Pakistan PKR Office Finance |
| Marketplace | Recognize sale at ERP invoice/fulfillment boundary; recognize commissions/withholding and settlement clearing when statement is accepted | Statement line ID and payout ID; reconcile gross-to-net, fees, tax and withholding without duplicate sales |

No entry will be produced by the prototype, and no mapping is implemented by this deliverable.

## 5. Historical migration architecture

Design an Accounting Import Center; do not import data yet. Flow: source file/API extraction → immutable staged batch → map entities/accounts/contacts/items → transform with source currency/rate/date/status → dry-run validation → Owner/accountant preview and exception resolution → controlled import in a locked cutover window → reconciliation sign-off → sealed audit manifest.

Requirements:

- Import transaction-level history, not only opening balances: invoices, sales receipts, credit memos, receipts/payments, purchase orders if required for open history, receipts, bills, vendor credits, bill payments, journals, transfers, deposits and reconciliation artifacts where extractable.
- Preserve source company/entity, object type, `TxnID` and line IDs, `EditSequence` as source metadata (not destination mutation token), references, dates, status/void state, customer/vendor/item/account/class links, parent/child links, applied payments/credits, currency, exchange-rate precision, tax amounts/codes and original-vs-functional currency totals where source exposes them.
- Map COA account identifiers and types; map customer/jobs, vendors and items with explicit unresolved/ambiguous queues. Never silently create a guessed map. Store original display labels and source IDs for lineage.
- Store source system, source company fingerprint, source object/line identifiers, export/extract timestamp, source payload hash, transformation version and destination batch/record key. Idempotency key based on source-company + object-type + source-ID (+ line ID where needed); re-run must result in identical no-op or report a conflict, never double post.
- Preflight duplicate fingerprints on document number + counterparty + date + amount + currency, but use source IDs for identity; fuzzy matching is only a review warning, not merge authority.
- Dry-run reports counts and value totals by transaction type, period, account, currency and status; missing references, unsupported fields, invalid/unbalanced journals, orphan links, duplicate candidates, closed-period conflicts, rounding deltas and totals not reconciling to source.
- Handle deletion/voids and historical status without silently deleting imported destination history; represent void/voided state and preserve audit provenance. Prevent imported history from retriggering live ERP-to-accounting posting events.
- Reconcile imported Trial Balance, AR/AP control totals, cash/bank registers, inventory valuation and P&L/Balance Sheet by period/currency; produce explicit conversion/rounding and unextractable-data gap reports. Cutover blocks until approved reconciliation tolerances and unresolved exceptions are signed off.
- Retain immutable batch manifest, actor approvals, all exceptions/resolutions, source and transformed hashes, row-level results and reversal/rollback plan. Rollback is a controlled reversal of a batch, never destructive database reset.

Feasibility is **not yet proven**. The current SDK reads verified master lists only; no transaction objects or reports have been extracted. The official IIF guidance says transaction data is not exported via IIF and imported IIF transactions do not automatically establish all links; therefore transaction-level transfer requires SDK query/export or another validated source extraction format and explicit linked-record reconciliation. This inference must be validated against actual query coverage and report/export outputs for Peera. [Intuit IIF import/export guidance](https://quickbooks.intuit.com/learn-support/en-us/help-article/import-export-data-files/export-import-edit-iif-files/L56LT9Z0Q_US_en_US).

## 6. Updated roadmap and gates

1. **Discovery completion (now):** restore stable QBD UI control; finish read-only transaction queries, report snapshots and screen review; redaction review; record query/version and field coverage. Owner decisions: accounting basis, entity/book boundary, UAE VAT policy, recognition points, inventory/COGS policy, opening/cutover date, history retention and role/approval matrix.
2. **Owner design review:** review this prototype and verified workflow evidence; approve navigation/forms, event boundaries, report set, data visibility, migration scope/tolerance and acceptance criteria. No B2–B7 major code starts before this gate.
3. **B2 foundation planning:** only after approval, refine ledger/subledger schema and reversible migrations, build on B1 safeguards, test disposable MySQL 8.4 with posting flag off. Plan only; not part of current deliverable.
4. **B3/B4 workflows:** approved AP/AR and payment/credit workflows with draft/review controls and source links.
5. **B5/B6 integration/reconciliation:** stage ERP event mappings and bank reconciliation, run dry-run shadow comparison; no activation until accountant reconciliation and Owner authorization.
6. **B7 reports/import:** approved reports, Import Center dry-run, historical mapping and controlled cutover rehearsal.
7. **Staging → PowerShell deployment → UAT → Main:** keep branches isolated; review migrations, backup/checksum, post-deploy verification and signed UAT. No automatic deployment or Main promotion from this work.

## 7. Artifact validation notes

Prototype is one self-contained HTML file with inline CSS/JS and synthetic data, with no external dependencies or network calls. Its posting controls only display a disabled message. QuickBooks SDK writes were not issued. No database/schema/financial records were changed. Browser rendering and real QuickBooks screen capture remain pending because the computer-use runtime failed initialization; visual fidelity claims are intentionally limited to the written product documentation and prototype design intent.
