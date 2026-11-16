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
