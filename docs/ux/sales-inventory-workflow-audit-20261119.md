# TPZ ERP — Sales & Inventory Workflow Simplification Audit (Phase 2A)

**Date:** 2026-11-19  
**Basis:** Static review of GitHub `main` after workspace navigation UI promotion.  
**Status:** Initial code-grounded audit; **not** a performance benchmark, staff usability study, or approval to change business logic.

## Objective and constraints

Reduce page transitions, repeat entry, waiting, and unnecessary page loading in Sales and Inventory while **reusing existing authorized services**. Preserve Access Control, per-product/brand/warehouse/marketplace responsibilities, shipment/QC rules, idempotency, immutable invoices, inventory ledgers, and append-only/audited corrections. Do not change accounting posting activation. Isolated development → Staging → focused UAT → Main/UAT only after Owner approval. No migrations or production data edits during this audit.

## Confirmed findings from source

### S1 — Sales tab changes still prepare both order lists

In `app/Filament/Pages/SalesWorkspace.php:getViewData()`, lines ~90–136, both scoped Sales Orders and Web Sales queries are built, filtered, then **both paginated to 10 records regardless of selected tab**. Quotations alone are guarded by `activeTab === 'quotations'`. Sales and Web Sales pagination are correctly named independently; the concern is unnecessary data retrieval, **not incorrect authorization**.

**Opportunity:** Fetch only the active table on Sales/Web Sales tabs; on Overview fetch a deliberately small, scoped recent-activity dataset. Supply tab totals with separately scoped counts, avoiding double counting and duplicate full lists. Preserve independent financial field gates and existing filters.

### S2 — Sales summary includes repeated count queries

`SalesWorkspace.php` calculates Sales/Web Sales totals through paginators, explicit counts for Quotations and Tax Invoices (~139–153), and three separate status counts for Overview alerts (~168–196), alongside `ErpDashboardService::forUser(... ['attention'])`. This may be reasonable for small datasets, but is a **candidate for query profiling** as records increase. No execution-time evidence has yet been collected.

**Opportunity:** Instrument per-tab query count and duration; consider aggregated responsibility-scoped status counts or short-lived permission-safe snapshots when profiling shows a benefit. Do not cache across employee scopes or expose restricted financial totals.

### S3 — Sales operational actions require opening separate resource pages

In `resources/views/filament/pages/sales-workspace.blade.php`, records link to individual `OrderResource`, `WebSalesOrderResource`, and `QuotationResource` view pages (~240–318); “Open full module” links navigate to resource lists. This is a real UI transition and matches the owner's reported friction. Current previews are primarily **read-only**, not the equivalent of full order operations.

**Opportunity:** Introduce a permission-scoped **in-page details drawer** (read-only first) for order items, warehouse, allocations, fulfillment/QC state and actionable next step. Keep “Open full module” as an escape hatch. Only then move eligible existing actions into a shared authorized action controller; do **not** duplicate fulfillment, reservations, invoicing, or manual sourcing business logic.

### I1 — Inventory prepares list and summary beyond active tab

In `app/Filament/Pages/InventoryWorkspace.php:getViewData()`, lines ~78–125, inventory-location overview, a 15-row inventory paginator, scoped movement list, and low-stock derivation are prepared, while one active module list of 10 records is also optionally paginated. The ERP Dashboard snapshot is already scoped to Overview (good). On other tabs, the inventory paginator and location summary can still be computed.

**Opportunity:** Gate heavy overview-only summaries and inventory-list pagination by active tab, retaining only lightweight permitted counters needed for top tab badges. Profile actual query time before making any caching decisions.

### I2 — Inventory counts may be recomputed on every interaction

`InventoryWorkspace.php` lines ~142–156 computes separate counts for My Inventory, Products, Requests and Transfers. The counts are policy-filtered; do not replace them with unscoped aggregate queries.

**Opportunity:** Measure response time/query count for `setTab`, `updatedSearch`, pagination and extra-module selection. Consider batch/optimized counts, scoped lazy loading or tightly keyed short-duration caching **only if needed**.

### I3 — Inventory work requires leaving workspace

