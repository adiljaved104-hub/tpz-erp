# QuickBooks Desktop Enterprise 2024 — Live Findings, 16 Nov 2026

## Scope and evidence labels

This is a continuation of the existing isolated-company review. QuickBooks was open to the approved Peera test copy; Product Information confirmed the required file basename and parent folder. The isolated copy showed 1,912 transactions. No actual Peera company, ERP finance data, QuickBooks transaction, or setting was changed. Screenshots were inspected live but not saved because the screens included vendor names and balances.

Evidence in this report is labeled as follows:

- **Live UI verified** — seen in the QuickBooks 24.0 window during this session.
- **Earlier SDK verified** — returned by the previously successful, read-only SDK session against the isolated copy; not re-run in this session.
- **Documented only** — supported by official Intuit SDK documentation, but not queried from this company.
- **Not verified** — no direct observation or successful query yet.

## Current access check

- QuickBooks Enterprise Solutions: Retail 24.0, 64-bit, was running in the Windows session.
- Product Information (F2) confirmed the isolated test file in the `qb-isolated-test-20261111` folder, with the expected `Peera-TPZ-QB-TEST-20261111.QBW` basename.
- The local runtime includes `QBXMLRP.dll` and `QBXMLRP2.dll`, version 34.13 R3401; 64-bit `QBXMLRP2.RequestProcessor` COM registration is present.
- **Earlier SDK verified:** a Medium-integrity client connected using `OpenConnection2(appID, appName, localQBD=1)`, `BeginSession(verifiedPath, qbFileOpenDoNotCare=2)`, checked `GetCurrentCompanyFileName()`, and found qbXML versions 1.0–18.0. Initial Company, Account, Customer, Vendor and Item queries returned status code 0 / “Status OK.”
- **Current SDK limitation:** the current PowerShell execution shell runs at Low integrity. In this session `OpenConnection2` returned success but `BeginSession` did not return; the process was stopped before submitting any qbXML. The SDK client must run at Medium integrity, matching QuickBooks. No authorization dialog appeared, and no Windows security setting was changed. Do not retry from the Low-integrity shell.
- The QuickBooks Home embedded panel displayed an offline notice and “another file download is already in progress.” Menu navigation remained usable; the panel was not treated as evidence about accounting data.
- **Stop state:** when closing the blank Enter Bills form, the unstable MDI focus also closed the company session. QuickBooks now shows **No Company Open**. No bill data was entered or saved. Do not reopen or query a file until the Owner opens the isolated copy and its full path is reconfirmed in Product Information (F2).

## Master-data SDK evidence from the earlier successful session

All counts and identifiers below are sanitized. The prior read-only responses included the actual SDK `ListID` / `FullName` relationship fields; raw company values are intentionally not reproduced.

| Object | Earlier SDK result | Returned structure / limits |
|---|---|---|
| Accounts | 65 account records, bounded query, status 0 | `ListID`, `EditSequence`, `Name`, `FullName`, `ParentRef`, `Sublevel`, `AccountType`, `SpecialAccountType`, `AccountNumber`, `CurrencyRef`, `IsActive`, balances, tax-line fields and timestamps. Multiple account types and account currencies were returned. |
| Customers | 18 records, status 0 | `ListID`, `EditSequence`, `Name`, `FullName`, `ParentRef` schema, address/profile references, `CurrencyRef`, tax-code references, balances, active flag and timestamps. A customer-job parent relationship was not demonstrated by the returned sample. |
| Vendors | 19 records, status 0 | `ListID`, `EditSequence`, `Name`, `FullName`, address/profile fields, `CurrencyRef`, active flag, balances and timestamps. Terms were not present in the returned vendor sample. |
| Items | Generic bounded query returned its 100-record cap; specialized inventory query returned its 50-record cap | Inventory items included asset, COGS and income account references, purchase/sales costs, quantity fields, parent reference, tax-code reference, active flag and timestamps. The capped responses do not establish the total inventory-item count. |
| Reference lists | Status 0 for Classes (4), Currencies (5), Terms (7), Payment Methods (10), Sales Tax Codes (2), Sales Tax Items (1) | Currency codes returned included AED, CAD, EUR, JPY and USD. No inactive record was positively observed. An `ActiveStatus=All` Account query returned qbXML parse error `0x80040400`; inactive handling remains unresolved. |

Do not treat a returned account balance or the existence of a currency field as proof of a particular posting, revaluation, or tax treatment.

## Live Accounts Payable UI findings

### Vendor Center and navigation

**Live UI verified:** `Vendors` menu exposes Vendor Center; Enter Bills; Upload & Review Bills; Pay Bills; Receipt Management; Create Purchase Orders; Receive Items and Enter Bill; Receive Items; Enter Bill for Received Items; Inventory Activities; 1099 Forms; and Item List.

Vendor Center showed:

- An Active Vendors filter and a supplier search box.
- A vendor list with name, balance and an additional status/attribute column.
- A vendor information pane, with QuickReport and Open Balance links.
- Tabs for Transactions, Contacts, To Dos, Notes and Sent Email.
- Transaction filters for transaction visibility, type and date; the history grid showed type, number, date, account and amount columns.
- Toolbar actions for New Vendor, New Transactions, Print, Excel and Word; lower actions for Manage Transactions and Run Reports.

No vendor profile was edited. Names, contacts, references and balances are omitted.

### Enter Bills form

**Live UI verified:** the blank form opened with Bill/Credit selection, Vendor, Address, Date, Ref No, Amount Due, Terms, Bill Due, Class, Memo and Bill Received controls. It has Expenses and Items tabs. The visible expense grid included Account, Amount, Memo, Customer:Job and Billable; further columns were clipped by the window width. The toolbar included New, Save, Delete, Find, Create a Copy, Memorize, Print, Attach File, Select PO, Enter Time, Clear Splits, Recalculate, Upload & Review Bills, Pay Bill and Intercompany Transactions. The form was closed blank; no fields were entered or saved.

### AP aging reports

**Live UI verified:** `Reports → Vendors & Payables` offered A/P Aging Summary, A/P Aging Detail, Vendor Balance Summary/Detail, Unpaid Bills Detail, Accounts Payable Graph, Transaction List by Vendor, 1099 Summary/Detail, Vendor Phone List and Vendor Contact List.

Both A/P Aging Summary and A/P Aging Detail were opened read-only and populated. The report header said values were in home currency. The visible controls included an as-of date, aging interval in days, overdue horizon, sort order, vendor filter, Show Filters, Look for, Search and Reset. The report toolbar included Customize Report, Comment on Report, Memorize, Print, E-mail, Excel, Hide Header, Collapse Rows and Refresh.

- Summary columns grouped balances into Current, 1–30, 31–60, 61–90, over 90 and Total.
- Detail columns included Type, Date, Num, Name, Due Date, Aging and Open Balance. Rows were grouped into the same aging buckets and could be expanded/collapsed.
- The company data and totals shown on screen are not recorded here.

These screens establish report behavior and navigation only. They do not verify how any individual bill, payment or credit posted to the General Ledger.

## Other live workflow observations

- The Home Page showed purchasing workflow links for Purchase Orders, Receive Inventory, Enter Bills Against Inventory, Enter Bills & Vendor Credits and Pay Bills.
- The customer workflow area showed Create Invoices, Receive Payments, Record Deposits and Refunds & Credits.
- The main Reports menu exposed Company & Financial, Customers & Receivables, Sales, Vendors & Payables, Purchases, Inventory, Banking, Accountant & Taxes, Budgets & Forecasts, List, QuickReport, Transaction History and Transaction Journal entries.
- **Not verified:** no supplier statement was generated; no existing transaction was opened for editing; no payments or credits were applied; no GL/Trial Balance/P&L/Balance Sheet/Inventory valuation/Tax/Bank report was run in this session.

## Transaction and report verification status

| Workflow | QuickBooks status | Evidence limit |
|---|---|---|
| Supplier/vendor profile and list | Live UI verified; earlier SDK verified | List screens and master fields observed; no vendor edit or transaction query in this session. |
| Supplier bill entry | Live UI verified | Blank form only; no saved bill or Transaction Journal inspected. |
| Bill payment / payment allocation | Menu/UI entry point verified | No payment form submitted; partial payment, overpayment, discounts and application behavior remain unverified. |
| Vendor credit | Bill/Credit selector and Home/menu entry point verified | No credit created/applied/refunded; account effects remain unverified. |
| Supplier statement | Not verified | Menu/report behavior and statement balance logic still need inspection. |
| AP Aging Summary/Detail | Live UI verified | Screens populated; amounts redacted; no qbXML report response or GL tie-out. |
| Purchase order / receipt / inventory bill | Home/menu workflow verified | No transaction or inventory movement inspected. |
| AR / banking / general journal / inventory accounting | Menu or earlier Home screen only | No transaction records, report postings or current-session qbXML transaction queries. |
| Financial statements / GL / Trial Balance | Not verified in this session | Need bounded SDK report queries and UI/report drill-down after a Medium-integrity connection is available. |

