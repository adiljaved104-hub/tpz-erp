# TPZ ERP Project Context and Roadmap

## Document Purpose

This document is the approved high-level context, requirements baseline, and implementation roadmap for the TPZ ERP system. It defines the intended scope and the rules that future design and implementation work must preserve.

This is a planning document only. It does not authorize application-code changes, database changes, migrations, package installation, deployment, or any major implementation. Each major module or architectural change requires explicit approval before work begins.

## Approved Business Context

- The system is an ERP and inventory management application built with Laravel and Filament.
- The operating currency is AED.
- The business currently operates one physical warehouse.
- Products are mainly laptops and electronics.
- Products are identified and managed by title and SKU, not by serial number.
- Inventory can be virtually assigned to employees by brand while remaining part of the central inventory record.
- Inventory quantities must never become negative.
- Average cost is calculated from completed stock purchases using weighted-average costing.
- A product's `cost_price` is a reference or entered price and must not automatically become its average cost.
- Returns assessed as `OK` return to available inventory through a recorded stock movement.
- Damaged returns move to the Damaged/Safe-T area through a recorded stock movement.
- Gross profit, average cost, stock value, and other sensitive financial information are visible only to the owner or explicitly approved owner-level roles.
- Every employee has a separate login.
- Important business and administrative actions are recorded in activity logs.

## Core Architectural Requirements

### Single Source of Truth for Inventory

- The inventory ledger and its stock movements are the authoritative source for all on-hand, available, reserved, damaged, and virtually assigned quantities.
- Products, purchases, sales, returns, adjustments, warranty cases, Safe-T claims, and employee brand assignments must not maintain conflicting independent stock balances.
- Stock summaries and dashboards must be derived from, or transactionally synchronized with, the inventory ledger.
- Every inventory-changing workflow must use a single approved inventory service or domain workflow and a database transaction.
- Negative stock must be prevented at the point of mutation, including under concurrent requests.
- The initial operating scope is one physical warehouse. A warehouse record may represent it, but multi-warehouse behavior requires separate approval.

### Immutable Stock Movements

- Every quantity change creates an immutable stock-movement record.
- A posted stock movement must not be edited or deleted through normal application workflows.
- Corrections are made with explicit reversing and replacement movements linked to the original movement.
- Each movement records the product, warehouse, quantity direction and amount, movement type, source document, actor, timestamp, and an optional approved reason or note.
- Stock-affecting documents must have controlled states so that only approved or completed states post inventory.
- Repeated requests must not post the same source document more than once.
- Database transactions and locking or equivalent concurrency controls are required for posting.

### Weighted-Average Costing

- Weighted-average cost is recalculated only when an eligible completed stock purchase brings inventory into stock, unless another inbound workflow is explicitly approved to carry cost.
- The calculation uses the value and quantity of stock on hand immediately before receipt together with the eligible received purchase value and quantity.
- A product's manually entered `cost_price` must not overwrite or initialize average cost automatically.
- Sales, reservations, transfers between logical states, and ordinary returns do not arbitrarily recalculate average cost.
- Customer returns assessed as `OK` restore inventory using the approved historical cost basis of the original sale where available; any fallback policy requires approval.
- Cost calculations, rounding rules, landed-cost treatment, purchase returns, zero-stock behavior, and backdated transaction behavior must be specified and approved before implementation.
- Average cost, stock value, cost of goods sold, and gross profit are owner-only data.

### Owner-Only Financial Data

- Authorization must be enforced on the server, not merely by hiding interface elements.
- Owner-only data includes gross profit, average cost, inventory value, cost of goods sold, purchase-cost analytics, and sensitive accounting reports.
- Exports, APIs, notifications, widgets, searches, logs, and background jobs must obey the same restrictions.
- Access or attempted access to sensitive reports should be auditable where appropriate.

### User-to-Employee Authentication Structure