In `resources/views/filament/pages/inventory-workspace.blade.php`, product/movement rows and request/transfer actions mostly navigate to Filament resource pages (~140, 174, 203, 247, 275, 298). The workspace is a navigation/preview layer, rather than a single-task workflow.

**Opportunity:** First deliver a read-only stock detail drawer with SKU, physical availability, reserved, allocated, location and scoped movement history. Follow with stock request review/approval and transfer action panels **only where existing service/authorization semantics can be reused**.

### X1 — Generic extra-module previews are permission-scoped but add queries

`app/Services/Navigation/WorkspaceRecordPreviewService.php:forLink()` (~29–61) resolves authorized resource, runs a `count()`, loads up to eight records, then per-record `canView` checks. Safe patterns exist, but the workflow still points to full resource detail pages. Any optimization must preserve both `canViewAny` and **record-specific** `canView` checks.

**Opportunity:** Keep extra-module previews lazy (selected only). Avoid preloading them on Overview. Add explicit placeholder when selected destination does not expose an in-page authorized action.

## Proposed delivery increments (prioritized)

| Priority | Increment | Scope | Definition of done |
|---|---|---|---|
| P0 | Performance baseline | Measure **authenticated** Sales/Inventory Overview, tabs, search, and pagination using Owner and restricted staff roles on Staging; record SQL count, server response, browser interaction time, and memory. | Before/after metrics and role coverage captured; no sensitive data leaked. |
| P1 | Tab-scoped loading | Only fetch list rows/summary data actually needed by active Sales/Inventory tab; maintain counts and alerts correctly. | Same data visible to authorized users, no cross-scope exposure, faster/equal response at baseline dataset, focused tests pass. |
| P1 | Read-only order detail drawer | Open Sales/Web Sales record details inside Sales Workspace, with scoped items, status, warehouse, reservations/QC indicator and link to full module. | No page leave for common inspection; no write route; scoped/denied user tests. |
| P1 | Read-only inventory/stock drawer | Show product SKU and available/reserved/allocated stock + scoped recent movements inside Inventory Workspace. | Responsible stock only; movement visibility requires separate grant; fast and accessible. |
| P2 | In-place actions for eligible workflows | Stock request creation/review, then Sales/Web Sales eligible processing; invoke existing services and preserve validation/idempotency/transaction boundaries. | No duplicate posting; clear failure feedback; audit trail preserved; approved on Staging. |
| P2 | Filter/search continuity | Keep selected tab, search, pagination and drawer state stable across successful in-page operations. | No unexpected resets or full reloads; keyboard/mobile usable. |
| P3 | Broader workspaces | Purchasing, Returns/QC, Finance, HR after validating patterns. | Individual scoped plans and UAT. |

## Required UAT scenarios

- Owner, Admin, permitted Staff and explicitly denied Staff: compare visible tabs, records and actions.
- Order with mixed product responsibilities: no unauthorized details/actions.
- Web Sale with renewed QC requirements: do not bypass QC dispatch or device assignment.
- Shipment retries and stale status: no duplicate fulfillment, inventory ledger or invoice changes.
- Inventory movements independently denied: do not leak movements via drawer.
- Search/filter/page interaction and small screen layout: stable, accessible, no surprise navigation.
- Browser network requests: tab switch and drawer open should update the relevant panel only, without full-document navigation.
- Staging authenticated page smoke test after deploy: guard against another PHP/Blade HTTP 500 (login HTTP 200 alone is insufficient).

## Open evidence gaps

- No staging SQL profiler, authenticated HTTP trace, browser timing or database cardinality measurements collected in this static review.
- Employee-reported pain points have not yet been mapped to exact task, user role, click sequence and expected result.
- No proof yet that selected service actions are reusable from a Livewire component without refactoring.
- No Main/UAT server deployment log provided for the most recent UI release; Owner reported “done all.”

## Recommendation

Begin with **P0 baseline + P1 tab-scoped loading** on an isolated development branch. Keep edits small and reversible; verify focused role-scope tests and page smoke checks; deploy via guarded PowerShell to Staging. Once performance and access are validated, add **read-only** details drawers before any transactional in-page actions.