## Historical migration requirements and present feasibility

**Earlier SDK verified:** bounded master-list queries returned source `ListID`, names, hierarchy and account/item references. The SDK runtime is available, but a Medium-integrity client is required for the next session. No invoice, bill, payment, adjustment, journal, deposit or reconciliation transaction query has yet been verified against this company. No Excel, CSV, IIF or qbXML transaction export was performed.

For a reliable migration, preserve at least:

- Company/source identity and export batch/version; account, customer, vendor, item, class, tax, terms and currency source identifiers.
- Transaction type, `TxnID`, `EditSequence`, reference number, dates, entity/account references, source currency, exchange rate, memo, status and create/modify times when available.
- Every line identifier and line order, item/account reference, description, quantity, rate, amount, tax code/rate/amount, class and billable/customer-job reference where available.
- Payment-to-bill/invoice and credit-to-source application links, applied amounts, deposits, bank account, cleared/reconciled state, and source/target transaction IDs.
- Voided/deleted/audit history, attachment/notes references and unsupported-field exceptions; prove retention with counts and exception reports.

Preferred next path: an authorized read-only qbXML extraction, bounded/paginated by transaction date and type, preserving raw sanitized responses outside source control and verifying `GetCurrentCompanyFileName()` before **every** request. Use report exports for reconciliation, not as the transaction source of truth. Use CSV/XLSX as controlled fallback only after retaining source IDs and link data. IIF is a poor primary history path; Intuit's SDK support guidance says IIF import is not officially supported and recommends the SDK.