- Authentication uses a one-to-one relationship between `User` and `Employee`.
- Each employee has an individual User login; shared accounts are not permitted.
- A User authenticates and carries security identity, while the related Employee record carries employment and operational profile data.
- Account lifecycle and employee lifecycle are coordinated without erasing historical business records.
- Disabled or departed employees must lose login access while their historical actions, tasks, conversations, and assignments remain attributable.
- Roles and permissions are assigned to authenticated users and enforced through Laravel authorization policies or gates and Filament resource authorization.
- Passwords and secrets must never be exposed in logs, exports, screenshots, or documentation.

### Private Screenshot Storage and Audit Rules

- Employee working screenshots are private records and must not be stored in a public web directory or exposed through predictable public URLs.
- Access must use authorized, time-limited delivery or an authenticated application endpoint.
- Screenshot metadata records the employee, work session, capture time, source device or agent identifier, storage reference, integrity information where practical, and relevant audit events.
- Screenshot viewing, downloading, retention changes, and deletion must be permission-controlled and auditable.
- A retention period, legal basis, employee notice or consent policy, authorized reviewer list, exception or legal-hold procedure, and deletion schedule must be explicitly approved before capture is enabled.
- Expired screenshots are deleted by a controlled retention process while preserving non-sensitive audit evidence of the deletion when required.
- Screenshots must not capture or expose passwords, secrets, or unrelated sensitive information; pause and exclusion behavior must be defined before rollout.

### Separate Windows Desktop Capture Agent

- Actual desktop screenshot capture is performed by a separate Windows desktop agent, not by the Laravel web application.
- The agent authenticates securely, associates itself with the correct employee and approved device, follows server-issued capture policy, and uploads over an encrypted connection.
- The web application manages policy, authorization, metadata, storage references, review, retention, and audit records.
- The agent must support secure enrollment, credential rotation or revocation, offline retry without duplicate uploads, version tracking, health status, and an employee-visible capture state.
- Agent signing, distribution, automatic updates, tamper handling, capture intervals, multi-monitor rules, compression, bandwidth limits, and offline behavior require separate design and approval.
- The desktop agent is a separate implementation workstream and package; it must not be silently embedded into the ERP scope.

### Task Progress and Immutable Task Timelines

- Tasks have an assignee, creator, status, priority, due dates where applicable, and clear authorization boundaries.
- Current task state is stored separately from the immutable task timeline.
- Every material task event appends a timeline entry, including creation, assignment, reassignment, status change, progress update, comment, due-date change, attachment, completion, reopening, and approved correction.
- Timeline entries are not edited or deleted through ordinary workflows. Corrections append a new explanatory event.
- Progress must follow an approved, validated model, such as a percentage or milestone scheme, and completion rules must be consistent.
- All events record the actor and server timestamp; system-generated events are clearly identified.

### Internal Chat Architecture

- Chat is internal to authenticated employees and is separate from system notifications and task timelines.
- The architecture supports direct conversations and approved group or contextual conversations.
- Conversation membership and message access are enforced on the server.
- Messages are persistent, attributable, timestamped, and ordered using server-controlled identifiers or timestamps.
- Real-time delivery uses an approved broadcasting or event transport, while stored messages remain the authoritative record.
- Reconnect and synchronization behavior must recover missed messages without duplication.
- Attachments use private storage and the same authorization principles as messages.
- Editing, deletion, retention, moderation, read receipts, presence, search, and attachment limits require explicit policy decisions before implementation.
- Chat activity must not expose owner-only financial information to unauthorized participants.

### Approval Before Major Implementation

- Work proceeds one module at a time.
- Before a major module begins, its scope, workflows, roles, permissions, database impact, inventory impact, financial visibility, audit events, and acceptance criteria must be presented for approval.
- Database changes must be explained before implementation and must use new migrations; migrations already used must never be edited.
- No package is installed and `.env` is not modified without approval.
- Inventory operations require database transactions, validation, authorization, non-negative-stock safeguards, and relevant tests.
- Laravel Pint and relevant automated tests are run after code changes.
- No destructive database commands such as `migrate:fresh` or `db:wipe` are permitted.
- Nothing is deployed or pushed directly to the `main` branch.

