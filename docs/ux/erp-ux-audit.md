# TPZ ERP UX Lab — Phase 1 Audit Baseline

This audit is an implementation baseline for the isolated UX Lab. It is based on the current Filament pages, services, and authorization boundaries; it does not change production workflows or operational records.

| Area | Current entry point | Friction observed | Prototype target | Guardrail |
| --- | --- | --- | --- | --- |
| My Workspace | `MyWork` / `MyWorkService` | Work is split across modules and queues | One prioritized, role-scoped queue | Existing task authorization and responsibility scopes |
| Main Dashboard | ERP dashboard pages/services | KPIs and actions compete for attention | Action-first summary with drill-down | Existing dashboard and financial permissions |
| Sales Orders | Sales Order resources | Context and next action are separated | Searchable order context and safe next action | Reservation, invoice, and amendment rules unchanged |
| Web Sales Dashboard | `WebSalesDashboard` | Dashboard and order follow-up are separate | Pipeline, exceptions, and owner-scoped actions | `WebSalesAuthorization` and financial permissions |
| Web Sales Orders | Web Sales resources/pages | Manual and warehouse lines need clear source context | Mixed-source order timeline | Existing fulfilment and inventory services |
| Stock Requests | Stock Request pages | Approval, transfer, and remaining quantity need one view | Quantity-progress work queue | Source-holder permissions and transfer idempotency |
| Stock Transfers | Transfer resources | Dispatch/receipt status is distributed | Shipment timeline and outstanding quantities | Existing stock movement writer |
| Stock Adjustments | Adjustment pages | Drafts and posting states compete | Draft-first review surface | Save-all and ledger rules unchanged |
| Product / Inventory | Inventory pages and search services | Location, holder, and availability context is fragmented | Search-first inventory cockpit | Responsibility and warehouse scope |
| Purchasing / Receiving | Purchasing resources and GRNs | Purchase date and receipt timestamps differ | Separate business date and receipt timeline | Immutable receipt and accounting history |
| Returns / QC | Customer Return and QC pages | Evidence and decision are multi-step | Evidence-led queue | Existing QC and damaged-stock workflow |
| Damaged / Safe-T | Inventory/QC services | Exceptions require cross-navigation | Exception workbench | No direct balance corrections |
| Accounting / Reports | Finance pages and reports | Owner-only figures need clearer context | Reviewable financial summaries | Posting, period-lock, and permission gates |
| Notifications | Notifications page/services | Alerts are disconnected from actions | Actionable notification inbox | Existing notification authorization |
| HR / Leave | HR pages | Policy and employee actions are separate | Role-specific HR workspace | Leave/attendance calculations unchanged |
| Tasks / Responsibilities | Task pages/services | Assignment and responsibility are separate concepts | Assignment-aware work queue | Existing task and responsibility authorization |
| Global Search / Mobile | Search providers and mobile API | Different surfaces can expose different context | Shared interaction vocabulary | Existing API scopes and serializers |

## Phase 1 prototype scope

`UxLab` is Owner-only and read-only. It provides module selection, Simple/Advanced mode, desktop/mobile preview, Current/Proposed comparison, reset, and session-only feedback. The first detailed prototype is My Workspace. Other modules are represented as safe preview cards until their existing read services are mapped in a subsequent approved increment.

## Prototype V2 visual directions

- **TPZ Executive:** warm ivory canvas, restrained copper accent, compact management tables, and decision-oriented summaries.
- **TPZ Modern Workspace:** cool sage canvas, terracotta accent, contextual workspace navigation, action queues, drawers, and progressive order flow.

The Owner can switch directions, workspaces, screens, role previews, and responsive viewport in the UX Lab. Order creation, reservation, shipment, and inventory actions are simulation-only Livewire state; no operational service is called.

## Prioritized next increments

1. My Workspace: action queue and linked-record context using `MyWorkService` read methods.
2. Main Dashboard and Web Sales Dashboard: information hierarchy and permission-scoped drill-down prototypes.
3. Sales Orders and Web Sales Orders: mixed-source context and safe action presentation.
4. Inventory, Stock Requests, Stock Transfers, and Stock Adjustments: quantity-progress and responsibility-aware views.
5. Purchasing, Receiving, Returns, QC, Accounting, Notifications, HR, and Mobile: shared interaction patterns and role-specific read models.
