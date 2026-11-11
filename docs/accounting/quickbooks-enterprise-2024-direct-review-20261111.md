# QuickBooks Enterprise 2024 Direct Review — 11 Nov 2026

**Issue:** [#21 — Native ERP Accounting](https://github.com/adiljaved104-hub/tpz-erp/issues/21)  
**Review branch:** `codex/quickbooks-2024-direct-review-20261111`  
**Review status:** `PARTIAL — QuickBooks GUI and backup verified; transaction testing paused at mandatory password setup`  
**Data boundary:** Peera International Trader FZ-LLC is a test/reference company, not TPZ's real company books. No Peera company file, transaction export, customer/vendor data, or screenshots were committed.

## What was directly verified

- QuickBooks ran locally as **Intuit QuickBooks Enterprise Solutions: Retail 24.0**. Its window title identified the open company as **Peera International Trader FZ-LLC**. The license number and financial balances visible in the UI are intentionally omitted.
- `File → Back Up Company → Create Local Backup` was available. The local backup configuration had **Complete verification (recommended)** selected. QuickBooks completed verification and confirmed that it saved a `.QBB` file. The local file is 12,091,392 bytes. It is outside the repository in a local review artifact folder and is not tracked.
- The company opened to the **Chart of Accounts** window. Observed controls included search by account name/number, Search and Reset, account type and currency columns, balances, an Include inactive toggle, and Account / Activities / Reports / Attach actions. The account list showed parent/child indentation and multiple currency codes, so account-level currency is a real configuration dimension in this company.
- `Company → Make General Journal Entries` opened a working entry screen. Visible header controls included currency (UAE Dirham), a home-currency adjustment checkbox, date, exchange rate, entry number and adjusting-entry flag. The line grid exposed Account, Debit, Credit, Memo, Name, Tax Item, Billable and Class fields. The toolbar exposed Find, New, Save, Delete, Memorize, Reverse, Print, Attach and Hide List. A lower pane listed selected journal entries with date, entry number, adjusting flag, account, memo and debit/credit amount.
- QuickBooks also showed a Home Page workflow with Company and Banking areas, including Chart of Accounts, Inventory Activities, Record Deposits, Reconcile, Write Checks and Check Register. The top menu included File, Lists, Company, Customers, Vendors, Employees, Banking and Reports.

### Current blocker and exact stop point

After the General Journal form had been opened, the application window was closed during window recovery. Reopening the installed QuickBooks executable presented **Sensitive Data Protection Setup — Create Complex QuickBooks Desktop Password**, requiring a new Admin password and confirmation. The current UI control policy requires the account owner to perform password creation/change steps. I did not enter or store a password and did not attempt to bypass this gate. A second QuickBooks window reported that an empty QuickBooks window already exists and would be reused.

As a result, no transaction was saved, edited, voided, deleted, reversed, or exported; no report impact or transaction journal was verified. The initial access was genuine GUI access, but full hands-on testing is now blocked until the Owner completes QuickBooks' Admin password setup and reopens Peera. The backup predates this blocker and was confirmed saved.

**Next operational step:** Owner completes the Admin password setup in the QuickBooks window, opens Peera, and leaves the company window available. Then resume from the verified backup; first confirm the company title and backup path before any experiments. The standard Intuit folder inspection found `Statement Writer 2024` and `VC10X64Redist` under `C:\Program Files (x86)\Intuit`; it did not find the SDK in `C:\Program Files\Intuit` or `C:\QuickBooks Enterprise Solutions 24.0\QBSDK`. This is not proof that no SDK exists elsewhere. No QBFC/qbXML request or integrated-app authorization was attempted.

## UI and workflow observations for TPZ design

The installed desktop interface is menu-led and multi-window. It combines persistent left shortcuts, a Home Page workflow canvas, top-level menus, account-list actions, toolbar commands, and list/detail panes. Search and list filters sit close to the current module. The journal's list pane is on the same screen as its entry form, enabling quick selection of prior entries. Reverse is an explicit toolbar action alongside Save and Delete, so correction paths need distinct, discoverable actions and should retain a link to the original.

For TPZ, preserve the functional pattern with a modern web experience: global Finance navigation; company/book and date context visible in the header; searchable lists with filters and saved views; a transaction detail drawer/page with source, attachments, audit events and ledger impact; clear Draft / Pending approval / Posted / Reversed states; separate Create, Edit draft, Post, Reverse and Void permissions; and report-to-transaction drill-down. Avoid a single dense desktop grid. In the journal form, show debit/credit totals and the imbalance continuously, make currency and FX rate explicit, and show the tax, class/dimension and entity fields only where configured.

## Hands-on scenario log

| Scenario | Outcome | Evidence / next action |
| --- | --- | --- |
| Company backup before testing | **Verified complete** | QBB saved; complete verification selected; QuickBooks success dialog; file present locally (12,091,392 bytes). |
| Chart of Accounts navigation | **Observed** | Company was already on the COA screen at initial capture; search, hierarchy, types, currency and account actions observed. No account was edited. |
| General Journal navigation and form inventory | **Observed; no save** | Entry form opened and actual fields/actions captured in this report. No date/account/amount was entered and no entry number consumed. |
| Balanced/unbalanced journal, Ctrl+Y Transaction Journal, edit, delete, void and reversal | **Not tested** | Password setup gate interrupted the session before any posting. After Owner access, use a synthetic memo prefix `TPZ-QB-REVIEW-20261111`; use a balanced low-value test with a dedicated test account pair, then verify the journal and reverse/clean up under the verified backup. |
| Customer invoice, partial/over/under-payment, credit memo and refund | **Not tested** | Resume in Peera; inspect Receive Payments unapplied-credit behavior and invoice linkage before refund. |
| PO, inventory receipt, bill, partial/overpayment and vendor credit | **Not tested** | Resume in Peera; verify whether receipt and bill create separate accrual/inventory effects and inspect Bill Payment application. |
| Inventory quantity, valuation, average cost and COGS | **Not tested** | Confirm item type, inventory asset, COGS timing and valuation method from transaction journal and inventory reports. |
| Deposit, transfer, expense, bank reconcile and correction | **Not tested** | Home Page exposes these workflows, but no transaction or reconciliation was made. |
| VAT, multicurrency, realized/unrealized FX | **Not tested** | Journal screen shows currency / exchange-rate controls; tax item is a line field. This does not establish UAE VAT suitability or FX revaluation behavior. |
| P&L, Balance Sheet, Trial Balance, GL, ageing, Audit Trail | **Not tested** | Reports menu is visible. No report figures or historical business identifiers were exported. |

## Export and historical migration feasibility

This matrix separates direct Peera testing from supported-format research. None of the data rows below has yet been exported from Peera.

| Method | What it can supply | Limits and recommendation |
| --- | --- | --- |
| Excel / CSV from reports | QuickBooks Desktop can export report output as `.xlsx`, CSV or PDF. Use customized transaction detail, GL, Trial Balance, P&L, Balance Sheet, ageing, inventory valuation and tax reports as reconciliation evidence. | Report output is a presentation/aggregation layer. Do not assume it preserves every source transaction ID, source lines, tax components, payment allocations, void/delete history, or attachments. Export and compare record counts and totals; use as cross-check and fallback, not sole full-history source. |
| IIF | The UI offers `File → Utilities → Export → Lists to IIF Files`; official Intuit guidance says IIF exports lists, while transaction export from QuickBooks is unavailable. | Not suitable as the complete history export. IIF import does not link transactions (e.g. invoice/payment); Intuit does not provide technical support for IIF. Use only for a narrow supported list transfer after a test and backup, never as the primary transaction migration. |
| QuickBooks Desktop SDK / qbXML (QBFC) | The SDK distinguishes list objects (stable QuickBooks-assigned `ListID`) and transactions (QuickBooks-assigned `TxnID`); its documented transaction types cover AR, AP, journal, banking and other objects. Record-specific query requests can return lines and references, and transaction requests can be paginated/filtered. Best candidate for source-level detail and relationship extraction. | Must verify installed SDK/QBFC version and 2024 Retail object coverage locally; obtain a read-only integrated-app authorization on the test company; inventory every query type and field, and prove counts/completeness. Preserve raw qbXML and response status securely outside source control. SDK coverage and retained history can differ by object; audit trail, deleted/voided records, attachments and unsupported fields need separate gap checks. No local SDK run occurred. |
| Manual UI report/detail export | Useful for screens, transaction journals and controlled spot checks where SDK fields are unclear. | Manual and error-prone at scale; not repeat-safe. Use to validate API extraction and resolve exceptions. |

Official Intuit material supports the report and IIF distinctions above: [Excel/CSV/PDF report export](https://quickbooks.intuit.com/learn-support/en-us/help-article/export-reports/export-reports-excel-workbooks-quickbooks-desktop/L4cLJEeXt_US_en_US), [IIF export limits and unlinked imports](https://quickbooks.intuit.com/learn-support/en-us/help-article/import-export-data-files/export-import-edit-iif-files/L56LT9Z0Q_US_en_US), and the [QuickBooks Desktop SDK Programmer's Guide](https://static.developer.intuit.com/qbSDK-current/doc/pdf/QBSDK_ProGuide.pdf). SDK suitability for this specific Peera file remains **document-supported, not directly verified**.

### Proposed extraction/import mapping (version 0; validate against real qbXML)

| QuickBooks source | Stable source key / fields to retain | TPZ import target and relationships |
| --- | --- | --- |
| Company / preferences | Company file identity fingerprint (redacted), fiscal start, home currency, multicurrency setting, tax settings, QB product/release | `accounting_books` / entity configuration; never merge Peera with TPZ legal books. |
| Accounts | `ListID`, `FullName`, parent, account number/type, currency, active status, description, opening balance/date | `accounting_accounts`; retain hierarchy and source ID; map account type and normal balance explicitly. |
| Customer / Vendor | `ListID`, name hierarchy/jobs, terms, currency, inactive flag and available profile fields | Customer/vendor entities with source-system ID; keep private contact/bank data in a protected migration batch, not review docs. |
| Items / tax codes / currencies / classes | `ListID`, item type, account refs, tax code/rate/group, currency code/rate, class hierarchy | Reference dictionaries and versioned account/tax mappings; do not equate QuickBooks tax behavior with UAE VAT policy. |
| Transaction header | `TxnID`, `EditSequence`, transaction type/number/date, entity/account refs, currency/exchange rate, memo, status/void indicators, created/modified timestamps where available | `accounting_source_records` plus typed invoice/bill/payment/journal records; preserve original identifiers and dates. `EditSequence` is concurrency/provenance, not a TPZ ID. |
| Transaction lines | `TxnLineID`, line order, item/account refs, description, qty/UOM, rate/amount, tax code/amount, class, billable state, inventory effect and available lot/serial/UOM data | Typed document lines and immutable journal lines; keep source line IDs and exact decimal strings. Do not invent serial-level detail if absent. |
| Application/link rows | Linked transaction `TxnID` and line ID, applied amount/date, payment-to-invoice/bill allocation, credit application, deposit/payment links | Allocation/link table keyed by source IDs; import after both ends exist. Reconcile AR/AP subledger to control account. |
| Bank / reconciliation | Account, transaction/line IDs, cleared status, statement date/ending balance, reconciliation period and status | Bank transactions and reconciliation records; preserve cleared/unreconciled state and opening balances. Confirm SDK/report availability per field. |
| Audit / attachment / notes | Audit event type/time/user/old-new field values; attachment IDs/names/content hashes and note links, only as extractable | Append-only provenance/import audit and secure external evidence archive. Run separate completeness audit; never claim full retention without counts and samples. |

Import order: legal book and policies → account hierarchy and dictionaries → entities/items → original source transaction headers and lines → payment/credit allocations and deposits → bank reconciliation state → report/control totals. Each row needs `source_system`, `source_company_fingerprint`, `source_object_type`, `source_id`, `source_line_id`, stable canonical fingerprint and import-batch ID. Enforce unique source keys and duplicate fingerprint checks. Stage, validate, preview, dry-run, import, and reconcile; never import Peera into TPZ's production company. Before a real TPZ cutover, reconcile year/period Trial Balance, GL, Balance Sheet, P&L, AR/AP ageing, inventory valuation/COGS, VAT and bank balances; agree cutover policy so historical transactions and opening balances do not double count.

## TPZ Accounting B1/B2 parity and priorities

The B1 code was inspected on reference branch `codex/accounting-b1-hardening-access-control-20261110` (not this review branch and not merged by this work). This is a source-code review, not proof of deployment or test execution. The branch contains `AccountingBook`, COA, journal/header/line and period models; COA, General Journal, General Ledger, Trial Balance and period Filament pages; an `AccountingLedgerService`; and an `AccountingFoundationTest`. The viewed journal UI creates balanced AED drafts and exposes a Post action only for drafts. Service-level source inspection shows transactional journal posting, balanced-line validation, posting/period gates, immutability after posting, idempotent reversal linking, compensating reversal lines, and GL/TB readers. The tests present on that branch cover a seeded inactive AED book, balanced journals, denied access, closed-period rejection, immutability and reversal. They were not run in this review.

The checked `main` baseline for this branch does not contain the Accounting B1 pages/models. The separate B1 reference branch is `b9b9730`; the current review branch intentionally remains based on Issue #21's requested Main baseline `7b67b3902e193d2677eb7c453695598c61f1922b`.

| QuickBooks capability | Peera observation | TPZ B1 reference branch | Gap / priority |
| --- | --- | --- | --- |
| Company books, base currency, fiscal year / close | UAE Dirham shown in journal; closing-date workflow not tested | AED book seeded inactive; period records and close controls exist | Add configurable fiscal calendar, year-end close/retained earnings and entity preferences. **P1** |
| COA hierarchy / multi-currency accounts | Searchable hierarchy; type and currency columns; multiple currencies present | Book-scoped COA, number/name/type/normal balance, posting/header and active state | Add parent accounts, currency policy and QB-style list/report/attachment actions where justified. **P1** |
| Journal, adjustment, reverse, audit | Form exposes adjusting entry and Reverse / Delete; no save tested | Draft/post/reverse and immutable posted entries exist in code | Add approval workflow, edit-draft UX, source drill-down and audit view; test reversal in actual QuickBooks. **P1** |
| GL / Trial Balance | Reports menu and account balance column observed; no report verified | GL and TB queries/pages exist | Add filters, as-of control, running balance, export, drill-through, comparative periods and report reconciliation. **P1** |
| Customers, invoices, AR, receipts / refunds | Not tested | Existing operational customers/orders/tax invoices are not an AR subledger | B3: invoices, credit notes, receipts, allocation/unapplied cash, refunds and aging. **P1** |
| Suppliers, bills, AP, vendor credits / payments | Not tested | Existing purchasing/obligation data is operational, not AP ledger | B2: vendor bill / receipt accrual policy, bill payment allocation, credits, aging and control-account tie-out. **P1** |
| Inventory valuation, purchase cost, sale COGS | COA contained Inventory Asset; transactions not tested | Existing stock and average-cost flows are separate from B1 GL | B2: approved receipt/bill/COGS event map; value and quantity reconciliation; do not derive average cost from `cost_price`. **P1** |
| Bank, deposits, transfers, reconciliation | Home Page exposes Record Deposits, Reconcile, Write Checks, Check Register | No B1 bank reconciliation/subledger | B4: statement import, match/unmatch, cleared state, reconciled lock, fees/transfers and balance tie-out. **P1** |
| Expense / petty cash | Not tested | Pakistan Office Finance remains a separate PKR cashbook | B4: AED expense/cash module and separate PKR entity/book mapping. **P1** |
| VAT / tax | Tax Item is a journal-line field; configuration and reports not tested | UAE tax invoice operational module; no GL VAT subledger verified | B5: input/output VAT, tax adjustments, tax evidence and reconciled UAE returns; verify current local rules separately. **P1** |
| FX / realized and unrealized gains | Currency and exchange-rate controls shown; outcomes not tested | AED book; no end-to-end FX subledger verified | B4: retain source and functional amounts/rates; realized settlement and unrealized revaluation with reversal. **P2** |
| Financial statements / management reports | Not tested | Only GL/TB confirmed in B1 reference branch | B6: P&L, balance sheet, cash flow, aging, inventory-to-GL and comparative management reports. **P1** |
| Audit trail, user permissions, period lock | Delete and Reverse UI actions observed; no history behavior tested | Server-side permissions, posting gate, period locking, activity log, posted immutability in code | Preserve actor/time/old-new values, dual control and correction lineage; demonstrate screen-level audit. **P1** |
| Historical record migration | Not tested; exports researched only | No transaction-level QuickBooks import center in B1 reference scope | Start a qbXML-backed inventory + versioned XLSX/CSV fallback template and reconciliation dry run before B2 data model freezes. **P0** |

### Recommended next phases

1. **Access unblock and evidence:** Owner completes Admin password setup. Capture release/build details and reopen the verified Peera company. Save clean screenshots with private balances/license/customer/vendor details masked.
2. **B2 before implementation freeze:** Query/test accounts, vendors, items, POs, item receipts, bills, payments, vendor credits and inventory valuation. Define receipt-vs-bill accrual and exact inventory/COGS posting rules. Keep GL posting disabled in ERP.
3. **Migration spike:** Install/locate the officially supported Desktop SDK for this environment if absent, verify `QBXMLVersionsForSession`, request read-only authorization for the Peera test company, and export one customer, vendor, account, inventory item, invoice/payment pair, bill/vendor-credit/payment pair, journal and bank deposit. Prove list IDs/transaction IDs, lines and links survive normalization without writing through SDK.
4. **B3–B6:** Implement subledgers, controlled posting/mapping and reconciliation in an isolated branch, then reports and UAE VAT. Do not use manual journal posting to replace invoice/bill subledger records.
5. **Employee-facing UAT gate:** Complete detailed finance permissions before employee UAT; retain deny-by-default, separation of posting/approval/reconciliation, owner-only posting activation, closed-period controls, activity logs and owner-only profit/cost data.

## Reproducibility and evidence gaps

- No transaction write was performed. This report therefore contains no fabricated accounting effects or balances.
- Screenshots were not saved because the actual QuickBooks screenshot contained live on-screen balances and the license number. The field inventory above is based on direct visual inspection and redacts those values.
- No qbXML/SDK request, Excel/CSV report, or IIF export was run. Export conclusions are clearly marked as official-document support, not Peera export results.
- No ERP tests were run. The accounting code inventory is from the separate B1 branch at `b9b9730`; this review branch started at `7b67b39`.