## Module Roadmap

The sequence below is the approved dependency-aware roadmap. Detailed design and implementation for every phase remains subject to approval.

### Phase 0 — Foundations and Governance

#### Authentication

- Establish secure login, logout, password reset, session controls, account activation and deactivation, and authentication auditing.
- Enforce separate accounts and the one-to-one User-to-Employee relationship.

#### Employees

- Maintain employee identity, contact and employment profile, status, department or team where approved, manager relationship where approved, and linked User account.
- Preserve historical attribution when employment status changes.

#### Roles and Permissions

- Define least-privilege roles and granular permissions for modules, actions, records, sensitive fields, exports, and reports.
- Establish the owner role and server-side protection of financial data.

#### System Activity Logs

- Record important authentication, configuration, CRUD, approval, inventory, finance, task, monitoring, export, and access events.
- Protect logs from ordinary modification and provide authorized search and review.

#### Audit and Compliance Reports

- Define auditable events, retention policies, reviewer access, export controls, exception reports, and evidence requirements.
- Include stock reversals, permission changes, sensitive-data access, monitoring-record access, and retention actions.

### Phase 1 — Master Data

#### Warehouses

- Configure the single physical warehouse and its approved logical stock states or areas.
- Keep future multi-warehouse extension possible without implementing unapproved multi-warehouse behavior.

#### Suppliers

- Maintain supplier profiles, contacts, commercial terms, status, notes, and purchasing history subject to permissions.

#### Products

- Maintain title, unique SKU, brand, category and specifications where approved, status, reference prices, and inventory-related settings.
- Do not require serial-number tracking and do not derive average cost automatically from `cost_price`.

#### Brand Assignment

- Assign approved brands virtually to employees for operational responsibility or selling scope.
- Brand assignment does not transfer physical ownership or create a second stock balance.
- Assignment changes are effective-dated or otherwise historically auditable.

### Phase 2 — Procurement and Inventory Core

#### Purchases

- Manage purchase lifecycle states, supplier documents, line quantities and costs, receipts, completion, cancellation, and approved reversals.
- Only eligible completed receipts post stock and weighted-average cost, exactly once.

#### Inventory

- Implement the authoritative stock ledger, immutable movements, balances by product and approved stock state, reservations, availability, and stock history.
- Prevent negative stock and ensure all posting workflows are transactional and idempotent.

#### Stock Adjustments

- Support controlled increases, decreases, and state corrections with mandatory reasons, permissions, and approval where required.
- Post immutable movements; never rewrite historical balances.

### Phase 3 — Order and Fulfilment Operations

#### Sales Orders

- Manage customers or buyers as approved, order lines, reservations, fulfilment, cancellation, completion, payments or payment references, and stock posting.
- Record cost basis for reporting while restricting financial fields to authorized users.

#### Marketplace Orders

- Import or enter marketplace orders through idempotent workflows with external identifiers, source marketplace, status mapping, reconciliation, and exception handling.
- Marketplace integrations, credentials, rate limits, and channel-specific behavior require separate approval.

#### Returns

- Link returns to original orders where possible and record quantity, reason, inspection outcome, disposition, actor, and timestamps.
- `OK` returns post back to available stock; damaged returns post to the Damaged/Safe-T area.

#### Warranty

- Track warranty eligibility, claim or repair lifecycle, custody, supplier or service-center handoffs, outcomes, costs where approved, and customer communication history.
- Any stock change posts through the inventory ledger.

#### Safe-T Claims

- Track damaged-return evidence, marketplace claim references, deadlines, submission state, outcome, reimbursement, and final disposition.
- Damaged items remain in the approved Damaged/Safe-T stock state until an authorized disposition movement occurs.

### Phase 4 — Work Management and Collaboration

#### Task Management

- Create, assign, prioritize, schedule, comment on, complete, reopen, and filter employee tasks according to permissions.
- Support links to relevant ERP records without duplicating their authoritative data.

#### Task Timelines and Progress Tracking