Intuit documents Aging, General Detail and General Summary report query families and provides the `AgingReportQuery` API reference. This is **documented only** for our company until a read-only request succeeds. See the [SDK Programmer's Guide](https://static.developer.intuit.com/resources/QBSDK_ProGuide.pdf), [AgingReportQuery API](https://developer.intuit.com/app/developer/qbdesktop/docs/api-reference/qbdesktop/agingreportquery), [SDK query tips](https://developer.intuit.com/app/developer/qbdesktop/docs/develop/tutorials/tips-and-techniques) and [Intuit IIF support note](https://help.developer.intuit.com/s/article/QBD-IIF-Not-Supported).

## Next inspection checkpoint

1. Owner opens the isolated copy and confirms its full path in Product Information (F2); do not alter QuickBooks security settings.
2. Launch the existing approved SDK client at Medium integrity under `DESKTOP-G0LLAPU\HP`. Use the prior localQBD / `qbFileOpenDoNotCare` settings and interactive “ask every time” authorization only. Owner reviews any authorization dialog; do not enable unattended access.
3. Verify the full company path with `GetCurrentCompanyFileName()` before each request. Begin with bounded, read-only Bill, BillPaymentCheck, VendorCredit, PurchaseOrder, ItemReceipt, General Journal and report queries. Close the SDK session normally.
4. Compare AP detail and AP aging to transaction-level GL/Trial Balance views on the same date. Do not infer entries without matching source `TxnID` / line references.
5. Continue with AR, Banking, Journal, Inventory and financial statements; record only redacted counts, schema fields and accounting behavior.

## Read-only continuation checkpoint — 2026-11-16

### Active test file verification

The previously locked/password-inaccessible isolated copy was replaced for this session by a restored isolated working copy. QuickBooks Product Information (F2) was checked and the active company path matched:

`C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`

QuickBooks showed 1,912 transactions. The source Peera file was used only as the restore source; no financial transaction or company setting was changed during this inspection. A local QuickBooks backup was created and QuickBooks' Complete Verification reported success before the restored copy was opened. Continue to treat this restored copy as read-only.

### Additional UI/report catalogue verified

- **Vendor Center:** confirmed the active-vendor selector, search, list, vendor information pane, tabs for Transactions/Contacts/To Dos/Notes/Sent Email, transaction Show/Filter by/Date filters, history columns and Manage Transactions/Run Reports. No vendor profile was changed.
- **Vendor workflow menu:** confirmed Enter Bills, Upload & Review Bills, Pay Bills, Receipt Management, Create Purchase Orders, Receive Items and Enter Bill, Receive Items, Enter Bill for Received Items, Inventory Activities and Item List. A vendor-statement command was not present in the inspected Vendors menu; this does not rule out custom statements or other add-ons.
- **Customer workflow menu:** exposed Create Statements alongside Create Invoices, Enter Sales Receipts, Receive Payments, Create Credit Memos/Refunds and related receivables commands. The statement workflow was not launched, printed, emailed or saved.
- **Vendor Balance Summary:** opened read-only. The report header identifies home-currency values and all transactions; the visible report controls include date range, Show Columns, Sort By, search, Customize Report, Memorize, Print, E-mail, Excel, Hide Header, Collapse Rows and Refresh. Company-specific rows and balances are intentionally omitted.
- **Chart of Accounts:** opened read-only. The list exposes account name/number/description search, name, type, currency and balance columns, hierarchy indicators, an Include inactive control and Account/Activities/Reports/Attach actions. No account was opened for editing.
- **Trial Balance:** opened read-only with Last Month selected (1–31 October 2026), Accrual and Cash basis selectors, Show Filters, sort/search, drillable account rows and report toolbar actions. The report was visually inspected but the total debit/credit tie-out was not completed; no financial amounts are copied into this report.
- **Audit Trail:** opened read-only with Date Entered/Last Modified defaulted to Today. The report displayed columns for transaction number, entered/last modified timestamp, user, state, date, name, memo, account, split, debit and credit. The default current-day view contained no rows. Historical Audit Trail behavior and void/delete audit coverage remain unverified.
- **Report families observed in Reports menu:**
  - Company & Financial: Profit & Loss standard/detail and comparison variants, income by customer, expenses by vendor, realized/unrealized gains and losses, Balance Sheet standard/detail/summary/comparison/class, Net Worth Graph, Statement of Cash Flows and Cash Flow Forecast.
  - Customers & Receivables: A/R Aging Summary/Detail, Customer Balance Summary/Detail, Open Invoices, Collections, average days to pay, A/R Graph, transaction list by customer and invoice profitability reports.
  - Vendors & Payables: A/P aging, vendor balance, unpaid bills, A/P graph and transaction list by vendor (as recorded above).
  - Purchases: purchases by vendor/item summary/detail and open purchase orders, including detail and job views.
  - Inventory: inventory valuation summary/detail, stock status by item/vendor, turnover, assembly shortage, physical inventory worksheet, pending builds and negative inventory listing.
  - Banking: Deposit Detail, Check Detail, Missing Checks, Reconciliation Discrepancy and Previous Reconciliation.
  - Accountant & Taxes: Adjusted Trial Balance, Trial Balance, General Ledger, Transaction Detail by Account, Adjusting Journal Entries, Journal, Audit Trail, Closing Date Exception, credit-card audit trail, voided/deleted transaction summary/detail, transaction list by date, account listing, fixed-asset listing and income-tax preparation/summary/detail reports.

These menu observations establish UI availability and navigation only. Opening a report does not establish report accuracy, tax compliance, an account posting, or successful SDK support. No transactions were created, edited, voided, deleted, paid or reconciled.

### Updated evidence limits and next checkpoint

The Trial Balance and Audit Trail screens were opened, but no balanced total was verified, no GL row was tied to a source transaction, and no P&L, Balance Sheet, inventory valuation, bank reconciliation or tax report was run. Supplier statement generation, payment application, credit application, transaction editing, transaction journal behavior, inventory costing and historical transaction exports remain pending. Prior SDK master-data results are historical evidence only; the SDK connection/read-only transaction queries have not been revalidated against this restored working copy in this session.

Next: launch the already-approved Medium-integrity SDK client only after confirming the active file path; perform bounded read-only transaction and report queries, then compare the same date's AP Aging, General Ledger and Trial Balance. If the SDK client is unavailable, continue with read-only UI inspection and record that limitation. Do not create sample transactions under the current read-only authorization.

## Controlled transaction testing update — 2026-11-16

This update supersedes the earlier **read-only** operating constraint and the “no transactions were created” status above for activity after the Owner's explicit authorization. Testing remained confined to the isolated restored working copy. The original Peera company, TPZ ERP code/database and deployment branches were not changed. The pre-test backup and full isolated path were verified earlier in this session; Product Information (F2) was rechecked immediately before report verification.

### Isolated company and invoice

- Active QuickBooks file verified in Product Information (F2): `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`.
- QuickBooks Enterprise Solutions: Retail 24.0, Release R22P, 64-bit. Product Information showed 1,913 total transactions after the test invoice (1,912 before the invoice).
- The originally named test customer from the older copy was absent, so a synthetic customer `TPZ-QB-TEST-20261116-CUST-001` was added with AED default currency, no opening balance and no contact data. Customer Center showed zero balance before the invoice.
- Created invoice `TPZQB-INV01`, dated 16 Nov 2026, for AED 100.00, one unit of the existing inventory item `Apple|IPAD 6th 32GB`, with the selling price entered as AED 100.00. Customer Center then showed AED 100.00 open balance. The invoice was not edited, voided or deleted.
- Before save, QuickBooks warned that the item's configured $0.00 selling price could produce incorrect cash-basis reports. The unit price was explicitly changed to AED 100.00 and rechecked before saving.

### Verified transaction journal and report effects

The invoice Transaction Journal (Ctrl+Y) showed these entries:

| Account | Debit AED | Credit AED |
|---|---:|---:|
| Accounts Receivable | 100.00 | — |
| Sales | — | 100.00 |
| Cost of Goods Sold | 291.99 | — |
| Inventory Asset | — | 291.99 |
| **Journal total** | **391.99** | **391.99** |

The item picker showed quantity on hand of 49 before invoice entry. The invoice journal's COGS/inventory entries support an inventory cost of AED 291.99 for the sold unit; an after-posting inventory valuation report was not completed, so the resulting on-hand quantity/value is not independently verified. The item cost exceeded the AED 100.00 selling price by AED 191.99, a negative gross margin of AED 191.99 for this test line.

The General Ledger, on accrual basis for November month-to-date, showed the 16 Nov invoice as an AED 100.00 debit to Accounts Receivable. The Trial Balance was refreshed for 1–16 Nov 2026, accrual basis; its displayed debit and credit totals agreed. It included a Sales credit of AED 100.00 and COGS debit of AED 291.99. Thus the displayed trial balance remained balanced after posting. Company-wide totals are omitted, and no exact net change in other accounts or financial statement totals was inferred from those screenshots.

### Payment-form test and stopping point

Opened Customers → Receive Payments and selected the synthetic customer. QuickBooks loaded invoice `TPZQB-INV01`, showing AED 100.00 original amount and AED 100.00 due. The first Enter action on the blank payment form produced a “cannot record a blank transaction” warning; it did not save a payment. The payment column was clipped at the right edge of the MDI child window. A test AED 40.00 partial payment was not visibly accepted or reflected in the payment total; **no customer payment was saved**. Stopped because the amount field could not be reliably targeted. This is a remaining UI automation limitation, not evidence of payment behavior.

### Updated test matrix

| Workflow | Status after this update | Verified result / pending evidence |
|---|---|---|
| Synthetic customer creation | Test record created | AED currency, zero opening balance; Customer Center verified. |
| Inventory sales invoice | Created and journal verified | AED 100.00 A/R and sales; AED 291.99 COGS and inventory relief; debits equal credits. |
| General Ledger / Trial Balance | Read-only report verification | GL showed invoice A/R debit; accrual Trial Balance debit and credit totals tied for Nov 1–16. |
| P&L / Balance Sheet drill-down | Pending | Menu offers the report families, but the invoice-specific report change was not isolated and verified. |
| Inventory valuation after sale | Pending | Pre-entry item-picker quantity 49 and journal cost relief observed; post-entry quantity/value report not verified. |
| Partial/complete customer receipt | Pending | Payment form loaded invoice; no payment saved because the amount column was clipped and entry could not be verified. |
| Customer credit/refund, void/edit/reversal | Pending | No test performed. |
| Supplier bill/payment/credit and purchase-to-inventory | Pending | No test performed. |
| VAT, multicurrency, banking, reconciliation, journals | Pending | No test performed in this checkpoint. |

Next safe checkpoint: keep using only the verified isolated path, resolve the Receive Payments form width/focus issue through supported UI controls, and verify any entered value before save. Then test synthetic supplier procurement and inventory receipt with a uniquely labeled vendor/item and inspect the linked bill/payment journals, AP aging, inventory valuation, GL and Trial Balance. Do not use a real payment method, real-party data or real bank information. No transaction writes should be routed through the SDK until the appropriate SDK write scope is explicitly in force and the company path is checked before each operation.

### Follow-on UI inspection — 2026-11-16

- Product Information (F2) again showed the exact isolated file path above and 1,913 total transactions. This F2 check succeeded after the blank Receive Payments form was closed.
- Receive Payments loaded the synthetic customer's invoice `TPZQB-INV01` with AED 100.00 original amount and AED 100.00 due. The form ribbon exposed Auto Apply Payment, Discounts and Credits, Record Bounced Check (disabled), Enable Payment, Payment Links and Setup Recurring Payment. These are UI affordances only; no payment was recorded.
- A paste attempt aimed near the rightmost payment column did not produce a visible AED 40.00 amount or updated total. Subsequent accessibility state showed a separate edit field containing an unrelated item name; pressing F2 while focus was in that form caused “This field contains an invalid character.” The blank-transaction prompt was answered **No**, closing the form without saving. This directly reproduces the MDI/focus hazard and confirms coordinate clicks near the clipped right edge are not reliable evidence of field targeting.
- QuickBooks remained listed as running and its title showed the Peera company display name, but after this interaction the computer-use service timed out on both accessibility and screenshot requests, including after reconnecting the CUA runtime. No more QuickBooks interaction was attempted.

The previously reported incomplete payment attempt is therefore classified as **no payment saved**. The observed invalid-character warning is a focus/targeting failure, not a QuickBooks validation rule for payment amounts. Remaining work must resume when UI observation recovers or a safely authorized SDK path is available. The immediate next safe action is read-only company/path confirmation and direct inspection of the payment form's actual control bounds; do not repeat the prior coordinate paste.

### Report and purchasing follow-up — 2026-11-16

#### Company identity and vendor

- Reopened Product Information (F2) while the purchase-order form was open. It displayed the isolated file under `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`, QuickBooks Enterprise Retail 24.0 R22P 64-bit, and 1,913 transactions. The original Peera path was not active.
- Vendor Center lists synthetic vendor `TPZ-QB-TEST-20261116-VEND-001` with AED currency and 0.00 balance. No contact or opening-balance data was added.

#### Accrual report evidence for the test invoice

- Profit & Loss Standard for 1–16 Nov 2026, accrual basis, showed Sales 100.00, COGS 291.99, and gross/net income of -191.99. These report rows match the invoice Transaction Journal; no company-wide totals are reproduced here.
- Balance Sheet Standard as of 16 Nov 2026, accrual basis, showed net income of -191.99 and balanced total assets against liabilities and equity. Company-wide amounts were intentionally not copied into the review.
- Inventory Valuation Detail for the test period showed the invoice's one-unit reduction at unit cost 291.99 and post-sale on-hand quantity 48. The item picker had shown 49 before the invoice. This independently verifies the sale's quantity and cost relief.

#### Purchase-order form outcome

- Vendors → Create Purchase Orders opened the PO form. The focused vendor field's accessibility value was the synthetic vendor name. The form showed date 16 Nov 2026, default PO No. 1, AED currency, blank item grid, and `Create Item Receipts`, `Select Items Receipt`, `Mark As Closed`, `Print`, `Email`, and `Approval List` controls. These controls document UI affordances; their downstream behaviors were not tested.
- The PO line grid did not expose usable item-cell controls in the accessibility tree. No reliable method to enter and re-verify item, quantity, rate, and reference was established. The blank form was closed and the “record new transaction?” prompt answered **No**. No PO was saved, no inventory was received, and no bill or AP posting was created. Do not treat the PO form as a completed purchasing test.

#### Synthetic-vendor A/P Aging Summary

- Reports → Vendors & Payables → A/P Aging Summary opened the report as of 16 Nov 2026. Report basis was Accrual; aging columns were Current, 1–30, 31–60, 61–90, >90, and Total. The report header offered a 30-day interval (editable) and “Through (days past due)” 90 control.
- Customize Report → Filters → Name → Multiple names opened a searchable name selector. Searching the exact synthetic vendor returned one result; selecting it and applying the filter reduced the report to the test vendor. Every bucket and the total displayed 0.00, consistent with its zero vendor balance and no bill/payment being created. No other vendor balances are recorded here.
- The report toolbar exposed Customize Report, Memorize, Print, E-mail, Excel, Hide Header, Collapse Rows and Refresh. The filter strip showed Account (all A/P accounts), Date (Today), Name (multiple names), and Paid Status (Open); a report search field was also visible. This verifies the report controls and test vendor’s zero AP only, not allocation or credit behavior.

#### Current checkpoint matrix

| Workflow | Status | Observed result / limit |
|---|---|---|
| Test invoice, transaction journal, GL, TB | Verified | AED 100.00 A/R and Sales; AED 291.99 COGS and inventory credit; GL and TB tied as documented above. |
| P&L and Balance Sheet | Verified for the test period | P&L reflects -191.99 test-period net income; Balance Sheet shows the same net income and balances. |
| Inventory Valuation Detail | Verified | Post-sale quantity 48 and one-unit cost 291.99. |
| Vendor master | Verified | Synthetic vendor present, AED, zero balance. |
| A/P Aging Summary | Verified for synthetic vendor | Filtered to the exact synthetic vendor; all aging buckets and total were zero as of 16 Nov, consistent with no bill. Report filter/date/interval/export controls were observed. |
| PO, receiving, supplier bill/payment/credit | Form inspected; transaction behavior pending | PO form's controls/fields observed but no record saved because line cells could not be targeted and verified. |
| Customer payment/credits/refund | Pending | No receipt, credit or refund saved; payment field focus remained unreliable. |
| Banking, VAT, multicurrency, journal correction, audit history and data export | Pending | No transaction-level behavior or report result claimed in this checkpoint. |

The earlier “Remaining inspection blockers” paragraph above is historical. At this checkpoint QuickBooks UI observation is available and the isolated company is open; the item grid and payment field remain the blocking controls for safe transaction entry. SDK transaction/report extraction and migration exports have not been established in this continuation. Continue with bounded read-only reports and UI inspection where possible, and only resume form entry when the exact focused control and value can be verified before save.

### Supplier bill and A/P posting verification — 2026-11-16

This section supersedes the earlier note that `TPZQB-BILL01` was an unsaved draft and the earlier A/P Aging zero-balance observation. Product Information (F2) was checked before further work and displayed the complete isolated company path ending in `qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`; transaction count was 1,916. No original Peera company was opened.

- Saved synthetic vendor bill reference `TPZQB-BILL01` for `TPZ-QB-TEST-20261116-VEND-001`, AED 25.00, using existing AED Office Supplies expense account and memo “Synthetic test expense only”; no tax was entered. The save was made after explicit Owner action-time approval.
- The Transaction Journal identified QuickBooks transaction #1941, type Bill. It showed Dr Office Supplies AED 25.00 and Cr Accounts Payable AED 25.00; both sides totaled AED 25.00.
- Vendor Center filtered to the synthetic vendor showed the bill and a vendor balance of AED 25.00. A/P Aging Summary filtered to the same vendor showed Current AED 25.00, zero in all older buckets, total AED 25.00. This confirms bill-to-vendor-balance and bill-to-aging behavior for this simple expense bill.
- Date discrepancy: the approved preparation request named 18 Nov 2026 with due date 20 Nov, while the saved transaction journal and Pay Bills screen displayed transaction date 16 Nov and due date 26 Nov 2026. The UI did not preserve the intended dates. Treat the dates as a verified UI-entry discrepancy; do not use this record as date-field workflow evidence. The bill amount, account and postings were independently verified.
- Pay Bills showed the synthetic bill and AED 25.00 due, with vendor/reference selection and discount/credit controls. A partial-payment entry attempt produced a two-decimal warning and an ambiguous concatenated amount in the payment cell. The draft was not saved. No supplier payment, AP settlement or partial-payment result is claimed. This is a control-targeting limitation, not evidence that QuickBooks disallows partial payments.

| Workflow | Updated status | Evidence boundary |
|---|---|---|
| Supplier expense bill | **Verified** | AED 25.00 expense/AP; transaction #1941; vendor balance and synthetic-only AP aging tied. Date intended by approval differed from dates shown after save. |
| Supplier partial payment | **Pending** | Pay Bills UI inspected; attempted amount entry was ambiguous and warned. No payment saved. |
| Supplier credit, PO/receipt, inventory purchase | **Pending** | No credit or PO/receipt transaction saved; end-to-end purchasing/stock/AP remains unverified. |
| AP aging for synthetic vendor | **Verified after bill** | Current AED 25.00; older buckets zero; total AED 25.00, as of 16 Nov 2026. |


### Verified partial customer receipt and current checkpoint — 2026-11-16

This section supersedes prior statements that no customer payment was saved. An earlier UI automation attempt failed, but a later controlled attempt succeeded after maximizing the Receive Payments child form and confirming the visible payment amount and applied amount before saving.

- Before resuming, Product Information (F2) showed QuickBooks Enterprise Solutions: Retail 24.0, Release R22P (64-bit), the exact isolated path `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`, and 1,914 total transactions. The preceding invoice checkpoint showed 1,913.
- Customers → Receive Payments was opened and maximized. Selecting synthetic customer `TPZ-QB-TEST-20261116-CUST-001` loaded invoice `TPZQB-INV01` with original amount/due AED 100.00. AED 40.00 was entered in the visible Payment cell. Before save, the form showed Payment Amount AED 40.00, applied amount AED 40.00 and underpayment AED 60.00. Payment type was Cash; no bank account, check number or real-party details were entered.
- After save, the form cleared. Product Information showed the transaction count incremented to 1,914. Customer Center then showed AED 60.00 customer balance and the AED 40.00 payment dated 16 Nov 2026, account Undeposited Funds, alongside invoice `TPZQB-INV01` AED 100.00.
- The saved payment's Transaction Journal showed QuickBooks transaction number 1939, type Payment, date 16 Nov 2026, blank payment number/reference, debit Undeposited Funds AED 40.00 and credit Accounts Receivable AED 40.00. Both sides totaled AED 40.00. This is direct UI evidence, not an SDK response.
- The accrual Trial Balance through 16 Nov 2026 was refreshed after payment; displayed debit and credit totals still agreed. Customer balance moved AED 100.00→AED 60.00. Revenue/COGS did not change on receipt; the balance-sheet reclassification from A/R to Undeposited Funds is supported by the Customer Center and Transaction Journal. No bank deposit was made.
- Maximizing the Receive Payments child window made its right-side payment column accessible and resolved clipping for this workflow. It did not resolve purchase-order focus: Ctrl+A while PO No appeared focused invoked QuickBooks' global Chart of Accounts action. A later blank PO had vendor NOMAN selected and was explicitly discarded by answering No to the record prompt. No PO was saved. Do not infer vendor/item selection or purchasing behavior from that attempt.
- Immediately after discarding the blank PO, Product Information again showed the exact isolated path and transaction count 1,914. QuickBooks remained at the Trial Balance workspace; the blank PO was not recorded.

#### Corrected transaction matrix

| Workflow | Status | Verified result / evidence boundary |
|---|---|---|
| Test invoice, Transaction Journal, GL, Trial Balance | Verified | Invoice AED 100.00; A/R and Sales; COGS AED 291.99 and Inventory Asset credit; journal balanced; GL/TB reviewed. |
| P&L, Balance Sheet, inventory valuation | Verified for test sale | P&L test-period net income -AED 191.99; Balance Sheet displayed same net income and balanced; valuation quantity fell 49→48 at AED 291.99 cost. |
| Partial customer payment | Verified | AED 40.00 applied; due AED 100.00→AED 60.00; debit Undeposited Funds/credit A/R; payment transaction #1939; TB remained balanced. |
| Full payment, unapplied/overpayment, bounced check, deposit | Pending | No additional receipt scenarios or deposit-to-bank workflow tested. |
| Customer credit/refund, edit/void/reversal | Pending | No credit/refund or correction workflow completed. |
| PO, item receipt, supplier bill/payment/credit | Pending | PO form inspected but never saved; no purchasing-to-inventory/AP behavior verified. Vendor identity/focus and grid targeting remain unreliable. |
| Banking, reconciliation, VAT, multicurrency, journal corrections, transaction exports | Pending | No new behavior claimed. |

Substantive testing remains incomplete. The outstanding workflows above remain pending; the payment UI blocker is resolved for a standard partial receipt only. SDK transaction query/export capability has not been revalidated in this restored company session.

#### Customer statement preview — read-only inspection

Customers → Create Statements was opened and narrowed to the synthetic customer `TPZ-QB-TEST-20261116-CUST-001`. For statement date 16 Nov 2026, the UI exposed the A/R account, statement period vs. all open transactions, template/customization, per-customer output, invoice item detail, due-date and PO display toggles, and filters for zero balance, small balance, no activity and inactive customers. The date-period selection was 17 Oct–16 Nov 2026. Preview displayed the test invoice `TPZQB-INV01` AED 100.00, the 16 Nov payment AED 40.00 and an AED 60.00 current balance/due. Preview was closed without printing, emailing, or saving. This verifies customer statement presentation/reconciliation for the synthetic customer only; it does not prove external delivery behavior.

### Banking, journal and multicurrency UI follow-up — 2026-11-16 (read-only)

After the partial receipt and statement preview, additional QuickBooks UI paths were inspected on the verified isolated company. No transaction was saved and no company setting was changed.

- **Banking menu:** Write Checks, Order Checks & Envelopes, Enter Credit Card Charges, Use Register (Ctrl+R), Make Deposits, Transfer Funds, Reconcile, Bank Feeds and Other Names List were visible. Reports → Banking exposed Deposit Detail, Check Detail, Missing Checks, Reconciliation Discrepancy and Previous Reconciliation.
- **Reconcile setup:** opening Banking → Reconcile displayed a Begin Reconciliation dialog with account selector, statement date, beginning balance, ending balance and optional service charge/interest amounts with dates, accounts and class fields. Locate Discrepancies, Undo Last Reconciliation and Continue controls were present. Defaults showed account Cash A/c, statement date 31 Oct 2026 and a zero beginning balance; no amount was entered and Continue/Undo was not pressed. The dialog was canceled. This is workflow/UI evidence only; reconciliation matching, completion and discrepancy logic remain unverified.
- **Transfer Funds form:** exposed date, class, transfer-from and transfer-to accounts, transfer currency, amount, memo and exchange-rate fields plus Journal, Save & Close, Save & New and Clear actions. Default currency was UAE Dirham and the visible rate was 1 AED. The blank form was closed without saving; transfer posting and FX gain/loss behavior remain unverified.
- **Company → Make General Journal Entries:** blank journal form showed currency, Home Currency Adjustment checkbox, date, exchange-rate field, entry number, Adjusting Entry checkbox and line columns Account, Debit, Credit, Memo, Name, Billable and Class, with Find/New/Save/Delete/Reverse/Print/Attach File/Hide List controls. It was closed without saving. Reversal and adjusting-entry posting behavior remain unverified.
- **Company → Manage Currency:** menu offered Download Latest Exchange Rates, Currency Calculator, Home Currency Adjustment and Currency List. Currency List exposed search, currency/code/exchange-rate/as-of-date columns, Include inactive, and Currency/Activities/Reports controls. No rates were downloaded, no rate or currency was changed, and no home currency adjustment was run. Actual currency settlement/revaluation remains unverified.

These observations improve UI parity requirements but do not verify ledger behavior. TPZ B4/B5 should model both transfer legs with bank-register and statement-clearing states, and currency-aware source/home amounts plus effective rate and realized/unrealized FX journals. Require policy approval before activating automatic postings. Reconciliation needs a locked statement period, beginning/ending balance validation, matched/cleared items, discrepancy detection and auditable correction flow.

### Completed customer receipt allocation — 16 Nov 2026

After explicit action-time approval, the remaining AED 60.00 due on `TPZQB-INV01` was entered as a Cash receipt for the synthetic customer. Before save, the maximized Receive Payments form showed the exact synthetic customer, invoice reference, original amount AED 100.00, amount due AED 60.00, payment AED 60.00, and total selected transaction amount AED 60.00. The amount field and invoice row were visibly verified. The form cleared after save.

- Product Information (F2) immediately after save reconfirmed the isolated file path `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW` and showed transaction count 1,915 (prior count 1,914).
- Customer Center, filtered to `TPZ-QB-TEST-20261116-CUST-001`, showed balance 0.00 and rows for `TPZQB-INV01` AED 100.00, first payment AED 40.00 and second payment AED 60.00. Both payments used Undeposited Funds; no bank deposit was made.
- The second payment Transaction Journal displayed transaction #1940, date 16 Nov 2026, debit Undeposited Funds AED 60.00, credit Accounts Receivable AED 60.00, total debits and credits AED 60.00. Reference number was blank.
- Trial Balance was opened and changed to This Fiscal Year-to-date through 16 Nov 2026 after the second receipt, but the bottom totals were outside the visible area and not inspected. Therefore, the second receipt's journal is verified balanced, while a refreshed Trial Balance after the second receipt is not yet tied out. The earlier Trial Balance remained balanced after the AED 40.00 receipt.

This completes only the full-settlement scenario for the test invoice. It does not verify overpayment/unapplied cash, deposits, bank reconciliation, refund/credit, or payment reversal behavior.

### Supplier bill form targeting — pending save approval (16 Nov 2026)

On the isolated company, Vendor Center was filtered to exact synthetic vendor `TPZ-QB-TEST-20261116-VEND-001`; the row showed zero balance and no transactions. Starting New Transactions → Enter Bills from that vendor opened a bill form with the exact synthetic vendor preselected in AED, avoiding the earlier PO vendor-selection problem. The maximized form showed Bill/Credit selection, Bill Received checkbox, vendor/currency, date, reference number, amount due, terms, discount date, bill due date, class, memo, Expenses and Items tabs, totals, and actions including Select PO, Enter Time, Clear Splits, Recalculate, Upload & Review Bills, Pay Bill, Intercompany Transactions and Scan Barcodes.

A clearly identified unsaved draft was prepared for review: vendor `TPZ-QB-TEST-20261116-VEND-001`, reference `TPZQB-BILL01`, one Expenses line to existing AED expense account Office Supplies, AED 25.00, line memo “Synthetic test expense only,” and total AED 25.00. The form currently displays bill date 18 Nov 2026 and due date 20 Nov 2026. No tax was entered. At this checkpoint the bill is **not saved**; no AP or expense posting is claimed. The action-time save confirmation is pending. If not approved, close the form and answer No to QuickBooks' record prompt.

### Accounts Payable read-only continuation — 16 Nov 2026

Product Information (F2) was checked before navigation. It showed QuickBooks Enterprise Retail 24.0 R22P 64-bit, transaction count 1,916, and exact file `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`. No other company was active.

- **Date discrepancy:** The saved bill's Transaction Journal was previously observed as dated 16 Nov 2026. The current Pay Bills list showed due date 26 Nov 2026, AED 25.00 due, and selected bill reference visible as `TPZQB-BIL…`; lower detail showed exact vendor and bill ref `TPZQB-BILL01`. The earlier draft had visually shown 18 Nov / due 20 Nov. The present inspection did not open or edit the saved transaction. The reason the entered dates were not retained is **not yet verified**. A possible default-date/default-terms fallback is only a hypothesis; the saved transaction's Terms field and date-input commit sequence still need read-only inspection.
- **Pay Bills workflow:** The list showed Due on or before / Show all bills options; Filter By vendor and Sort By vendor; columns Date Due, Vendor, Ref No., Disc. Date, Amt. Due, Disc. Used, Credits Used and Amt. to Pay; and Clear Selections, Go to Bill, Set Discount and Set Credits. Payment controls previously visible were Date, Method (Check selected), To be printed (checked) and Account (Cash A/c). This directly verifies selection and payment setup controls, not a completed payment.
- **Amount-entry safety:** The Pay Bills edit control's accessibility value remained `10.0025.00` after the prior failed edit and a two-decimal-place warning. The amount is malformed/ambiguous; no payment was submitted. Remaining due after a partial payment and actual partial application behavior therefore remain unverified. The malformed value is only an unsaved UI draft, not a saved transaction.
- **Vendor navigation:** The Vendors menu visibly offered Vendor Center, Enter Bills, Upload & Review Bills, Pay Bills, Receipt Management, Create Purchase Orders, Receive Items and Enter Bill for Received Items, Inventory Activities and Item List. This confirms navigation affordances only. Earlier Enter Bills form controls exposed Bill/Credit, Expenses/Items, Select PO and Receive Items. No vendor credit, PO, item receipt, inventory purchase, or new bill was created during this continuation.
- **A/P Aging:** The already-open A/P Aging Summary had Open paid status, all A/P accounts, Date Today, and Name Multiple names filters. Previously verified synthetic-vendor-only post-bill result remains AED 25.00 Current / AED 25.00 Total. This continuation did not independently refresh or recalculate the report.

#### AP evidence status after continuation

| Workflow | Status | Verified boundary |
|---|---|---|
| Expense bill and AP posting | Verified | Bill #1941/ref `TPZQB-BILL01`: Dr Office Supplies 25 / Cr A/P 25; vendor balance and filtered A/P aging matched. |
| Bill transaction date and due date | Partially verified; cause unresolved | Journal date 16 Nov; Pay Bills due date 26 Nov; requested draft values 18/20 were not retained. No edit was made. |
| Pay Bills amount/account/allocation UI | UI inspected; payment behavior pending | AED25 due; method Check and Cash A/c observed; malformed amount draft `10.0025.00`; no save. |
| Vendor credits | Not tested transactionally | Bill/Credit selector and Set Credits controls observed only. Credit balances, application and debit/credit effects unknown. |
| PO / goods receipt / inventory purchasing | Not tested transactionally | Navigation/actions observed only. Purchase-to-receipt-to-bill linkage, quantity/cost and accounting effects unknown. |
| AP Aging | Verified for synthetic vendor after bill | Prior filtered report was Current 25 / Total 25; no fresh report run in this continuation. |

Do not infer QuickBooks' date fallback, payment remainder arithmetic, discount/credit application, PO receipt accrual, or stock valuation until the existing bill is inspected read-only and safe, separately reviewed synthetic test transactions are approved.

### AP date and payment UI follow-up — 16 Nov 2026

Read-only follow-up in the already-open isolated company; no transaction was saved or changed.

- The current Pay Bills screen lists `TPZQB-BILL01` for the synthetic vendor with **AED 25.00 Amt. Due** and **Date Due 28 Nov 2026**. This differs from the earlier captured Pay Bills due date of 26 Nov. The previously inspected saved bill form showed transaction date 16 Nov, due date 26 Nov and a visually blank Terms field. This pass did not reopen the bill because an unsaved payment-cell entry was present and navigating could discard or prompt to save it. Cause and the current saved header due date remain unresolved; do not edit the saved bill.
- The Terms dropdown had shown `<Add New>`, `1% 10 Net 30`, `2% 10 Net 30`, `Consignment`, `Due on receipt`, `Net 15`, `Net 30`, and `Net 60`. None was selected. Blank Terms plus the differing date observations are not enough evidence to infer QuickBooks' due-date rule or fallback.
- The current Pay Bills list presents Due on or before / Show all bills, vendor filter and sort, Date Due, Vendor, Ref No., Disc. Date, Amt. Due, Disc. Used, Credits Used and Amt. to Pay columns, plus Clear Selections, Go to Bill, Set Discount and Set Credits. The synthetic row is selected and shows due 28 Nov and AED25 due. This is list/selection UI evidence only.
- Accessibility state exposes an unsaved payment amount edit of AED 10.00. The lower payment controls are clipped in the current view; payment date, destination account, method and exact allocation were not all re-confirmed together. No payment was saved, so no partial-payment remainder, settlement journal or post-payment aging effect is claimed.
- The background A/P Aging Summary filters show Accrual, All accounts payable, Date=Today, Name=Multiple names and Paid Status=Open. Result rows were not available in the current view. The earlier synthetic-vendor Current AED25 / Total AED25 remains the last verified aging result; no fresh tie-out is claimed.
- Vendor credit, PO, receipt and inventory-purchase workflows were not reopened while the payment amount entry remained transient. Existing Bill/Credit, Set Credits, Select PO and Receive Items controls are affordance evidence only; no transaction or posting behavior is verified.

#### Current AP status and B2 requirements

| Workflow | Current verified state | Remaining evidence |
|---|---|---|
| Bill/AP posting | Saved synthetic bill #1941, Dr Office Supplies / Cr A/P AED25; vendor and prior Aging tied. | Preserve record; safely read back saved date, due date and terms. |
| Bill date/terms | Inconsistent: journal date 16 Nov; due date previously 26 Nov and currently Pay Bills shows 28 Nov; Terms visually blank; draft showed 18/20 Nov. | Find actual stored header values without editing; observe date/terms commit behavior. |
| Pay Bills | Selected AED25 bill; unsaved amount shows 10.00; lower payment controls clipped. | Verify amount, date, method, destination account and bill allocation in one view before separately approved payment save. |
| Vendor credit / PO / receipt / inventory buy | UI affordances only. | Read-only form/linkage inspection; transaction, quantity, valuation and journal behavior remain unverified. |
| A/P Aging | Prior synthetic-vendor Current25 / Total25 verified after bill; current result not visible. | Refresh Summary and Detail at explicit as-of date; reconcile vendor balances to AP control. |

B2 should preserve transaction date, selected terms ID and whether due date is derived or overridden; show a due-date preview; read back dates after save and flag mismatches. Payment drafts need explicit per-bill applied, unapplied and remaining amounts, date, method, destination account, reference, discounts and credits, with precision validation and a visible review summary. Aging should be as-of, posted/open-only, include explicit credit allocation and tie to AP control. Link PO, existing receipt/GRN and bill through canonical source IDs without duplicate stock movements. Keep received-not-billed accrual behind approved accounting policy.

### Owner-saved supplier payment verification — 16 Nov 2026

Owner reported paying the synthetic supplier bill. Read-only Product Information (F2) verified the exact isolated file `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`; Product Information now shows 1,917 total transactions, up from the prior 1,916. Vendor Center filtered to `TPZ-QB-TEST-20261116-VEND-001` now shows balance AED 15.00. A refreshed A/P Aging Summary as of 16 Nov 2026 shows Current AED 15.00 and Total AED 15.00 for the synthetic vendor. Together these verify that a supplier payment/settlement was recorded and reduced the open vendor balance by AED 10.00 from the prior AED25.00; AED10 is inferred from the before/after balance, not read directly from a payment detail form.

The current saved Enter Bills display still shows bill date 16 Nov 2026, due date 26 Nov 2026, blank Terms, and AED25 original amount. This contrasts with the earlier Pay Bills row due date 28 Nov. The conflict is not resolved. The current Vendor Center Bill Payments category displayed older history rows (2023) and did not expose the new transaction in the captured view. A Transaction Journal view was inspected but showed customer payment #1940 (AED60) rather than the supplier payment, so no supplier payment transaction number, actual payment date, method, cash/bank account, reference, or debit/credit journal is claimed. Do not infer those fields from the inferred AED10 balance reduction. No further payment was made by the investigator.

| AP checkpoint | Status after Owner payment | Evidence boundary |
|---|---|---|
| Bill #1941 | Original AED25 expense/AP transaction preserved. | Existing verified debit Office Supplies / credit A/P. |
| Supplier balance | **Verified AED15 outstanding.** | Vendor Center balance; prior was AED25. |
| AP Aging | **Verified Current15 / Total15** as of 16 Nov 2026. | Refreshed synthetic-vendor summary; no payment detail row. |
| Payment amount | **AED10 inferred** from AED25 to AED15 balance change. | Not directly viewed on the payment form. |
| Payment date/method/account/reference and journal | **Pending direct verification.** | Transaction Journal currently surfaced customer payment #1940; Vendor Center payment list did not surface the new row. |
| Bill due date | **Conflicting UI evidence:** saved bill form 26 Nov; Pay Bills list earlier 28 Nov. | No saved edit; investigate report refresh/date display behavior read-only. |

Next: obtain the new supplier payment record from a refreshed Vendor Center/transaction search or a journal scoped to the synthetic vendor and payment date, then verify amount, account credit, A/P debit, transaction reference and remaining bill balance. Preserve the current balance and do not duplicate the payment. Continue vendor-credit, PO/receipt/inventory purchasing and AP Aging Detail inspection read-only; any further write action must be performed by the Owner at the final save step.

### Supplier payment journal and purchasing/AP read-only follow-up — 16 Nov 2026

Product Information (F2) again showed the isolated company path `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW` and 1,917 total transactions. No transaction was saved or altered during this pass.

#### Owner-saved payment verified

Vendor Center filtered to synthetic vendor `TPZ-QB-TEST-20261116-VEND-001` listed exactly the test bill and its payment in the visible transaction history:

- Bill `TPZQB-BILL01`, 16 Nov 2026, Accounts Payable, AED25.00; bill reference and original amount match Journal #1941.
- `Bill Pmt -Check`, 16 Nov 2026, account Cash A/c, AED10.00; the transaction number/reference column was blank in the Vendor Center list.
- The payment row was opened read-only. It showed Cash A/c, synthetic payee, date 16 Nov 2026, AED10.00, and the bill allocation row for `TPZQB-BILL01`: bill amount AED25.00, amount due before this payment AED25.00, amount paid AED10.00, due date 26 Nov 2026.
- Ctrl+Y opened that payment's Transaction Journal: transaction #1912, type Bill Pmt -Check, date 16 Nov 2026; debit Accounts Payable AED10.00 and credit Cash A/c AED10.00. Debits and credits both total AED10.00.
- Vendor Center showed AED15.00 vendor balance. Refreshed A/P Aging Summary showed Current AED15.00 / Total AED15.00 as of 16 Nov 2026. A/P Aging Detail showed the bill row as Current, due 26 Nov 2026, Open Balance AED15.00. Together the payment allocation, journal and outstanding bill balance reconcile.

The due-date discrepancy is narrowed but not fully explained: the saved Enter Bills form, payment allocation row, and refreshed A/P Aging Detail all show 26 Nov 2026. An earlier Pay Bills list capture showed 28 Nov 2026. Treat 26 Nov as corroborated across three transaction/report surfaces; retain the 28 Nov Pay Bills display as an unresolved inconsistent UI observation. No bill edit was made.

#### Vendor credit, PO, receipt and inventory-purchasing boundaries

- A blank Enter Bills form was switched to Credit for inspection only. Credit form fields observed: Vendor, Date, Ref No., Credit Amount, Class, Memo, Expenses and Items tabs, with account/amount/memo/customer:job/billable/class columns. It was closed without saving. Vendor Center history for the synthetic supplier lists only the bill and bill payment; no supplier credit/application is verified. No credit journal or AP-aging credit treatment was tested.
- A blank Create Purchase Orders form was inspected then closed without saving. It exposes Vendor, Class, Drop Ship To, Template, Date, PO No., Ship To, line columns Item, Description, Qty, Rate, Customer and Amount; toolbar actions include Mark As Closed, Create Item Receipts and Select Items Receipt. Purchase Orders list reported no transactions of type Purchase Orders for All Purchase Orders / date range All. No PO, approval or bill link was created.
- A blank Item Receipt form was inspected and closed without saving. It displayed Vendor, Date, Ref No., Total, Class, Memo (default “Received items (bill to follow)”), Bill Received checkbox and Expenses/Items tabs. The separate “Enter Bill for Received Items” workflow opened Select Item Receipt for the synthetic AED vendor, with “Use item receipt date for the bill date” checkbox and Date/Ref No./Memo columns; the receipt grid was empty and canceled. Item Receipts list likewise reported no transactions for the full company/date filter.
- These observations establish form structure, navigation, and absence of existing PO/item-receipt records in this reference file. They do **not** establish PO approval, receipt quantity/valuation, inventory/AP accrual, bill matching, or inventory-account posting behavior. No inventory-purchasing transaction was created.

#### Verified AP workflow matrix

| Workflow | Status and observed result | Remaining work |
|---|---|---|
| Supplier bill | Verified: #1941, AED25 expense debit / A/P credit. | None for basic expense bill; tax and item-based bill not tested. |
| Partial supplier payment | Verified: Bill Pmt-Check #1912, AED10; Cash A/c credit / A/P debit. | Check number/reference blank; full/overpayment, discount, credit application and void/reversal not tested. |
| Open balance and Aging | Verified: AED15 current/open in Vendor Center, AP Aging Summary and Detail as of 16 Nov. | Broader vendor population, aging edge dates, credits and AP-control reconciliation remain. |
| Vendor credit | Blank UI inspected only; no saved credit. | Create only through Owner-performed final save; verify application/refund and journal. |
| PO / item receipt / bill-from-receipt | Forms and empty lists verified; no records. | Transaction-level links, partial receipts, costs, duplicate prevention and accounting entries remain. |

B2 requirements from this checkpoint: represent bill payments as separate auditable records with explicit method, cash/bank account, date, reference/check number and bill-level allocation; verify the payment journal, vendor balance and AP Aging reconcile. Support credit balances and allocation preview without implying they post. Link PO→existing receipt/GRN→bill through stable source IDs; handle partial receipt/bill quantities without duplicate inventory events. Keep received-not-billed accrual disabled pending approved accounting policy.

### Owner-saved vendor credit verification — 16 Nov 2026

After the Owner reported saving the synthetic credit, Product Information confirmed the isolated file `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW` and total transactions increased 1,917→1,918. Vendor Center for `TPZ-QB-TEST-20261116-VEND-001` now shows balance AED10.00 and these rows:

- Bill `TPZQB-BILL01`, 16 Nov 2026, AED25.00.
- Bill Pmt-Check, 16 Nov 2026, Cash A/c, AED10.00.
- Credit `TPZQB-CREDIT01` (displayed as `TPZQB CREDIT01` in parts of the UI), 16 Nov 2026, Accounts Payable, AED5.00.

The credit transaction's Transaction Journal is #1913, dated 16 Nov 2026, and balanced: debit Accounts Payable AED5.00; credit Office Supplies AED5.00. The vendor-credit form showed AED5.00 credit total and an Office Supplies expense line AED5.00. This verifies a vendor credit posting to reduce expense and A/P in the tested configuration.

Refreshed AP Aging Summary as of 16 Nov shows synthetic-vendor Current AED10.00 / Total AED10.00. AP Aging Detail separates a Credit row with open balance **-AED5.00** from the Bill row with open balance **AED15.00**, due 26 Nov 2026; Total Current is AED10.00. Thus the credit reduced the net vendor/aging total but did not reduce the bill's own open balance. This directly evidences an unapplied vendor credit displayed separately from the still-open bill; applying credits to bills remains untested.

**Data-quality issue:** The saved credit memo contains unrelated text, “Continue the live QuickBooks Desktop Enterprise 2024 investigation from the current verified state.” It was not intentionally entered as accounting memo content; UI text entry had inserted prior prompt text. The credit was not edited after save. Owner should review and correct the memo in QuickBooks if a clean test record is required; preserve the current reference, amount and posting evidence.

| Workflow | Verified result | Remaining |
|---|---|---|
| Vendor credit entry | Saved synthetic AED5 credit, ref `TPZQB-CREDIT01`, expense allocation Office Supplies, journal #1913. | Memo needs Owner review; tax and item-based credit not tested. |
| Credit/AP journal | Dr Accounts Payable 5 / Cr Office Supplies 5, balanced. | Other account/category and VAT behavior untested. |
| Aging and allocation | Credit -5 and bill +15 shown separately; net vendor balance and Current/Total Aging 10. | Explicit credit application against bill, refund, partial allocation and unapplied-credit settlement remain untested. |

No additional transaction was created in this verification pass.

### Memo correction attempt blocked by MDI focus — 16 Nov 2026

At the Owner's request to address the saved credit memo quality issue, the existing credit form was opened read-only and showed the saved credit values and unrelated memo text. When attempting to select the memo contents, Ctrl+A transferred control to QuickBooks' global Chart of Accounts action instead of selecting text in the credit form. The credit record was not saved, changed, or otherwise modified during this attempt; its existing AED5 amount and Journal #1913 remain intact. Stop UI text edits until reliable child-window targeting is restored. The memo remains for Owner review/correction.

Other remaining work (credit application, overpayment/discount/void/refund, purchasing/receipt/inventory posting, expanded AP aging, and non-AP modules) was not attempted in this pass because the observed focus shift made continued UI interaction unsafe.

### Credit-application preview — live UI verified (16 Nov 2026)

The saved credit memo was left unchanged as requested. Product Information (F2) reconfirmed the active isolated company path:

`C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`

The file showed 1,918 transactions before any action in this continuation. Pay Bills was filtered to synthetic vendor `TPZ-QB-TEST-20261116-VEND-001` and showed only `TPZQB-BILL01`: due date 26 Nov 2026, amount due AED15.00 (original AED25.00 less the previously saved AED10 payment). Selecting the bill displayed AED5.00 available credit. Set Credits opened a review dialog identifying `TPZQB-CREDIT01`, credit amount AED5.00, amount to use AED5.00, credit balance AED0.00, bill amount due AED15.00 and amount to pay AED10.00. Clicking Done applied the AED5 allocation in the Pay Bills preview: Credits Used AED5.00 and Amt. to Pay AED10.00. This directly verifies the allocation preview math and that QuickBooks accepts the full remaining credit against the open bill.

The payment form showed date 16 Nov 2026, method Check, destination account Cash A/c, and “To be printed” selected. No payment was saved and no posting, transaction-count change, new payment journal or refreshed aging result was observed. Therefore the **credit allocation preview is verified, but saved application effects remain unverified**. The displayed AED10 payment is a separate proposed cash settlement; the credit-only remainder would be AED10. Do not treat the preview as a posted application.

| Capability | Updated evidence | TPZ B2 requirement |
|---|---|---|
| Credit allocation preview | **Live UI verified, unsaved:** AED5 `TPZQB-CREDIT01` selected against AED15 open `TPZQB-BILL01`; bill payment preview becomes AED10, credit balance becomes zero. | Provide an explicit draft allocation preview with source credit/bill IDs, before/after balances, and no posted-state change until approved. **P0** |
| Payment form metadata | **Live UI verified, unsaved:** payment date 16 Nov 2026, Check, Cash A/c, “To be printed”; amount to pay AED10 after credit. | Keep payment metadata and credit allocation distinct; require exact final review and Owner approval before posting. **P0** |
| Posted credit application / aging / GL | **Not verified:** payment preview was not saved. | Reconcile bill open amount, unapplied credit, vendor balance, AP Aging and any journal only after an Owner-saved test. **P0** |

This supersedes earlier statements that the credit application UI preview had not been tested. The saved memo remains untouched. No payment or other transaction was created by this continuation.

### Owner-saved credit application and bill settlement — verified (16 Nov 2026)

After the prior unsaved preview, the Owner reported paying the selected bill. The Pay Bills Payment Summary explicitly stated the payment was successfully recorded. It showed payment date 16 Nov 2026, Payment Account Cash A/c, Payment Method Check, vendor `TPZ-QB-TEST-20261116-VEND-001`, bill due date 26 Nov 2026, amount paid AED10.00, and total AED10.00. The preceding Set Credits dialog had shown `TPZQB-CREDIT01` AED5.00 fully used against `TPZQB-BILL01`, reducing the bill's prior AED15.00 balance to the AED10.00 cash payment. Product Information then reconfirmed the isolated file path above and transaction count increased from 1,918 to 1,919.

The saved Bill Payments (Check) form showed Cash A/c, vendor, date 16-11-2026, check amount AED10.00, and the `TPZQB-BILL01` allocation with bill amount AED25.00, amount due AED10.00 before this payment, and amount paid AED10.00. The form therefore supports the payment-to-bill link and amount; the bill is now fully settled when combined with the earlier AED10.00 payment and AED5.00 credit.

The payment Transaction Journal is #1914, dated 16 Nov 2026, type Bill Pmt-Check. It is balanced: debit Accounts Payable AED10.00 and credit Cash A/c AED10.00, total debits and credits AED10.00. This journal records the cash payment; the earlier saved vendor credit separately posted Dr A/P AED5 / Cr Office Supplies AED5 in journal #1913. No separate journal for applying the already-posted credit was observed.

Vendor Center after payment showed synthetic vendor balance AED0.00 and history rows for bill AED25.00, prior Bill Pmt-Check AED10.00, credit `TPZQB-CREDIT01` AED5.00, and the new Bill Pmt-Check AED10.00. A/P Aging Summary refreshed as of 16 Nov 2026; it did not list the zero-balance synthetic supplier and showed all-vendor total AED37,215.23. The report was summary-only, so this is not a direct zero-open-detail row or AP-control GL reconciliation. No post-payment Trial Balance tie-out was performed.

| Capability | Updated status | Evidence boundary / next work |
|---|---|---|
| Vendor credit application | **Verified through saved Pay Bills workflow:** AED5 credit was fully used against the AED15 bill balance; credit remainder AED0 and bill cash amount AED10. | No separate credit-application journal was identified; verify allocation detail/read-back and aging detail if needed. |
| Bill settlement | **Verified:** original bill AED25 less prior payment AED10, credit AED5, and new payment AED10 = AED0; Vendor Center shows zero. | No overpayment, partial credit, discount, refund, void/reversal tested. |
| Payment record | **Verified:** Bill Pmt-Check journal #1914, 16 Nov, Cash A/c, AED10, linked to bill; Dr A/P / Cr Cash, balanced. | Check number/reference is blank/not shown; no bank reconciliation. |
| AP Aging | **Refreshed:** all-vendor summary total AED37,215.23; zero-balance synthetic supplier omitted. | No post-save Aging Detail row or AP control tie-out; do not claim full reconciliation. |

The earlier unposted-allocation preview is superseded by this completed Owner-saved transaction. No additional save was performed by the investigator.

### Full supplier AP reconciliation — read-only read-back (16 Nov 2026)

Product Information reconfirmed the active isolated file `C:\Users\HP\.codex\visualizations\2026\11\11\01a1c988-2b79-7562-a1f0-7a46144cf5d1\qb-isolated-test-20261116\Peera-TPZ-QB-TEST-20261116.QBW`, with 1,919 total transactions. No transaction was changed during this reconciliation.

Vendor Center, filtered to `TPZ-QB-TEST-20261116-VEND-001`, showed balance AED0.00 and exactly four transaction rows under the visible All Transactions / All date filters: one bill `TPZQB-BILL01` (AED25.00), two Bill Pmt-Check entries (AED10.00 each, both Cash A/c, 16 Nov), and one Credit `TPZQB-CREDIT01` (AED5.00, 16 Nov, Accounts Payable). There is only one credit row in this supplier history; AED5.00 is the total credit, not one of multiple visible adjustments. No discount row is present. The saved bill form shows the green PAID state.

The complete balance bridge is:

| Activity | Amount | AP effect |
|---|---:|---:|
| Bill `TPZQB-BILL01` | AED25.00 | Increases A/P 25.00 |
| First Bill Pmt-Check, journal #1912 | AED10.00 | Reduces A/P 10.00 |
| Sole vendor credit `TPZQB-CREDIT01` | AED5.00 | Reduces A/P 5.00 |
| Second Bill Pmt-Check, journal #1914 | AED10.00 | Reduces A/P 10.00 |
| Discount used | AED0.00 | No effect |
| **Open supplier balance** | **AED0.00** | **25 - 10 - 5 - 10 = 0** |

The missing AED10 in the earlier partial summary is the other AED10 Bill Pmt-Check transaction. Both payments are separate records; the second was saved after the AED5 credit had been selected in Pay Bills. The first payment journal #1912 was directly re-opened and verified: Dr Accounts Payable AED10 / Cr Cash A/c AED10, balanced. The second payment journal #1914 was previously directly verified with the same balanced posting. The credit was directly re-opened; its form shows AED5.00 and a single Office Supplies expense line. Its balanced journal was previously captured as Dr A/P AED5 / Cr Office Supplies AED5 (previous documentation identifies it as #1913). No separate credit-application journal was observed; the existing credit journal plus the subsequent cash payment settle the bill.

A/P Aging Detail was refreshed as of 16 Nov with `Paid Status: Open`; the fully settled vendor is not shown among open items. A/P Aging Summary total is AED37,215.23. The refreshed accrual Trial Balance for 1 Jan–16 Nov shows Accounts Payable credit AED37,215.23 and total debits and credits AED460,705.36 each. Thus the all-vendor AP Aging Summary ties to the Trial Balance A/P control balance and the Trial Balance balances. This is a report-level control tie-out; no separate vendor-subledger-to-GL transaction export was run.

#### Bill-date and transaction-number discrepancies

The saved bill form opened read-only shows transaction date 18 Nov 2026 and Bill Due 20 Nov 2026, reference `TPZQB-BILL01`, total AED25, and PAID. Its Transaction Journal read-back shows a Bill dated 16 Nov 2026, A/P credit AED25 and Office Supplies debit AED25, balanced. The payment allocation row and Payment Summary showed due date 26 Nov. These conflicting saved-form, journal, and payment dates remain unresolved; no edit was made. The current Transaction Journal read-back labels the bill `Trans #1911`, while earlier documentation called it #1941. Use #1911 as the current screen observation and treat #1941 as superseded pending a stable transaction-number read-back. Amount and posting account evidence are consistent; date/reference metadata is not fully consistent across views.
