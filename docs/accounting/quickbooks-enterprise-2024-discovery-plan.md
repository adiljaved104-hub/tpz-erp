# TPZ ERP Native Accounting — Discovery, QuickBooks 2024 Comparison and Test Protocol

> **Owner authorization addendum (9 Nov 2026):** The installed QuickBooks Desktop Enterprise 2024 **already contains company accounting records**. Codex may review and use actual company accounts, transactions, balances, reports, mappings and settings as a primary design/reference source, and is authorized to make appropriate changes when necessary for controlled evaluation. Earlier references in this plan that limit the review to a sample company are superseded for read/review. **Experimental postings must use a verified backup/copy**, not pollute active books. Before any live-company financial change, prepare a verified backup, identify the particular entries and financial impact, maintain an audit trail, and obtain separate specific approval for destructive operations, tax filings, or closed-period changes. Respect QuickBooks application permissions and report if the Windows desktop or SDK is not actually accessible. No unrelated VPS or GitHub Main/Staging writes.

**Initiated:** 2026-11-09
**Status (updated 2026-11-12):** Discovery only; SDK connection and read-only master-list queries now verified in the isolated Peera reference copy. Transaction/report extraction and direct UI walkthrough remain incomplete. See [`accounting-b2-owner-review-20261112.md`](accounting-b2-owner-review-20261112.md) for exact evidence, gaps, prototype and migration plan. No financial posting code, migrations, VPS deployment or production ledger writes approved.
**Reference software:** QuickBooks Desktop Enterprise 2024 (installed on Owner's Windows computer).
**Issue:** #21.
**GitHub source baseline:** `main` at `dbd3e85ffda0ad2e5727e22f0ad4401e3f3a0280`; isolated discovery branch only.

## Purpose and boundary

Create a **native double-entry accounting module** for TPZ ERP, not a QuickBooks connector and not a byte-for-byte replica of QuickBooks software. Review actual workflows, reports, business rules and keyboard shortcuts of the installed 2024 desktop edition using a **sample company file**, then implement the necessary UAE business accounting features under TPZ ERP's existing Laravel 13 / Filament / MySQL 8.4 architecture. Product design may learn from QuickBooks workflows, but must not copy proprietary source, artwork, branding or assume QuickBooks US tax reporting applies to the UAE.

Primary operating business is **Tech Point Zone Electronics Trading LLC (UAE)**, with AED as intended base accounting currency. A separate **Pakistan Office Finance** PKR cashbook already exists; do not silently combine those records or books. Future multi-entity capability should be accounted for in IDs/schema while activation and cross-entity accounting remain a separate decision.

## Existing TPZ ERP integrations (verified in repository, not a claim of ledger readiness)

| Existing feature | Source/code inventory | Accounting implication |
| --- | --- | --- |
| Web Sales & Orders | WebSales services, order fulfillment ledger | Sales and COGS recognition events require a deliberate posting boundary; pending Web Sales cost is not a final GL asset/expense |
| Purchases & Goods Receipts | Purchases, purchase receipts, supplier invoice normalization | Distinguish purchase order (non-posting) vs goods receipt / supplier bill (posting based on accrual policy); avoid double counting |
| Tax Invoices | TaxInvoiceService and issued immutable invoice snapshots | Use issued invoice snapshots as invoice audit/source; do not edit/replace issued invoices |
| Inventory | ProductInventory, allocation and stock movements | One physical warehouse, virtual employee allocations, SKU/title identity (NO serial tracking); maintain inventory-to-GL cost reconciliation |
| Manual Sourcing Obligations | `accounting_obligations` model/table | Source-side outstanding obligations, **not** GL journal entries; map to AP accrual only once with source-event idempotency |
| Pakistan Office Finance | OfficeFinanceService and PKR accounts/transactions, funding, loan modules | Existing operational cashbook should continue unchanged until a separately approved accounting mapping; no automatic ledger conversion |
| Returns/QC/Damaged/Safe-T | Existing stock/returns/claims | Posting points for refunds/credit notes, damage/impairment, claim recoveries require explicit documented policy |
| Marketplace | Orders, fees/settlement-related operations | Handle payout clearing, marketplace receivable, sales fees, withholding/adjustments and statement reconciliation explicitly |

**Never treat this feature inventory as evidence that native double-entry accounting already exists.**

## A. Installed QuickBooks 2024 walkthrough

**Precondition:** Open a disposable *sample product-based business* or *sample wholesale/distribution business* file. In QuickBooks Desktop, use `File → Close Company` and the `Open a Sample File` control in the No Company Open window when available. Do not run tests in Tech Point's live company file. Confirm exact product/edition/build and configured company currency; some modules vary by edition/preferences.

For each tested area, collect: **screen/layout, input fields, menus/shortcuts, validation, permission checks, generated accounting debit/credit lines, financial/report impact, error paths, and action history**. Screenshots should show only sample business data and must never contain credentials, serial/license keys, real customer information or live banking details.

| Review area | Actions to demonstrate in sample company | Expected evidence |
| --- | --- | --- |
| Company setup | Company info, fiscal year, currency, closing date, preferences, roles | Screens and rules/limitations |
| Chart of Accounts | List; account type, number, subaccount, active/inactive, opening balance | COA view; types/control accounts; account journal impact |
| General Journal | Create balanced debit/credit entry, test unbalanced, reverse, date lock | Transaction Journal (Ctrl+Y), Trial Balance before/after |
| Vendors / A/P | Vendor, purchase order, receive inventory, bill, bill payment, vendor credit | AP ageing and GL for each, including goods receipt-vs-bill distinction |
| Customers / A/R | Customer, estimate/sales order, invoice, part payment, credit memo/refund | AR ageing and GL journal per event |
| Inventory / COGS | Receive stock, sell stock, inventory valuation and cost impact | Inventory Asset / COGS movements and valuation report |
| Banking | Record deposit, transfer, charges, statement reconcile, undo/correction | Bank register, cleared/uncleared and reconciliation discrepancies |
| Taxes and FX | Sales tax preference UI and multi-currency behavior *as reference only* | Feature observation; do NOT treat US sales-tax logic as UAE VAT implementation |
| Reports | Trial Balance, General Ledger, P&L, Balance Sheet, Cash Flow, AR/AP ageing, inventory valuation | Captured report filters, as-of date, drill-down and export |
| Users and audit | Roles, restrictions, closing date, audit trail | Permission matrix and immutable posted-entry expectations for TPZ |

### Minimal test transactions in QuickBooks sample file

Use the sample company's own item/account setup and a clearly labeled synthetic batch such as `TPZ-ACCOUNTING-DEMO-01`. Execute **one action at a time** and inspect its **Transaction Journal** before proceeding:

1. **Non-posting order:** create sales order (and optionally PO); observe whether it changes posted GL.
2. **Credit inventory purchase:** receive 2 units with synthetic cost; separately record supplier bill according to QuickBooks receiving workflow. Inspect inventory and AP/receipt accrual.
3. **Credit sale:** invoice one unit; inspect revenue, receivable, inventory reduction and COGS.
4. **Partial customer receipt** and **partial supplier payment:** inspect AR/AP balances and bank register.
5. **Customer credit/refund:** inspect tax and inventory/COGS reversal/adjustment behavior under the chosen return disposition.
6. **Expense + bank transfer:** inspect register movement and correct double-entry.
7. **Journal adjustment/reversal:** check validation for equal debits/credits, permission and effective accounting date.
8. **Reports:** export demo-only Trial Balance, General Ledger, P&L, Balance Sheet and AR/AP ageing for the same date. Capture figures and explicit accounting basis.

For QuickBooks, `Company → Make General Journal Entries` is an official general-journal route; **Ctrl+Y** within a transaction opens the Transaction Journal report in documented desktop versions. Menu labels may differ slightly by edition.

## B. Parity matrix (fill from real installed-version walkthrough)

Statuses: `NOT REVIEWED`, `REFERENCE CAPTURED`, `DESIGN APPROVED`, `IMPLEMENTED`, `VALIDATED`, or `INTENTIONALLY EXCLUDED`.

| Feature | QB desktop actual behavior | TPZ current implementation | Status |
| --- | --- | --- | --- |
| Company books / fiscal period / home currency | To inspect | Company config exists; not independent accounting books | NOT REVIEWED |
| Chart of Accounts (number/type/subaccounts/control accounts) | To inspect | Pakistan Office Finance accounts are not full UAE GL COA | NOT REVIEWED |
| Journal and reversal workflow | To inspect | No verified shared double-entry GL | NOT REVIEWED |
| General Ledger / Trial Balance | To inspect | No verified shared GL / TB | NOT REVIEWED |
| Receivables invoices/receipts/credit notes | To inspect | Operational orders and issued tax invoices | NOT REVIEWED |
| Payables supplier bills/payments/credits | To inspect | Operational purchasing and obligations | NOT REVIEWED |
| Inventory Asset / COGS posting | To inspect | Existing physical/virtual stock and costing | NOT REVIEWED |
| Banking/reconciliation | To inspect | Pakistan Office Finance cashbook is separate | NOT REVIEWED |
| Currency, FX gains/losses | To inspect | Existing AED operational flows | NOT REVIEWED |
| UAE VAT, tax adjustments | QB taxes are not assumed UAE-compliant | Existing UAE tax invoice service | NOT REVIEWED |
| P&L, Balance Sheet, Cash Flow, ageing | To inspect | Operational reporting; financial statements require GL | NOT REVIEWED |
| Audit roles, close/period locks | To inspect | Existing access control and logs | NOT REVIEWED |
| Marketplace clearing and fees | Likely TPZ-specific extension | Operational marketplace services | NOT REVIEWED |

## C. Proposed native ledger model (architecture to approve; not implemented)

- **AccountingBook / AccountingEntity**: legal entity, base currency, active fiscal year, accounting method, VAT profile, default account mapping, activation state.
- **Account**: hierarchical numbered COA, account type/category, normal balance, parent, posting/control flag, active/inactive, company-scoped uniqueness.
- **FiscalPeriod**: start/end, open/closed/locked, closing actor, lock timestamps.
- **JournalEntry + JournalLine**: transaction date (business date), immutable posted timestamp (UTC), entity, source type/id/event identity, currency and exchange rate, signed debit and credit (never floats), description, memo, actor, approval state, reversal-of link and lineage. Each posted entry **must balance** in base currency.
- **AccountingSourceEvent / PostingKey**: unique book + source type + source ID + transition/event ID; exactly-once accounting even on retries; no double posting after restore or reconciliation.
- **Subledger linkages**: AR invoices/receipts, AP bills/payments, customer/vendor credit, banking, VAT, inventory valuation and marketplace settlement; all reconcile to control accounts.
- **BankStatement/Reconciliation**: imported/entered statement rows, matching, cleared/uncleared, independent reconciliation lock and exception report.
- **AccountMapping policy**: versioned rules mapping existing ERP immutable events to postings; no silent guesses when accounts missing or transaction cost is not finalized.

### Financial correctness controls (non-negotiable)
1. **Double entry:** for every posted transaction, total debits equal total credits at currency-minor-unit or approved high-precision FX scheme.
2. **Financial statements:** Trial Balance balances; `Assets = Liabilities + Equity` after handling period profits/retained earnings appropriately; P&L equals ledger income minus ledger expenses.
3. **Posting immutability:** after posting, corrections are new reversal/adjustment entries, not editing the original booked record or immutable tax invoice.
4. **Operational integrity:** never change physical stock, virtual allocation or existing order/return status just to make the GL tie.
5. **Idempotency and locking:** exactly one entry per source event per book; lock/concurrency-safe transactional post; no partial journals.
6. **Recognition rules:** define exact event for inventory received, supplier bill accrued, shipment/sale recognized, cash collected, customer return, marketplace fee, chargeback and failed/damaged item.
7. **Period protections:** closed dates refuse normal backdated entries; changes require authorized reopen or current-period adjustment trail.
8. **Segregation and confidentiality:** finance entry, approval, reconciliation, close, reporting and Owner cost/profit roles are distinct; no unauthorized access.
9. **Multi-currency:** store document currency, functional base currency, approved transaction FX, realized/unrealized difference; do not change historical rates.
10. **Cutover:** historical ERP operational transactions are not auto-replayed. Start from reconciled opening trial balance and an approved start date, reconciling inventory/AR/AP control accounts and existing unpaid obligations.

## D. UAE-specific requirements to verify before coding

- UAE VAT treatment for domestic sales and purchases, imports/reverse charge, zero-rating/exemption eligibility, input tax recovery, refunds/credit notes and bad debt adjustments.
- Correct VAT invoice/credit-note record keeping and accounting policy; existing TPZ tax invoice snapshots remain authoritative.
- Currency, fiscal year and accounting periods, corporate tax/reporting implications, and audit retention.
- UAE e-invoicing **future-readiness**; avoid assuming a QuickBooks Desktop 2024 US tax setup covers UAE digital invoicing. Confirm current Ministry of Finance / Federal Tax Authority rules and phase applicability before implementing.
- Business policy on marketplace sales timing vs settlement timing, fee VAT and refunds must be documented by the owner/accountant.

Official source references for ongoing compliance checks: Federal Tax Authority VAT guides `https://www.tax.gov.ae/en/taxes/Vat/guides.references.aspx`; UAE Government digital invoicing overview `https://u.ae/en/information-and-services/business/important-digital-services/digital-invoicing`.

## E. Incremental implementation after approval

| Batch | Scope | Required verification |
| --- | --- | --- |
| B1 | Company books + COA + periods + balanced manual GL/reversals + Trial Balance | MySQL 8.4 tests; unbalanced entry rejected; immutable post; locks, audit, permissions; GL = TB |
| B2 | AP: supplier bills, receipts/accrual mapping and payments; inventory/COGS | Receipt/bill cannot double-book; AP ageing = AP control; inventory valuation reconciles |
| B3 | AR: sales/invoices/receipts/credit notes/refunds | AR ageing = AR control; correct revenue and receivable recognition |
| B4 | Cash/banks, expenses, clearing and reconciliation, FX | Bank reconciliation matches posted bank movements; mismatch handling |
| B5 | VAT accounting and compliant reports | Tax invoice snapshot to GL/VAT ties, reverse-charge, returns and credits test matrix |
| B6 | P&L, Balance Sheet, Cash Flow, financial dashboards/reports/exports, closing | Trial Balance and subledgers reconcile at each date; closed period protection |
| B7 | Migration/cutover toolkit with Owner UAT and signed opening balances | Dry-run reconciliations, backup, controlled Staging, no historical double posting |

Build **one batch at a time** on isolated branches; never migrate, post or deploy directly to Main. Staging via guarded PowerShell only after explicit Owner approval, then fast operational UAT, then Main/UAT after separate approval.

## F. Deliverables for next review

1. Owner-provided or Codex-reviewed **sanitized sample-company** screenshots/screenshare of QuickBooks 2024: Chart of Accounts, General Journal, Transaction Journal (Ctrl+Y), Customer/Vendor Centers, Receive Inventory/Bill/Invoice, bank reconciliation, P&L/Balance Sheet/Trial Balance. No live data or license details.
2. Completed matrix above with screenshot/transaction evidence and precise business rules.
3. Confirm **UAE company as initial book**, accounting method, initial fiscal year/cutover date, AED base currency, initial opening balance source, whether Pakistan finance will ever feed GL, and which Owner/Accountant approvals apply.
4. Approved B1 entity/account/journal ERD and the golden synthetic scenario with expected debit/credit postings.
5. Only after these: schema migration and code phase, with feature disabled until approved.

## References for functional review

- Intuit Enterprise 24.0 official guide: `https://quickbooks.intuit.com/oidam/intuit/sbseg/en_us/quickbooks-desktop/web/content/QuickBooks_Enterprise_24.0_In-Depth_Guide.pdf/`
- Intuit Desktop account guide: `https://quickbooks.intuit.com/learn-support/en-us/help-article/chart-accounts/learn-chart-accounts-quickbooks/L5KDqxweR_US_en_US`
- Intuit transaction journal documentation: `https://quickbooks.intuit.com/learn-support/en-us/help-article/transaction-reports/view-transaction-journal-report-quickbooks-desktop/L944aaidk_US_en_US`
- Intuit sample file walkthrough: `https://quickbooks.intuit.com/oidam/intuit/sbseg/en_us/quickbooks-desktop/web/content/quickbooks-desktop-manual%20%281%29.pdf`
