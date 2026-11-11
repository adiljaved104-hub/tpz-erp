# QuickBooks Desktop Enterprise 2024 — Direct Review Findings

Review branch: `codex/quickbooks-2024-direct-review-20261111`

## Access and company identity

- `QUICKBOOKS_ACCESS=VERIFIED`
- Product observed: QuickBooks Enterprise Solutions Retail 24.0 (Desktop Enterprise 2024).
- Open company observed: **Peera International Trader FZ-LLC**.
- The company file is reference/testing data for parity analysis, not TPZ ERP's live UAE books.
- No company records, transactions, preferences, closing dates, or accounting settings were modified.

## Workflows and reports inspected

The read-only desktop review exposed the following native accounting areas:

- Chart of Accounts with AED accounts, including receivables, inventory asset, payables, and sales-tax payable.
- General Journal Entries, Closing Date, and Manage Currency controls.
- Profit & Loss, Balance Sheet, Cash Flow, realised/unrealised gain/loss, and expense reports.
- Accountant & Taxes: Adjusted Trial Balance, Trial Balance, General Ledger, Transaction Detail by Account, Journal, Audit Trail, Closing Date Exception, Voided/Deleted Transactions, and tax reports.
- Customers & Receivables: AR ageing summary/detail, open invoices, collections, average days to pay, and customer transaction lists.

These observations are workflow and parity references only; they are not a request to copy QuickBooks branding or proprietary implementation.

## Historical data export/import capability

The reviewed report screens provide the normal QuickBooks report export workflow (report output can be prepared for spreadsheet/CSV-style export). QuickBooks also exposes standard historical transaction exchange surfaces through its supported SDK/qbXML/IIF mechanisms, subject to the company file's permissions and a successful authenticated session.

During the direct SDK diagnostic, `OpenConnection2` succeeded but `BeginSession` returned `0x80040438` while a second dynamic `QBW.EXE` process was present. Therefore no transaction-level SDK export was executed and no sensitive company data was copied into this repository. A future import/export rehearsal must use a verified disposable copy and preserve the original company file.

## TPZ accounting implications

The parity baseline should cover:

1. Separate legal-entity books and chart/account types.
2. Journal entry validation, posting, period closing, and compensating reversals.
3. General Ledger and Trial Balance as-of reporting.
4. AR ageing/open-invoice reporting and customer transaction history.
5. Inventory, payables, sales-tax, audit-trail, and voided/deleted transaction reporting.
6. Explicit permissions and immutable audit history for exports and posting.

No TPZ operational accounting posting is enabled by this review.