- Append immutable task events and expose an authorized chronological view.
- Validate progress updates and keep current task state consistent with its timeline.

#### Real-Time Internal Employee Chat

- Provide persistent authorized employee conversations with real-time delivery and reliable reconnect synchronization.
- Keep chat messages, task events, and notifications as separate concerns with purposeful links where approved.

#### Notifications

- Deliver in-app notifications for actionable ERP, inventory, order, return, warranty, Safe-T, task, approval, monitoring, and system events.
- Apply recipient authorization, deduplication, read state, and preference rules; external channels require separate approval.

### Phase 5 — Work Sessions and Employee Monitoring

#### Employee Work Sessions

- Record approved start, pause, resume, and end events with employee, server time, device or agent identity, and exception handling.
- Work-session records provide context for activity and screenshots but do not replace system activity logs.

#### Employee Activity Monitoring

- Collect only explicitly approved monitoring signals from enrolled devices during valid work sessions.
- Define transparency, proportionality, access, retention, pause, exception, and audit policies before collection begins.

#### Employee Working Screenshot Records

- Store private screenshot metadata and protected image objects associated with an employee and work session.
- Apply approved capture policy, restricted review access, retention deletion, legal hold where required, and complete access auditing.
- Actual capture remains the responsibility of the separately approved Windows desktop agent.

### Phase 6 — Management Information and Finance

#### Dashboards

- Provide role-specific operational dashboards from authoritative data.
- Owner dashboards may include inventory value, average cost, gross profit, and other restricted financial measures; employee dashboards must omit them unless explicitly authorized.

#### Reports

- Provide operational reports for products, purchases, inventory movements, stock status, sales, marketplace orders, returns, warranty, Safe-T, assignments, tasks, work sessions, and notifications.
- Enforce authorization consistently in onscreen views and exports.

#### Accounting

- Define the approved accounting scope before implementation, including chart of accounts, journal rules, receivables, payables, payments, taxes, adjustments, closing, and reconciliation as applicable.
- Derive accounting entries from approved business events without changing the inventory ledger's role as the stock source of truth.
- Protect all accounting and profitability data as owner-only unless a narrower permission is explicitly approved.

#### Audit and Compliance Reports

- Deliver controlled reports covering authentication, permission changes, master-data changes, stock movements and reversals, purchasing and order state transitions, sensitive financial access, task history, chat administration, work sessions, screenshot access and retention, exports, and configuration changes.
- Reports must preserve traceability to source records and identify actor, action, time, and reason where applicable.

## Cross-Cutting Delivery Standards

- Follow Laravel, Filament, Eloquent, and project conventions.
- Add server-side validation to every form and mutation endpoint.
- Enforce authorization through policies, gates, query scoping, and resource controls.
- Use database transactions for inventory and other multi-record critical operations.
- Prefer append-only history for stock, task timelines, approvals, and audit evidence.
- Use idempotency and concurrency controls for imports, integrations, receipts, fulfilment, returns, and retries.
- Keep private files outside public storage and deliver them only after authorization.
- Protect credentials, personal data, monitoring data, and financial data in logs, exports, notifications, and error messages.
- Define acceptance criteria and add relevant automated tests for each approved module.
- Maintain accessible, role-appropriate Filament interfaces and clear operational error messages.
- Document approved state machines, reversal rules, retention schedules, and operational responsibilities.

## Implementation Approval Checklist

Before implementing any roadmap module, obtain explicit approval for:

1. Business scope and exclusions.
2. Roles, permissions, and owner-only fields.
3. Workflow states, transitions, approvals, cancellations, and reversals.
4. Database schema changes and migration plan.
5. Inventory quantities, stock states, movement types, and costing impact.
6. Financial and accounting impact.
7. Audit events and immutable history requirements.
8. Privacy, private-file storage, retention, and compliance requirements.
9. External services, desktop-agent behavior, packages, and configuration changes.
10. Validation rules, concurrency safeguards, acceptance criteria, and tests.

No major implementation begins solely because it appears in this roadmap. Approval is required before each major implementation step.
