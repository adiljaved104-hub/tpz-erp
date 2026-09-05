# TPZ ERP Module Specifications — Phases 1A–7

## Document Status

This is the permanent, phase-limited specification for the first approved planning sequence of the TPZ ERP. It must be read with `PROJECT_CONTEXT.md`, `SYSTEM_ARCHITECTURE.md`, `DATABASE_STANDARDS.md`, and `AGENTS.md`.

Only the modules in Phases 1A–7 are fully specified here. Later modules are named only as dependencies or roadmap items. Nothing in this document authorizes implementation, migrations, packages, `.env` or configuration changes, database execution, data changes, external connections, or deployment.

All tables, columns, classes, files, and transitions described below are proposed implementation scope. Decisions B01–B23 and the exact initial Owner identity/profile are approved as recorded in Section 18. They become actionable only after the remaining legacy Employee-password decision, the exact phase plan, and implementation are separately approved.

## 1. Approved Implementation Order

| Order | Phase | Approved planning scope |
|---:|---|---|
| 1 | **Phase 1A** | User–Employee relationship, role enum, Owner bootstrap, financial Gate, baseline Policies |
| 2 | **Phase 1B** | Suppliers, Warehouses, default Warehouse |
| 3 | **Phase 1C** | Product remediation |
| 4 | **Phase 2** | Product inventories, Stock Movements, Opening Stock, Reservations, reservation release, available/damaged transitions, Inventory Services |
| 4A | **Before Phase 3** | Focused Inventory Adjustment architecture and separate implementation approval |
| 5 | **Phase 3** | Remaining adjustment architecture and later inventory workflow work not delivered in Phase 2 |
| 6 | **Phase 4** | Purchases, approval, completion, weighted-average cost |
| 7 | **Phase 5** | Purchase reversals |
| 8 | **Phase 6** | Reservation fulfilment, partial release, expiry, and future Order integration |
| 9 | **Phase 7** | Safe-T foundation and later damaged-stock dispositions |

Each phase requires its own approval. A later phase must not begin merely because an earlier phase is approved. Activity logging and Filament navigation/UX are cross-cutting deliverables added incrementally in every phase.

## 2. Fixed Cross-Phase Rules

- User is the only authentication model. Employee is linked one-to-one through nullable unique `employees.user_id` in a staged migration.
- The Phase 1A migration must preserve current Filament login and retain Employee email/password. Later removal requires verification and separate approval.
- Employee role enum cases are Owner, Admin, Manager, and Staff, with stable stored values `owner`, `admin`, `manager`, and `staff` after safe mapping.
- One physical Warehouse is currently supported. Multi-warehouse operations are outside these phases.
- `products.name` remains unchanged; Filament may label it “Product” or “Product Title.”
- Product warranty is an unsigned integer count of months.
- Product `cost_price` is not average cost and must never initialize or overwrite it automatically.
- Unit and weighted-average cost use `DECIMAL(15,4)`. Selling price, line totals, and document totals use `DECIMAL(15,2)`.
- Internal costing uses four-decimal half-up rounding. AED presentation and totals use two-decimal half-up rounding.
- `product_inventories` is the only current operational quantity balance. Immutable `stock_movements` are the authoritative history.
- All stock mutations pass through Inventory Services within database transactions. Negative stock is prohibited.
- Average cost changes only through eligible completed purchase receipts or another separately approved cost-bearing inbound workflow.
- Posted movements and completed/reversed documents are never edited or deleted. Corrections append linked reversal/corrective records.
- Gross profit, average cost, stock value, cost of goods sold, and purchase-cost analytics are owner-only.
- Authenticated User IDs are recorded in actor columns.
- References use an approved concurrency-safe sequence Service/table and are never reused.
- Sequence is separate per document type and year for PO/OS/PR/RSV. Employee references preserve `TPZ-####` through a concurrency-safe non-reusing sequence. SKU keeps its current visible format until a safe compatible replacement is proposed from repository inspection.
- SQLite remains the local and standard-test database. MySQL/InnoDB is required later for true locking/concurrency tests; current configuration stays unchanged.

## 3. Phase 1A — Authentication Alignment

### Purpose

Align employment identity with Laravel authentication without breaking the current Filament login or prematurely deleting legacy Employee credentials.

### Scope

- Add the staged one-to-one User–Employee relationship.
- Backfill links only through an approved, verified matching plan.
- Preserve existing User authentication and Employee credential columns.
- Provide clear active/inactive account behavior and relationship accessors.

### Current repository state

- `App\Models\User` is the only `Authenticatable` model and has hashed password/datetime casts.
- `App\Models\Employee` extends Eloquent Model and separately stores/hashes its own password.
- `employees` contains no `user_id`; User and Employee models have no relationship methods.
- Employee email is unique independently of User email.
- Filament Admin Panel uses its normal User-based login.
- A read-only database inspection on 2026-08-06 found the confirmed initial Owner User: ID `1`, name `Adil Hussain`, email `adiljaved104@gmail.com`; it found no Employee records.

### Business rules

- User remains the sole login/security identity.
- Employee is the operational profile.
- `employees.user_id` is nullable during staging and unique when present.
- One User links to at most one Employee; one Employee links to at most one User.
- The initial migration does not remove or repurpose Employee email/password.
- No automatic email match may silently link ambiguous or conflicting records.
- Backfill matches normalized email only where exactly one User and one Employee match.
- Duplicate, missing, or conflicting emails remain unlinked and appear in an exception report.
- An authorized Owner/Admin may perform a controlled manual link.
- Once linked, inactive or terminated Employee status blocks Filament operational access without deleting User, Employee, or history.
- Rehire/reactivation is an explicit authorized action.

### Database tables and columns

- Existing `users`: no initial column change required.
- Existing `employees`: add nullable unsigned big-integer `user_id`, unique index, and foreign key to `users.id` with restrictive deletion.
- The exact migration is new; the existing Employee migration is never edited.
- Backfill/data linking is separate from schema creation and must be idempotent, previewable, and approved.

### Relationships

- `User::employee()` → `hasOne(Employee::class)`.
- `Employee::user()` → `belongsTo(User::class)`.
- Historical actor fields elsewhere reference User, not Employee.

### PHP enums

- No new authentication-status enum is required in Phase 1A; linked Employee active/inactive status governs operational access under B03.
- Role is specified in the next module.

### Services and actions

- `LinkUserToEmployee` Action validates uniqueness, identity match, actor authority, and audit event.
- `UnlinkUserFromEmployee` Action is restricted to approved recovery scenarios and cannot strand an active account without confirmation.
- `ResolveEmployeeAccess` service/helper centralizes whether the User/Employee combination may access operational resources.
- Backfill command/action, if approved, must dry-run, report conflicts, and use the linking Action.
- Approved Owner Employee profile: generated compatible `TPZ-####` reference; name `Adil Hussain`; email `adiljaved104@gmail.com`; designation `Owner / Managing Director`; role Owner; active status; `user_id = 1`; phone, team, and joining date null because the schema permits null and no personal data was provided.
- The legacy `employees.password` field is currently non-nullable and required by the Employee Form. No password may be invented, copied, or taken from User. Phase 1A should propose making this legacy column nullable for linked User-backed Employees while preserving the column/data; this requires approval before implementation.

### Policies and permissions

- `EmployeePolicy` controls view/create/update/deactivate/link/unlink.
- Owner/Admin may manually link an unambiguous approved pair. The initial Owner target is now confirmed, but enforcement waits for Phase 1A implementation approval.
- Users cannot change their own privileged role/link through mass assignment.

### Filament resources and pages

- Employee view shows linked account state without exposing password data.
- Employee create/edit must not accidentally create or overwrite User credentials in Phase 1A.
- Add explicit link/unlink actions only after policy checks and confirmation.
- Remove destructive Employee delete actions when history/deactivation rules apply.

### Validation rules

- `user_id`: nullable, exists in users, unique in employees, not already linked.
- Email matching for a backfill: normalized exact match only with one User and one Employee; duplicates, missing matches, and conflicts go to an exception report.
- Password fields are never returned or prefilled.
- Link/unlink requires an authorized actor and reason where approved.

### Activity-log events

- `employee.user_linked`
- `employee.user_unlinked`
- `employee.link_conflict_detected`
- `user.access_enabled`
- `user.access_disabled`

### Automated tests

- User remains able to authenticate through Filament after the schema change.
- Employee/User relationships work in both directions.
- Nullable staging works; duplicate User linkage is rejected by validation and database uniqueness.
- Unauthorized link/unlink is denied.
- Linked inactive/terminated Employees cannot access Filament; explicit authorized reactivation restores operational eligibility.
- Existing Employee credentials remain unchanged.

### Files to create

- New migration adding nullable unique `employees.user_id`.
- `app/Actions/Employees/LinkUserToEmployee.php`
- `app/Actions/Employees/UnlinkUserFromEmployee.php`
- `app/Services/Employees/ResolveEmployeeAccess.php`
- `app/Policies/EmployeePolicy.php`
- Focused Feature/Policy tests.

### Existing files to change

- `app/Models/User.php`
- `app/Models/Employee.php`
- Employee Filament Resource, schema, table, and relevant pages.
- `app/Providers/AppServiceProvider.php` only if policy/Gate registration is not conventionally discovered.

### Risks

- Incorrect automatic linking, duplicate emails, accidental login regression, privilege escalation, or premature credential removal.
- SQLite tests cannot prove MySQL concurrent uniqueness behavior.

### Dependencies

- Existing Laravel/Filament authentication.
- B01–B03 and the exact Owner profile are approved; legacy Employee password nullability remains the only Phase 1A data-field blocker.

### Out-of-scope items

- Removing Employee email/password.
- Replacing User authentication, multi-factor authentication, SSO, API tokens, or authentication packages.
- Broad Employee/Team redesign.

### Acceptance criteria

- Current Filament User login still works.
- Nullable unique User–Employee linking is enforced.
- No credentials are lost or exposed.
- Conflicts are reported rather than guessed.
- Policies and audit events cover link/access changes.
- Relevant tests pass on SQLite; MySQL uniqueness/concurrency verification is planned.

## 4. Phase 1A — Employee Roles and Authorization

### Purpose

Normalize roles and establish the minimum authorization foundation for Phases 1A–7, including Owner bootstrap and financial-data protection.

### Scope

- Introduce the Employee role backed enum and safely map existing values.
- Bootstrap exactly the approved initial Owner.
- Add a `viewFinancialData` Gate and baseline Policies.
- Enforce authorization in Services and Filament, not only UI visibility.

### Current repository state

- Employee migration uses a database-native enum containing `Owner`, `Admin`, `Manager`, and `Staff`.
- Employee forms/tables hard-code those title-case strings.
- No `app/Policies` files are present.
- Product cost is visible and labeled “Average Cost” in existing Filament Product UI.
- Employee and Product Resources expose delete/bulk-delete actions.

### Business rules

- PHP enum cases: `Owner`, `Admin`, `Manager`, `Staff`.
- Stored values after mapping: `owner`, `admin`, `manager`, `staff`.
- Owner alone sees average cost, unit purchase cost, stock value, COGS, gross profit, and related exports unless a narrower permission is later approved.
- Role changes are privileged, validated, and audited.
- The system must never be left without an active Owner.
- Role display labels do not control authorization.
- Owner has full Phase 1A–7 access, financial visibility, approvals, completion, reversals, adjustments, Opening Stock, and role management.
- Admin manages Employees, Suppliers, Warehouses, Products, Purchases, and operational Inventory. Admin has no financial visibility by default, cannot change the Owner role or remove the last Owner, may complete an approved Purchase, and may approve only when not its creator.
- Manager may view operational Inventory/master data and create/submit Purchases. Manager has no average cost, unit cost, stock value, gross profit, completion, reversal, or first-release approval authority.
- Staff sees only operational areas needed for assigned work and cannot manage master data, financial data, approvals, completion, reversals, Opening Stock, adjustments, or roles.
- Services and Policies enforce this matrix independently of Filament visibility.

### Database tables and columns

- New migration changes `employees.role` from database-native/title-case representation to a cross-engine string compatible with the PHP enum and maps existing values safely.
- The migration must validate all legacy values before mutation and provide a recovery/rollback plan.
- No role/permission package tables are approved.

### Relationships

- Role remains an Employee attribute associated with the linked authenticated User.
- Policies resolve the actor User and linked Employee role.

### PHP enums

- `App\Enums\EmployeeRole`: `Owner = 'owner'`, `Admin = 'admin'`, `Manager = 'manager'`, `Staff = 'staff'`.
- Enum may provide stable labels/colors but not workflow authorization logic.

### Services and actions

- `ChangeEmployeeRole` Action prevents unauthorized changes and removal of the last active Owner.
- `BootstrapOwner` Action/approved command links or assigns the specifically approved initial Owner once, idempotently.
- Financial Gate uses one centralized rule tied to the authenticated User's linked Employee role.

### Policies and permissions

- Baseline Policies: Employee, Supplier, Warehouse, Product, ProductInventory, StockMovement, OpeningStock, StockPurchase, PurchaseReversal, InventoryReservation, ActivityLog.
- Default deny; each later module fills its policy methods before its UI is exposed.
- `viewFinancialData` is Owner-only.
- Immutable/read-only records deny update/delete.

### Filament resources and pages

- Employee role options come from the enum.
- Role controls and Owner bootstrap are hidden and server-protected from unauthorized actors.
- Owner-only fields are excluded from unauthorized Product/Purchase/Inventory queries and schemas.
- Delete actions are removed where deactivation or immutable history is required.

### Validation rules

- Role must be an EmployeeRole value.
- Role mapping accepts only known legacy values after preflight validation.
- Owner bootstrap target is User ID `1` and the exact Employee profile above; it must be idempotent and reject duplicate User/Employee records.
- Last active Owner cannot be demoted, unlinked, or disabled without an approved successor rule.

### Activity-log events

- `employee.role_changed`
- `owner.bootstrapped`
- `owner.change_rejected`
- `financial_data.viewed` for approved sensitive access points where required
- `authorization.denied` for selected high-risk operations

### Automated tests

- Legacy role mapping preserves all four roles.
- Each Policy/Gate has allow and deny cases.
- Non-owner cannot query/render/export financial fields.
- Last-Owner protection and bootstrap idempotency work.
- Hard-coded UI labels do not bypass Policies.

### Files to create

- `app/Enums/EmployeeRole.php`
- New safe role-normalization migration.
- `app/Actions/Employees/ChangeEmployeeRole.php`
- Owner bootstrap Action/command for confirmed User ID `1` and the approved Employee profile, only after legacy password handling and the Phase 1A implementation plan are approved.
- Baseline files under `app/Policies/`.
- Gate and Policy tests.

### Existing files to change

- `app/Models/Employee.php`
- Employee Filament Form/Infolist/Table/Resource/pages.
- Product Filament schemas/tables to protect current financial fields.
- `app/Providers/AppServiceProvider.php` if Gate registration is required.

### Risks

- Locking out the Owner, mis-mapping roles, client-side-only protection, or leaking cost through tables, exports, caches, or logs.

### Dependencies

- Authentication alignment and identified Owner.
- B04 and the Owner identity/profile are approved; Phase 1A remains blocked only by legacy Employee password handling and implementation approval.

### Out-of-scope items

- Permission packages, arbitrary custom roles, per-user permission overrides, delegation, or later-module permissions.

### Acceptance criteria

- All existing roles map without loss.
- Exactly the approved Owner is bootstrapped.
- Financial Gate is enforced server-side.
- Baseline Policies default-deny unapproved behavior.
- Non-owner financial-leakage tests pass.

## 5. Phase 1B — Suppliers

### Purpose

Provide controlled Supplier master data for Purchases.

### Scope

- Create, view, update, search, filter, and deactivate Suppliers.
- Preserve Supplier history when referenced.
- Store only fields required for the approved initial purchasing workflow.

### Current repository state

- No Supplier model, migration, Policy, Resource, Service, or tests exist.

### Business rules

- Supplier name is required and duplicate handling is explicit.
- Optional fields are contact person, phone, email, address, VAT number, and notes.
- At least one of phone/email is recommended but not required, and VAT details are optional.
- Referenced Suppliers are deactivated, never deleted.
- Commercial notes and purchase history follow authorization.
- No supplier balance is stored in Phase 1B.

### Database tables and columns

- `suppliers`: `id`, `name`, nullable `contact_person`, nullable `email`, nullable `phone`, nullable `address`, nullable `vat_number`, nullable `notes`, `is_active` default true, nullable `created_by`, `created_at`, `updated_at`.
- Index `name`, `is_active`; foreign key `created_by` to Users with restrictive/set-null behavior chosen during migration review.
- Supplier reference, payment terms, balances, and mandatory VAT data are not part of Phase 1B.

### Relationships

- Supplier will have many Stock Purchases in Phase 4.
- `createdBy()` belongs to User when `created_by` is used.

### PHP enums

- No workflow enum; `is_active` is a genuine boolean.

### Services and actions

- `CreateSupplier`, `UpdateSupplier`, `DeactivateSupplier` Actions.
- Delete is allowed only for an unreferenced draft-like record if explicitly approved; default workflow is deactivation.

### Policies and permissions

- Owner/Admin manage. Manager may view. Staff has no Supplier-management permission.
- Cost/purchase analytics are not exposed through Supplier to non-owner.

### Filament resources and pages

- Supplier Resource with list/create/view/edit.
- Active filter, searchable name/contact/email/phone, clear inactive badge.
- No bulk delete; deactivation is a named confirmed Action.

### Validation rules

- Name required, trimmed, max 255.
- Email valid/nullable; phone/address length constrained.
- Possible duplicates are never merged automatically.
- Inactive Suppliers cannot be selected for new Purchases.
- Actor cannot set protected audit fields.

### Activity-log events

- `supplier.created`
- `supplier.updated`
- `supplier.activated`
- `supplier.deactivated`

### Automated tests

- CRUD/deactivation Policy tests.
- Validation and duplicate behavior.
- Referenced Supplier cannot be deleted.
- Non-owner cannot obtain purchase-cost analytics via Supplier.

### Files to create

- Supplier migration, Model, Policy, DTO/Actions, Filament Resource/pages/schemas/table, factory, and tests.

### Existing files to change

- Navigation configuration/grouping only if needed.
- No existing Supplier files.

### Risks

- Duplicate Suppliers, deletion of purchasing history, unbounded personal/commercial data, or accidental financial leakage.

### Dependencies

- Phase 1A authorization and activity logging.
- B05 is approved; no Supplier business decision remains blocking.

### Out-of-scope items

- Accounts payable, supplier payments, supplier portals, external sync, contracts, and supplier returns.

### Acceptance criteria

- Authorized users can manage valid Supplier records.
- Deactivation preserves history.
- Validation, Policy, audit, and search/filter tests pass.
- No unapproved financial data is shown.

## 6. Phase 1B — Warehouses and Default Warehouse

### Purpose

Represent the single physical Warehouse and provide the required default for all early inventory workflows.

### Scope

- Create the Warehouse master table and one approved default Warehouse.
- View/update/deactivate under restrictions.
- Resolve the default Warehouse through a centralized Service.

### Current repository state

- No Warehouse table, Model, Policy, Resource, or default-Warehouse logic exists.

### Business rules

- Initial system supports exactly one operational physical Warehouse.
- Required record: name `Main Warehouse`, code `MAIN`, active yes, default yes.
- One active Warehouse must be the default before inventory posting begins.
- Referenced Warehouses are not deleted.
- Multi-warehouse transfer/routing is out of scope.
- Default creation must be approved, idempotent, and not hidden in an unsafe production Seeder.

### Database tables and columns

- `warehouses`: `id`, unique `code`, `name`, nullable `address`, `is_default`, `is_active`, nullable `created_by`, timestamps.
- Index `is_active`; database/service safeguards for one default must work on SQLite and MySQL.
- Required default record is created through an idempotent data migration/deployment step, not only a development Seeder.

### Relationships

- Warehouse will have many Product Inventories, Stock Movements, Opening Stocks, Purchases, Reversals, and Reservations.
- `createdBy()` belongs to User.

### PHP enums

- None; `is_default` and `is_active` are true binary values.

### Services and actions

- `CreateWarehouse`, `UpdateWarehouse`, `SetDefaultWarehouse`, `DeactivateWarehouse`.
- `ResolveDefaultWarehouse` fails clearly if none or multiple are configured.

### Policies and permissions

- Owner/Admin manage. Manager may view. Staff sees Warehouse only where an assigned operational screen requires it.
- Default/deactivation actions require elevated permission.

### Filament resources and pages

- Warehouse Resource with list/create/view/edit.
- Default/active badges and confirmed “Set as default” Action.
- No delete/bulk-delete once referenced.

### Validation rules

- Code required, normalized, unique, and must accept the approved `MAIN` value.
- Name required; address bounded.
- Inactive Warehouse cannot become default.
- Default Warehouse cannot be deactivated while inventory/workflows depend on it.

### Activity-log events

- `warehouse.created`
- `warehouse.updated`
- `warehouse.default_changed`
- `warehouse.activated`
- `warehouse.deactivation_rejected`
- `warehouse.deactivated`

### Automated tests

- Unique code, one-default invariant, default resolution, deactivation restrictions, Policies, and idempotent bootstrap.
- SQLite functional tests; MySQL uniqueness/concurrency test planned.

### Files to create

- Warehouse migration, Model, Policy, DTO/Actions/Service, Filament Resource/pages/schemas/table, approved default data step, factory, and tests.

### Existing files to change

- Navigation grouping/provider only if required.

### Risks

- Multiple defaults, no default, accidental multi-warehouse assumptions, unsafe bootstrap data, or deletion of referenced Warehouse.

### Dependencies

- Phase 1A Policies/activity logging.
- B06 is approved; implementation must still present the idempotent data-migration/recovery plan.

### Out-of-scope items

- Multiple physical Warehouses, bins, transfers, routes, replenishment, and per-Warehouse permissions.

### Acceptance criteria

- Exactly one approved active default Warehouse exists before Phase 2 posting.
- Resolution is deterministic and audited.
- No referenced Warehouse can be deleted.
- Validation/Policy/default tests pass.

## 7. Phase 1C — Product Remediation

### Purpose

Bring the existing Product schema, Model, Filament UI, and SKU generation into alignment without losing existing data or confusing reference cost with average cost.

### Scope

- Preserve `products.name`.
- Safely remediate precision, casts, warranty months, status/condition values, fillable fields, owner-only financial display, SKU generation, and destructive UI actions.

### Current repository state

- Product migration/model/Filament Resource are untracked in the inspected working tree but present.
- `cost_price` and `selling_price` are `DECIMAL(10,2)`.
- Model casts only `cost_price` as decimal:2; fillable omits `selling_price` and `warranty`.
- SKU generation uses `max(id) + 1`.
- Warranty is signed integer default 12.
- Status and condition use title-case strings.
- Product Form labels `cost_price` “Average Cost”; table and infolist expose costs without inspected Policy/Gate protection.
- Form omits selling price and warranty; edit/list expose delete/bulk-delete.
- Read-only inspection found condition `New` on 1 Product and status `Active` on 1 Product; no other stored values were present.
- Existing Filament options include `Open Box`, while the approved enum case is `OpenBox`; no inspected row currently stores `Open Box`.

### Business rules

- Database field remains `name`.
- SKU is unique, immutable after approved creation, concurrency-safe, and never reused.
- Warranty is unsigned months.
- `cost_price` is an optional/reference cost only; it is not average cost.
- Unit/reference cost uses `DECIMAL(15,4)`; selling price uses `DECIMAL(15,2)`.
- Product average cost will live with inventory valuation, not be copied from `cost_price`.
- Products with history are deactivated, not deleted.
- Cost fields are owner-only.
- Keep all current Product fields listed in B08. Selling price may be visible to authorized operational users, while cost price, average cost, and inventory value remain Owner-only.
- Product creation never creates stock.

### Database tables and columns

- New Product-remediation migration only; never edit the existing migration.
- Preserve `products.name` and `sku` unique constraint.
- Change `cost_price` to `DECIMAL(15,4)` and `selling_price` to `DECIMAL(15,2)` with preflight/data-preservation checks.
- Make warranty unsigned integer months while preserving values and rejecting invalid data.
- Normalize `status` and `condition` to approved stable string enum values.
- No `average_cost` or quantity column is added to Products.

### Relationships

- Product later has many Product Inventories and Stock Movements.
- No serial-number relation.

### PHP enums

- `ProductStatus`: `Active = 'active'`, `Inactive = 'inactive'`, `Discontinued = 'discontinued'`.
- `ProductCondition`: `New = 'new'`, `Renewed = 'renewed'`, `Used = 'used'`, `OpenBox = 'open_box'`, `Refurbished = 'refurbished'`.
- Distinct stored values must be reported again immediately before implementation. Known `New` and `Active` map unambiguously; unknown legacy values require approval rather than silent conversion.

### Services and actions

- `GenerateProductSku` uses the approved sequence mechanism.
- `CreateProduct`, `UpdateProduct`, `DeactivateProduct` Actions centralize validation and protected-field handling.
- No average-cost calculation belongs in Product actions.

### Policies and permissions

- Product Policy controls view/create/update/deactivate and financial-field visibility.
- Owner-only Gate protects cost input/display/export.
- Selling price may be shown to authorized operational users under B04; cost price, average cost, and inventory value remain Owner-only.

### Filament resources and pages

- Label `name` as “Product” or “Product Title.”
- Relabel `cost_price` as “Cost Price,” never “Average Cost.”
- Show warranty with month validation/formatting.
- Enum-backed status/condition selects and badges.
- Remove destructive delete/bulk-delete for Products with history; use deactivation.
- Exclude cost from unauthorized queries/tables/infolists/forms.

### Validation rules

- Name required/max 255; SKU generated/unique and not user-overwritten unless explicitly approved.
- Cost decimal, non-negative, max precision/scale; selling price decimal/non-negative.
- Warranty integer, unsigned/non-negative, approved maximum.
- Status and condition valid enums.
- All current B08 Product fields remain supported and validated; separate Brand/Category master-data redesign is deferred.

### Activity-log events

- `product.created`
- `product.updated`
- `product.activated`
- `product.deactivated`
- `product.sku_assigned`
- `product.financial_fields_changed`

### Automated tests

- Legacy value/precision preservation and enum mapping.
- SKU concurrency/idempotency and uniqueness, including MySQL later.
- Warranty validation and casts.
- Cost is never treated as average cost.
- Owner-only cost visibility at query/UI/export boundaries.
- Deactivation and delete restrictions.

### Files to create

- Product-remediation migration.
- ProductStatus/ProductCondition enums.
- Product DTO/Actions and SKU sequence integration.
- Product Policy and focused tests.

### Existing files to change

- `app/Models/Product.php`
- All existing Product Filament Resource/pages/schema/table files as required.
- Sequence files created in the approved shared reference phase.

### Risks

- Decimal truncation, invalid legacy statuses, SKU collisions, cost leakage, accidental average-cost initialization, or loss of existing data.

### Dependencies

- Phase 1A authorization, shared sequence design, Activity Log.
- B07–B09 are approved; implementation must re-report distinct enum values and propose a safe SKU-compatible sequence before changing data.

### Out-of-scope items

- Product inventory balances, average-cost posting, serial tracking, Brand Assignment, and separate Brand/Category administration.

### Acceptance criteria

- Existing Product data is preserved and mapped.
- Name stays unchanged.
- Precision, casts, warranty months, enums, and SKU strategy match approved standards.
- No UI calls `cost_price` average cost.
- Owner-only protection and Product tests pass.

## 8. Phase 2 — Inventory Ledger Foundation

### Purpose

Create the single source of truth for current balances, immutable stock history, and weighted-average valuation used by later phases.

### Scope

- Product Inventories, immutable Stock Movements, focused single-entry Opening Stock, full-release Reservations, available/damaged transitions, transactional Services, read-only history UI, integrity and concurrency tests.
- Phase 2 exposes only Opening Stock, reserve, full release, mark damaged, and restore damaged. Purchases, sales, fulfilment, partial release, expiry, transfers, write-offs, adjustments, and reversal remain outside this implementation.
- Available includes Reserved; Sellable is Available minus Reserved; Total on Hand is Available plus Damaged.
- Valuation quantity is Available plus Damaged. Average cost is nullable `DECIMAL(15,4)` and exact calculations use BCMath without PHP floats.

### Current repository state

- No Warehouse/Product Inventory/Stock Movement tables or models exist.
- No Inventory Services, enums, Policies, locks, idempotency, or integrity tests exist.
- Product has no inventory relationships and no approved average-cost field.

### Business rules

- One unique balance row per Warehouse/Product.
- `available_quantity >= 0`, `reserved_quantity >= 0`, `damaged_quantity >= 0`, and reserved does not exceed available.
- Sellable equals available minus reserved; physical equals available plus damaged.
- Stock Movements are append-only; Product Inventory is a transactionally maintained projection.
- All mutations use Inventory Services, transactions, row locks on MySQL, consistent lock order, unique idempotency, and actor/source references.
- Average cost is stored with the Product Inventory valuation projection and changes only through approved cost-bearing inbound events.
- No Product quantity or average-cost duplicates.
- Weighted-average costing includes available quantity, including reserved units, but excludes damaged quantity from the Purchase formula.
- Zero existing available quantity sets average cost to incoming unit cost. Negative cost is forbidden. Zero-cost purchase items are blocked unless Owner approves one with a mandatory reason.
- Sales and reservations do not recalculate average cost.

### Database tables and columns

- `product_inventories`: `id`, `warehouse_id`, `product_id`, unsigned integer `available_quantity`, `reserved_quantity`, `damaged_quantity`, `average_cost DECIMAL(15,4)` default 0, timestamps; unique Warehouse/Product; check constraints where portable/approved.
- `stock_movements`: `id`, UUID `movement_group`, `warehouse_id`, `product_id`, `movement_type`, nullable `from_section`, nullable `to_section`, signed or directionally explicit quantity, nullable `unit_cost DECIMAL(15,4)`, nullable internal `total_cost DECIMAL(15,4)`, morph-map `source_type/source_id`, nullable unique `idempotency_key`, nullable self-reference `reversal_of_id`, nullable `reason`, `actor_id`, `occurred_at`, `created_at`; no `updated_at`.
- Index Warehouse/Product/occurred time, source type/id, group key, actor/time; restrictive foreign keys.
- Purchase receipt movements also require a unique Purchase Item safeguard independent of the optional retry idempotency key.

### Relationships

- Product Inventory belongs to Warehouse and Product.
- Stock Movement belongs to Warehouse, Product, User actor, optional reversed movement; morphs to approved source.
- Product/Warehouse have many balances/movements.

### PHP enums

- `InventorySection`: `Available = 'available'`, `Reserved = 'reserved'`, `Damaged = 'damaged'`. Reserved remains an allocation within available stock for costing and physical-count formulas.
- `StockMovementType` catalog: `opening_stock`, `purchase`, `purchase_reversal`, `reservation`, `reservation_release`, `sale`, `sale_cancellation`, `return_ok`, `return_damaged`, `transfer`, `adjustment`, `write_off`, `warranty`, and `safe_t`.
- Only `opening_stock`, `purchase`, `purchase_reversal`, `reservation`, `reservation_release`, and Phase 7 transfer/Safe-T-compatible cases are enabled through Phase 7. Future cases do not authorize unused workflows or tables.

### Services and actions

- `InventoryService` is the only public mutation boundary.
- Focused Actions/operations: initialize locked balance, receive costed stock, post outbound, transfer available/damaged, reserve, release, reverse linked movement group.
- `WeightedAverageCostCalculator` uses decimal-safe half-up rules.
- `InventoryReconciliationService` reports balance/ledger discrepancies without repair.

### Policies and permissions

- ProductInventory Policy: view quantities by approved operational role; average cost/value Owner-only; no generic create/update/delete.
- StockMovement Policy: read-only; financial columns Owner-only; posting only through authorized Services.

### Filament resources and pages

- Read-only Product Inventory Resource with authorized balance columns and Owner-only average cost/value.
- Read-only Stock Movement Resource with filters by Product, Warehouse, type, source, actor, and date.
- No create/edit/delete/bulk-mutation controls.
- Purpose-built workflow pages come only in their approved phases.

### Validation rules

- Product/Warehouse exist and are active; quantity positive integer.
- Polymorphic source is required. Idempotency key is nullable but unique and required for retried/external or otherwise retry-prone stock-changing operations.
- Movement type/sections form an allowed combination.
- No result violates non-negative/reservation invariants.
- Cost required only for approved cost-bearing movements and conforms to precision.

### Activity-log events

- `inventory.movement_posted`
- `inventory.movement_reversed`
- `inventory.posting_rejected`
- `inventory.reconciliation_completed`
- `inventory.discrepancy_detected`
- Sensitive viewing events where required.

### Automated tests

- Every invariant and movement type.
- Duplicate idempotency, rollback on failure, lock order, and no negative stock.
- Ledger-to-balance and valuation reconciliation.
- Weighted-average decimal/rounding cases.
- Policy/read-only/owner-field leakage tests.
- MySQL concurrent receipt/reservation tests later; SQLite functional coverage now.

### Files to create

- Product Inventory and Stock Movement migrations/models/enums.
- Inventory Services, calculator, exceptions, DTOs/Actions, Policies.
- Read-only Filament Resources/pages/schemas/tables.
- Factories and Unit/Feature/Policy/integrity/concurrency tests.

### Existing files to change

- Product and Warehouse models for relationships.
- Navigation grouping.
- Service provider only if required for policy/morph map/Gate registration.

### Risks

- Duplicate postings, overselling, deadlocks, ledger/projection drift, cost precision errors, polymorphic rename risk, or owner-only data leakage.

### Dependencies

- Phases 1A–1C and default Warehouse.
- B10–B12 are approved: stable morph map, UUID movement group, nullable unique idempotency key, Purchase Item uniqueness, approved movement catalog, and decimal-safe costing rules apply.

### Out-of-scope items

- Stock Adjustments UI, Sales fulfilment, Returns, Warranty, Safe-T claims, multi-warehouse transfers, serial numbers, and accounting journals.

### Acceptance criteria

- Every mutation route is Service-only, transactional, idempotent, and auditable.
- Balance invariants and immutable history are enforced.
- Average cost uses approved precision/rounding and is Owner-only.
- Read-only Filament resources cannot mutate ledger/balances.
- Integrity tests pass; MySQL concurrency plan is documented.

## 8A. Required Before Phase 3 — Inventory Adjustment Architecture

### Purpose

Provide the separately authorized correction mechanism required when completed Opening Stock cannot be edited and a Purchase reversal is blocked.

### Scope

- Focused Owner-authorized positive or negative quantity correction for one Inventory section.
- Record immutable before/after evidence, Stock Movements, idempotency, and Activity Log.
- Architecture/specification is required before Phase 3; implementation remains separately approved and is not part of Phase 1A.

### Current repository state

- No Adjustment document, Model, Service, Policy, Filament page, reason catalog, or tests exist.
- Product quantities do not yet exist; Phase 2 Inventory foundation must be completed first.

### Business rules

- Owner authorization is mandatory.
- Every Adjustment requires a mandatory reason and explicit Inventory section.
- Direction is increase or decrease; quantity is stored as a positive integer rather than accepting an ambiguous signed form input.
- The Service records authoritative before/after balances and appends immutable Stock Movements in one transaction.
- Negative available, reserved, damaged, or sellable stock is prohibited.
- Completed Adjustment records and movements are immutable; correction requires another linked Adjustment.
- Adjustment is not a substitute for a valid Purchase reversal and cannot silently rewrite average cost.
- Financial/valuation fields and effects are Owner-only. Exact valuation treatment must be approved in the Adjustment implementation plan.

### Database tables and columns

- Proposed `inventory_adjustments`: `id`, `warehouse_id`, `status`, mandatory `reason`, nullable `notes`, unique/nullable `idempotency_key` as appropriate, `created_by`, nullable `completed_by/completed_at`, nullable `cancelled_by/cancelled_at`, timestamps.
- Proposed `inventory_adjustment_items`: `id`, `inventory_adjustment_id`, `product_id`, `inventory_section`, `direction`, unsigned integer `quantity`, before/after quantity snapshots, nullable protected `unit_cost DECIMAL(15,4)` if the approved valuation rule needs it, timestamps while draft.
- Completion links the Adjustment to its UUID movement group/source. Tables and exact reference strategy require separate migration review.

### Relationships

- Adjustment belongs to Warehouse and actor Users, has Items, and is the polymorphic source of its Stock Movements.
- Item belongs to Adjustment and Product.

### PHP enums

- `InventoryAdjustmentStatus`: proposed `Draft`, `Completed`, `Cancelled`.
- `InventoryAdjustmentDirection`: `Increase`, `Decrease`.
- `InventorySection`: approved `available`, `reserved`, `damaged`; direct reserved adjustment additionally requires reservation reconciliation safeguards.
- `StockMovementType::Adjustment = 'adjustment'`.

### Services and actions

- `CreateInventoryAdjustment`, `UpdateInventoryAdjustment`, `CompleteInventoryAdjustment`, and `CancelInventoryAdjustment`.
- Completion delegates to Inventory Service, locks balances in consistent order, validates before/after quantities, posts immutable movements, logs activity, and commits atomically/idempotently.

### Policies and permissions

- Owner-only create, edit draft, complete, cancel, view financial fields, and view full evidence.
- No generic Model/Filament update of Product Inventory quantities.
- Completed records deny update/delete.

### Filament resources and pages

- Purpose-built Owner-only Adjustment Resource/page after separate approval.
- Draft form requires Product, Warehouse, section, direction, positive quantity, and reason.
- Completion confirmation shows before/after balances and any protected valuation effect.
- Completed view is read-only and links to Stock Movements/Activity Log.

### Validation rules

- Active Product/Warehouse, approved section/direction, positive integer quantity, mandatory reason, valid draft state, sufficient balance for decrease, no negative result, unique idempotency, and Owner actor.
- Reserved-section changes cannot create divergence from active Reservations.
- Any cost/valuation input uses decimal-safe `DECIMAL(15,4)`, never negative, and follows the separately approved valuation rule.

### Activity-log events

- `inventory_adjustment.created`
- `inventory_adjustment.updated`
- `inventory_adjustment.completed`
- `inventory_adjustment.cancelled`
- `inventory_adjustment.rejected`
- Events include actor, reason, section, direction, quantity, before/after quantities, source/correlation, and protected financial references without generic financial JSON leakage.

### Automated tests

- Owner-only authorization, required reason, positive/negative direction, each approved section, before/after snapshots, negative-stock rejection, reserved reconciliation, immutable movement/source/group, idempotent retry, rollback, Activity Log, read-only completion, and owner-only valuation fields.
- MySQL concurrency tests cover simultaneous adjustment and other stock mutations.

### Files to create

- Adjustment migrations/models/enums/DTOs/Actions/Policy/Filament Resource or purpose-built page, factory, and Unit/Feature/Policy/integrity/concurrency tests after separate approval.

### Existing files to change

- Inventory Service, Product Inventory/Stock Movement models and enums, morph map, Activity Log catalog, Owner-only navigation/Policy registration as approved.

### Risks

- Using Adjustment to bypass normal workflows, ledger/projection drift, negative stock, reserved-balance divergence, average-cost corruption, repeated posting, or financial leakage.

### Dependencies

- Completed Phase 1A authorization/Activity Log and Phase 2 Inventory ledger.
- Separate approval of exact valuation treatment, tables, files, migrations, UI, and tests before Phase 3.

### Out-of-scope items

- Phase 1A implementation, routine cycle counting, batch costing, automatic reconciliation repair, Purchase editing, Opening Stock reversal documents, and future Return/Warranty/Safe-T dispositions.

### Acceptance criteria

- Owner can post an explained authorized increase/decrease without direct balance editing.
- Before/after balances, immutable movement, UUID group/source, idempotency, Activity Log, and negative-stock prevention reconcile atomically.
- Financial effects are Owner-only and follow an explicitly approved valuation rule.
- No Adjustment implementation begins until its separate pre-Phase-3 approval.

## 9. Phase 3 — Opening Stock

> **Approved ordering update:** The focused single-entry Opening Stock workflow is implemented in Phase 2. The older draft/header-and-lines design below is superseded and retained only as historical planning context. Opening Stock reversal remains prohibited; corrections require a separately approved Inventory Adjustment workflow.

### Purpose

Load verified starting stock and value through an approved, auditable posting workflow.

### Scope

- Draft and complete Owner-only Opening Stock documents; later corrections use a separately authorized adjustment workflow.
- Post initial available quantities and values exactly once through Inventory Services.

### Current repository state

- No Opening Stock tables, references, workflow, UI, Services, or tests exist.

### Business rules

- Every document has a concurrency-safe reference never reused.
- Duplicate Product lines are prohibited.
- A Product/Warehouse combination with any prior Stock Movement cannot receive another Opening Stock balance.
- Completed lines append stock/valuation movements and update Product Inventory atomically.
- Opening unit cost uses `DECIMAL(15,4)` and cannot come automatically from Product `cost_price`.
- Completed documents are immutable; a second Opening Stock or direct reversal is not used for correction.

### Database tables and columns

- `opening_stocks`: `id`, unique `reference` using `OS-{YEAR}-{SEQUENCE}`, `warehouse_id`, `status`, `effective_date`, nullable `notes`, `created_by`, nullable `completed_by/completed_at`, nullable `cancelled_by/cancelled_at`, timestamps.
- `opening_stock_items`: `id`, `opening_stock_id`, `product_id`, unsigned integer `quantity`, `unit_cost DECIMAL(15,4)`, timestamps while draft; unique document/Product.
- Restrictive foreign keys and status/date indexes.

### Relationships

- Opening Stock belongs to Warehouse and actor Users; has many Items and related Stock Movements.
- Item belongs to Product and Opening Stock.

### PHP enums

- `OpeningStockStatus`: `Draft = 'draft'`, `Completed = 'completed'`, `Cancelled = 'cancelled'`.

### Services and actions

- `CreateOpeningStock`, `UpdateOpeningStock`, `CompleteOpeningStock`, and `CancelOpeningStock`.
- Completion locks document/balances, posts one group idempotently, and dispatches after commit.

### Policies and permissions

- Owner alone creates, edits, and completes Opening Stock in the first release.
- Completed records are read-only; later correction is an Owner-authorized adjustment with explanation/audit.

### Filament resources and pages

- Opening Stock Resource with draft form and line repeater/Relation Manager.
- Named complete/cancel Actions with confirmation; no second-opening or direct-reversal Action.
- Completed view shows movement links; non-owner cost is hidden.

### Validation rules

- Active default Warehouse, unique Products, positive integer quantities, non-negative four-decimal costs, required effective date/evidence.
- State transition, Owner authority, idempotency, unique document Products, and no prior movement for the Product/Warehouse combination.

### Activity-log events

- `opening_stock.created`, `updated`, `completed`, `cancelled`, `posting_rejected`.

### Automated tests

- Owner-only Policy, duplicate Products, prior-movement rejection, completion idempotency, weighted average from zero, rollback, financial visibility, immutability, and ledger reconciliation.

### Files to create

- Opening Stock/Item migrations, models, enum, DTOs/Actions/Service, Policies, Filament Resource/pages/schemas/Relation Manager/table, factory/tests.

### Existing files to change

- Warehouse/Product relationships, Inventory movement enum, navigation.

### Risks

- Double loading, incorrect cost, unauthorized posting, historical edits, or an unapproved correction path.

### Dependencies

- Phase 2 inventory foundation and sequence Service.
- B13 is approved; no additional Opening Stock business decision is blocking the documented first release.

### Out-of-scope items

- Recurring adjustments, cycle counts, imports unless explicitly included, and opening accounting journal.

### Acceptance criteria

- Owner-approved starting stock posts exactly once and reconciles; any prior Product/Warehouse movement blocks Opening Stock.
- Average cost is correct and not sourced automatically from Product.
- Completed history is immutable; later correction is an authorized adjustment, not another Opening Stock.
- Policy and integrity tests pass.

## 10. Phase 4 — Purchases

### Purpose

Capture Supplier purchase documents and validated lines before posting inventory.

### Scope

- Purchase draft creation/editing, references, Supplier/Warehouse selection, lines, calculated totals, submission/cancellation, and read-only completed history.
- Approval/completion is specified separately in Section 11.

### Current repository state

- No Purchase tables, Models, Services, Policies, Resource, references, or tests exist.

### Business rules

- Reference is concurrency-safe and never reused.
- Draft lines use Product, positive integer quantity, and unit cost `DECIMAL(15,4)`.
- Line/document totals are server-calculated with approved rounding; browser totals are non-authoritative.
- Draft may change only under Policy. Submitted/approved/completed restrictions follow state machine.
- Unit cost and Purchase totals are visible within authorized Purchase workflows to Manager, Admin, and Owner as required by their Purchase permissions. This scoped access does not grant Inventory average cost/value, gross profit, or previous/new average-cost audit visibility.
- First release supports partial receiving and multiple immutable Goods Received Notes until ordered quantity is satisfied or the Purchase is manually closed.
- Staff cannot create Purchases. Manager/Admin/Owner may create and submit.

### Database tables and columns

- `purchases`: approved Purchase header, Supplier/Warehouse, original and normalized Supplier invoice, AED totals, lifecycle actors/reasons, and timestamps.
- `purchase_items`: Product, ordered/received/rejected quantities, unit cost, allocated discount, effective inventory unit cost, VAT, totals, notes, and draft timestamps.
- `purchase_receipts`: immutable `GRN-{YEAR}-{SEQUENCE}` document, Purchase/Warehouse, receiving evidence, actor, idempotency key, movement group, notes, and `created_at` only.
- `purchase_receipt_items`: immutable Purchase Item/Product link, accepted/damaged/rejected quantities, effective inventory unit cost, posting key, notes, and `created_at` only.
- Unique Purchase/Product if duplicate lines are prohibited; relevant status/date/Supplier indexes.

### Relationships

- Purchase belongs to Supplier, Warehouse, and actor Users; has many Items, Movements, and later Reversals.
- Item belongs to Purchase and Product.

### PHP enums

- `PurchaseStatus`: `draft`, `approved`, `partially_received`, `fully_received`, `closed`, `cancelled`. Reversal is not a first-release status.

### Services and actions

- `CreateStockPurchase`, `UpdateStockPurchase`, `SubmitStockPurchase`, `CancelStockPurchase`.
- `PurchaseTotalsCalculator` uses decimal-safe server calculations.
- Sequence Service assigns reference at approved lifecycle point.

### Policies and permissions

- Purchase Policy defines list/view/create/update/submit/cancel/approve/complete/reverse/export.
- Manager/Admin may view Purchase unit costs/totals only for Purchases they are authorized to manage. This does not grant general Inventory financial queries, reports, exports, or average-cost audit fields.

### Filament resources and pages

- Purchase Resource with list/create/view/edit and line Relation Manager/repeater.
- Status/Supplier/date filters; financial columns Gate-protected.
- Named lifecycle Actions; no generic delete after submission.

### Validation rules

- Active Supplier/Warehouse/Product; at least one line; positive quantity; non-negative four-decimal unit cost; non-negative approved charges; valid dates and transitions.
- Recalculate all totals server-side and reject tampered totals.
- Client-supplied line/subtotal/final totals are ignored. Currency is AED.

### Activity-log events

- `purchase.created`, `updated`, `line_added`, `line_changed`, `line_removed`, `submitted`, `cancelled`.

### Automated tests

- Reference uniqueness, totals/rounding, validation, draft mutability, submitted immutability, Policy/financial leakage, and audit events.

### Files to create

- Purchase/Item migrations/models/enum, DTOs/Actions/calculator, Policy, Filament Resource/pages/schemas/Relation Manager/table, factories/tests.

### Existing files to change

- Supplier/Warehouse/Product relationships, navigation, movement source morph map when completion is enabled.

### Risks

- Client-calculated totals, cost leakage, duplicate lines, invalid Suppliers/Products, or state bypass.

### Dependencies

- Phases 1B–3, sequence Service, Inventory foundation.
- B14–B16 are approved as revised: exact statuses/authority, commercial fields, partial receiving, and immutable GRNs apply.

### Out-of-scope items

- Multi-currency, payment status/terms, accounts payable, supplier returns, receipt reversal, landed-cost allocation, and external imports.

### Acceptance criteria

- Authorized draft workflow is valid and auditable.
- References and totals are database/server safe.
- State/Policy protections prevent unauthorized edits and cost access.
- Purchase tests pass.

## 11. Phase 4 — Purchase Approval and Completion

### Purpose

Approve valid Purchases and post received stock and weighted-average cost exactly once.

### Scope

- Approval, completion, transactional inventory/valuation posting, failure recovery, and after-commit events.

### Current repository state

- No approval/completion workflow, actor fields, Inventory Service, locking, idempotency, or tests exist.

### Business rules

- Only an approved Purchase can complete.
- Completion is atomic: lock Purchase and Product Inventory rows in consistent order, revalidate, append movements, update balances/average costs, record actors/times/audit, and commit.
- New average cost = `(existing physical eligible quantity × current average cost + received quantity × received unit cost) ÷ new eligible quantity`, with approved four-decimal half-up behavior.
- Completion posts once; retries return the existing result or a controlled conflict.
- Slow external work is not performed while locks are held.
- Admin may approve only a Purchase created by another actor and may complete an already approved Purchase. Owner may approve and complete. Manager cannot approve/complete in the first release. Self-approval is prohibited and completed Purchases never reopen.
- Reserved quantity remains included in available quantity for costing. Damaged stock is part of valuation quantity. Accepted and damaged receipt quantities use the same effective inventory unit cost; rejected quantity is excluded.
- Zero-cost items are rejected unless Owner explicitly approves each with a mandatory reason; negative cost is always rejected.

### Database tables and columns

- Uses Purchase, Item, Product Inventory, and Stock Movement tables.
- Completion identity/idempotency is protected by unique source/movement or idempotency constraint.
- `approved_by/approved_at`, `completed_by/completed_at` are required in corresponding states.

### Relationships

- Completed Purchase links to its movement group through stable source/group identity.

### PHP enums

- Purchase Status and `StockMovementType::PurchaseReceipt`.

### Services and actions

- `ApproveStockPurchase` and `CompleteStockPurchase` Actions.
- Completion delegates only to Inventory Service costed-receipt operation and calculator.
- Events `PurchaseApproved` and `PurchaseCompleted` dispatch after commit where listeners require persisted state.

### Policies and permissions

- Approve/complete permissions follow B14 exactly.
- Costs and resulting valuation are Owner-only.
- Self-approval is prohibited for all actors.

### Filament resources and pages

- Confirmed Approve and Complete Actions visible only in valid state.
- Completion preview shows Purchase lines/totals to the authorized Owner/Admin completion actor without exposing Inventory average-cost/value audit fields to Admin.
- Completed page becomes read-only with movement links.

### Validation rules

- Valid state, active Warehouse, approved contractual Product/Supplier handling, nonempty lines, positive quantities, outstanding-quantity limits, approved costs, no duplicate posting, actor permission, and approved sole-Owner self-approval exception.

### Activity-log events

- `purchase.approved`
- `purchase.approval_rejected`
- `purchase.completed`
- `purchase.completion_rejected`
- `purchase.completion_retry_detected`

### Automated tests

- Approval authority/self-approval, completion math, full receipt, multiple Products, existing/zero balance, zero/negative cost, rounding, idempotent retry, rollback, lock ordering, Policy, audit, and ledger reconciliation.
- Expected weighted-average assertions: Example 1 stores `2100.0000`; Example 2 stores `1750.0000`; Example 3 stores `2033.5184` from `(4 × 1999.9999 + 2 × 2100.5555) ÷ 6` using decimal-safe half-up rounding.
- Genuine concurrent completion test on MySQL later.

### Files to create

- Approval/completion Actions, domain events/listeners as approved, business exceptions, focused tests.

### Existing files to change

- Purchase Resource/pages, Policy, Purchase model casts/relationships, Inventory Service/movement enum, activity catalog.

### Risks

- Duplicate receipt, average-cost error, deadlock, partial posting, approval bypass, or cost leak.

### Dependencies

- Sections 8 and 10.
- B14–B17 are approved, including the three exact expected stored average-cost results.

### Out-of-scope items

- Payments, accounting journals, notifications beyond approved internal events, receipt reversals/returns, landed-cost allocation, and backdated recalculation.

### Acceptance criteria

- Only approved documents complete.
- Completion is atomic, idempotent, reconciled, and auditable.
- Weighted-average cost matches approved examples and rounding.
- Failed completion leaves no partial stock/value changes.

## 12. Phase 5 — Purchase Reversals

### Purpose

Correct an eligible completed Purchase without deleting or rewriting its original document, movements, or valuation.

### Scope

- Reversal request, approval if selected, validation, posting, linkage, and read-only history.

### Current repository state

- No Purchase/Reversal tables, movement reversal links, Services, UI, or tests exist.

### Business rules

- Original Purchase remains completed/history-visible; reversal is a separate referenced document.
- References never reuse Purchase references.
- Reversal quantity/cost links to the original receipt and appends inverse movements.
- Reversal cannot cause negative available/physical stock or invalid valuation.
- Exact reversal is blocked if purchased stock has been sold, reserved, damaged, transferred, written off, or affected by a later cost-changing movement.
- The system does not identify fungible units by batch. A blocked case requires a later Owner-authorized adjustment workflow with full explanation/audit.
- The original Purchase remains Completed unless a valid formal reversal succeeds.
- Completed reversal is immutable and idempotent.

### Database tables and columns

- `purchase_reversals`: `id`, unique `reference` using `PR-{YEAR}-{SEQUENCE}`, `stock_purchase_id`, `warehouse_id`, `status`, required `reason`, actor/timestamp fields for requested/approved/completed/cancelled, timestamps.
- `purchase_reversal_items`: `id`, `purchase_reversal_id`, `stock_purchase_item_id`, `product_id`, unsigned integer `quantity`, `unit_cost DECIMAL(15,4)`, timestamps while draft.
- Unique idempotency/source constraints and indexes; restrictive foreign keys.

### Relationships

- Reversal belongs to original Purchase/Warehouse/actors; has Items and movement group.
- Reversal Item belongs to original Purchase Item and Product.

### PHP enums

- `PurchaseReversalStatus`: `Draft = 'draft'`, `PendingApproval = 'pending_approval'`, `Approved = 'approved'`, `Completed = 'completed'`, `Cancelled = 'cancelled'`.
- `StockMovementType::PurchaseReversal`.

### Services and actions

- `CreatePurchaseReversal`, `SubmitPurchaseReversal`, `ApprovePurchaseReversal`, `CompletePurchaseReversal`, `CancelPurchaseReversal`.
- Completion uses Inventory Service reversal operation with original cost basis.

### Policies and permissions

- Owner may create/request, approve, and complete. Admin may request but cannot approve or complete. Creator/approver separation is preferred; when only one active Owner exists, that Owner may self-approve as an explicit exception with mandatory reason, confirmation, activity event, timestamps, and a self-approval marker. With another authorized approver, self-approval is prohibited.
- Financial visibility and all approval/completion remain Owner-only.

### Filament resources and pages

- Reversal Resource or purpose-built Purchase relation page.
- Select only eligible completed Purchase lines/remaining reversible quantities.
- Require reason/confirmation; completed reversal read-only with links both ways.

### Validation rules

- Original Purchase completed/not previously reversed; mandatory reason; exact full reversal scope; no disqualifying later stock/cost event; sufficient stock/value; valid state; idempotency; self-approval only under the sole-active-Owner exception.

### Activity-log events

- `purchase_reversal.created`, `submitted`, `approved`, `completed`, `cancelled`, `rejected`.

### Automated tests

- Exact full reversal, Admin-request/Owner-approval, ordinary self-approval rejection, sole-Owner exception evidence, consumed/reserved/damaged/transferred/written-off/later-cost-event rejection, cost/ledger math, idempotency, rollback, Policy, immutability, and reconciliation.
- MySQL concurrent reversal/consumption test later.

### Files to create

- Reversal migrations/models/enums/DTOs/Actions/Policy/Filament Resource and focused tests.

### Existing files to change

- Purchase model/Resource, Inventory Service/movement enum, sequence and activity catalogs.

### Risks

- Removing stock already consumed, incorrect value removal, repeated reversal, hidden history, or racing fulfilment/reservation.

### Dependencies

- Completed Phase 4 workflow and immutable Inventory ledger.
- B18–B19 are approved, including the audited sole-active-Owner self-approval exception.

### Out-of-scope items

- Supplier credit notes/payments, accounting reversal journals, and arbitrary stock correction.

### Acceptance criteria

- Original and reversal remain fully linked and immutable.
- Unsafe reversal is rejected without partial effects.
- Eligible reversal posts exactly once and reconciles quantity/value.

## 13. Phase 6 — Inventory Reservations

> **Approved ordering update:** Basic Reservations and full release are implemented in Phase 2. Partial release, fulfilment, expiry, Sales Order linkage, and customer linkage remain deferred to Phase 6 or later approval.

### Purpose

Provide a safe, reusable reservation foundation that prevents overselling before Sales/Marketplace modules are designed.

### Scope

- Create, release, fulfil, and cancel reservation quantities through Inventory Services. Expiration automation is deferred.
- Provide an internal source contract for future order modules without designing those modules here.

### Current repository state

- No reservation table, reserved balance, Service, source contract, scheduler, UI, or tests exist.

### Business rules

- Reserved quantity is part of Product Inventory and cannot exceed available quantity.
- Sellable equals available minus reserved.
- Reservation does not change physical quantity or average cost.
- Release decreases reserved only; fulfilment decreases reserved and available atomically and records outbound movement using current approved cost basis.
- Every operation is idempotent, source-linked, authorized, and auditable.
- Manual reservations require a reason and User actor.
- Duplicate release or fulfilment is rejected/idempotently resolved without changing balances twice.

### Database tables and columns

- `inventory_reservations`: `id`, unique `reference` using `RSV-{YEAR}-{SEQUENCE}`, `warehouse_id`, `product_id`, nullable morph-map `source_type/source_id`, `status`, unsigned integer `quantity`, `fulfilled_quantity`, `released_quantity`, nullable unique `idempotency_key`, nullable `expires_at`, nullable `reason`, actor/timestamp fields, timestamps.
- Index Product/Warehouse/status/expiry and source type/id; constraints prevent impossible totals.

### Relationships

- Reservation belongs to Warehouse, Product, actor Users; morphs to approved future source.
- Links to movement group on fulfilment/reversal through source identity.

### PHP enums

- `ReservationStatus`: `Active = 'active'`, `Released = 'released'`, `Fulfilled = 'fulfilled'`, `Expired = 'expired'`, `Cancelled = 'cancelled'`.
- Reserve/release may be activity events; fulfilment is a Stock Movement type.

### Services and actions

- `ReserveInventory`, `ReleaseInventoryReservation`, `FulfillInventoryReservation`, and `CancelInventoryReservation`.
- No expiration Job is implemented in the first release; `expired` is future-compatible and cannot be set by an unapproved arbitrary update.

### Policies and permissions

- Manual creation is allowed only to B04-authorized operational roles and requires reason/actor.
- Future source module Policy plus Inventory Reservation Policy must both allow operation.
- Cost on fulfilment remains Owner-only.

### Filament resources and pages

- Read-only Reservation Resource for authorized troubleshooting.
- Filters by status/Product/source/expiry; no generic create/edit/delete.
- Purpose-built create/release/fulfil/cancel Actions follow B20 and never directly edit quantities.

### Validation rules

- Positive integer quantity, active Product/Warehouse, sufficient sellable stock, supported source type, unique idempotency, valid transition, consistent fulfilled/released totals.

### Activity-log events

- `inventory.reserved`, `reservation.released`, `reservation.expired`, `reservation.fulfilled`, `reservation.rejected`.

### Automated tests

- Create/release/fulfil/cancel, insufficient stock, mandatory manual reason, duplicate release/fulfil, transaction rollback, source authorization, reconciliation, and MySQL concurrency later.

### Files to create

- Reservation migration/model/enum/DTOs/Actions/Policy/read-only Filament Resource/tests; no expiry Job.

### Existing files to change

- Product Inventory relationship, Inventory Service, movement/activity enums, navigation.

### Risks

- Overselling, leaked/never-released reservations, duplicate fulfilment, polymorphic source instability, or premature assumptions about future Orders.

### Dependencies

- Phase 2 ledger and default Warehouse.
- B20 is approved; expiration automation, Orders, and polymorphic Order linkage remain deferred.

### Out-of-scope items

- Sales Orders, Marketplace Orders, customer allocation, backorders, payments, shipping, and channel priority.

### Acceptance criteria

- Concurrent requests cannot reserve beyond sellable stock.
- Lifecycle is idempotent and reconciles with reserved balance.
- No future Order schema or workflow is embedded.

## 14. Phase 7 — Damaged and Safe-T Stock Foundation

### Purpose

Provide the first-release available-to-damaged transfer foundation without designing Returns or Safe-T claims.

### Scope

- Transfer available stock to damaged only.
- Preserve one damaged balance and immutable movement history.

### Current repository state

- No Product Inventory damaged balance, movement types, transfer Service, UI, evidence links, or tests exist.

### Business rules

- Damaged stock is excluded from sellable quantity.
- Transfer to damaged creates one negative available movement and one positive damaged movement using the same UUID `movement_group`.
- Safe-T is a future claim workflow, not a separate current balance in this foundation.
- Safe-T-related units remain included in `damaged_quantity`; a reason/source reference may distinguish context without creating an alternative balance or claim workflow.
- Transfers require positive quantity, mandatory reason, User actor, source where available, and sufficient available balance.
- A transfer never changes total physical quantity or recalculates average cost.

### Database tables and columns

- Reuse Product Inventory `available_quantity`/`damaged_quantity` and immutable Stock Movements.
- Stock Movement requires from/to section, reason code/string, source, group identity, quantity, actor, occurred time.
- No `safe_t_claims` table is created in Phase 7.

### Relationships

- Transfer movements relate to Product, Warehouse, actor, optional source, and reversal movement.

### PHP enums

- `InventorySection`: `Available`, `Damaged`.
- Movement uses approved `transfer`/`safe_t`-compatible catalog values and sections `available` and `damaged`; exact pair encoding must remain consistent with B10/B12.
- Reason is mandatory; Safe-T claims, write-offs, recovery, repair, and disposal reasons do not create workflows in Phase 7.

### Services and actions

- `TransferInventoryToDamaged` through Inventory Service.
- No claim submission/reimbursement/disposition logic.

### Policies and permissions

- Available-to-damaged transfer is an explicit privileged ability under B04.
- Movement records read-only; average cost/value Owner-only.

### Filament resources and pages

- Purpose-built confirmed transfer Action from authorized Product Inventory view or a small transfer page.
- Require reason/source and show before/after quantities.
- Damaged history is read-only and filterable; no Safe-T Claim Resource.

### Validation rules

- Active Product/Warehouse, positive integer quantity, sufficient source balance, allowed reason, source/evidence rules, idempotency, authorized transition.

### Activity-log events

- `inventory.transferred_to_damaged`
- `inventory.damage_transfer_rejected`

### Automated tests

- Paired movement arithmetic/group identity, insufficient stock, physical-total preservation, no cost recalculation, duplicate request, Policy, audit, and reconciliation.

### Files to create

- Transfer DTO/Action, any stable reason enum approved in the implementation plan, purpose-built Filament Action/page if needed, tests.

### Existing files to change

- Inventory Section/Movement enums, Inventory Service, Product Inventory UI, activity catalog.

### Risks

- Treating Safe-T as alternative stock truth, unpaired movements, accidental sellability, unsupported reverse/disposition logic, or cost recalculation on state transfer.

### Dependencies

- Phase 2 Inventory ledger.
- B21 is approved; Phase 7 is limited to available-to-damaged transfer.

### Out-of-scope items

- Damaged-to-available recovery, transfer reversal, Returns, Warranty, Safe-T Claim records/submission/deadlines/reimbursement, disposal/write-off, repair, supplier return, and marketplace integration.

### Acceptance criteria

- Available-to-damaged transfer creates the required paired movements atomically, idempotently, and auditably while remaining physically quantity-neutral.
- Damaged units cannot be sold.
- Safe-T context is traceable without a Safe-T Claim schema or duplicate balance.

## 15. Cross-Cutting — Activity Logging

### Purpose

Provide project-owned, sanitized evidence for important actions across Phases 1A–7 without installing a package.

### Scope

- Append-only activity records, central writer Service, module event catalog, authorized read-only review UI, retention-ready design.

### Current repository state

- No project-owned Activity Log table, Model, Service, Policy, Resource, or tests exist.
- Existing Models use creation hooks for references but no durable business audit trail.

### Business rules

- Critical events are written in the same transaction where possible.
- Activity history is append-only and not a substitute for Stock Movements or document timelines.
- Actor is User or explicit system identity.
- Payloads are sanitized and minimal; passwords, hashes, tokens, sessions, API secrets, payment credentials, and private authentication information are prohibited.
- Owner-only financial values are excluded from generic payloads or protected as owner-only.
- Owner may view all Phase 1A–7 activity logs. Admin may view operational logs without protected financial payloads. Manager and Staff have no broad Activity Log access.
- IP address and User Agent may be stored when available.
- Generic old/new JSON avoids financial values and relies on protected documents/Stock Movements for financial truth.

### Database tables and columns

- `activity_logs`: `id`, nullable `actor_id`, `event`, morph-map `subject_type/subject_id`, nullable sanitized `old_values` JSON, nullable sanitized `new_values` JSON, nullable `ip_address`, nullable bounded `user_agent`, nullable `correlation_id`, nullable `source`, `occurred_at`, `created_at`; no `updated_at`.
- Index actor/time, event/time, subject type/id/time, correlation ID.

### Relationships

- Activity Log belongs to User actor and morphs to a stable-mapped subject where appropriate.

### PHP enums

- Stable event identifiers may use per-module enums/constants; database stores stable lowercase dot-separated values.

### Services and actions

- `ActivityLogger` accepts a typed/sanitized event DTO.
- Module Services call it at the authoritative transaction boundary.
- No broad Model Observer captures entire rows or critical business mutations implicitly.

### Policies and permissions

- ActivityLog Policy is read-only and role-scoped.
- Financial/security/authentication payloads are Owner-only; Admin operational views are redacted.
- No edit/delete UI permission.

### Filament resources and pages

- Read-only Activity Log Resource with date, actor, event, subject, and correlation filters.
- Payload display is redacted/authorized and not bulk-exported by default.

### Validation rules

- Event identifier required and cataloged; actor/system source valid; JSON keys allowlisted; payload size bounded; forbidden keys recursively rejected.

### Activity-log events

- The logger does not recursively log its own successful writes.
- Access/export/retention actions on logs are separately auditable; export remains controlled by the B22 policy.
- Module events are those listed in Sections 3–14 and 16.

### Automated tests

- Same-transaction behavior for critical events, rollback, sanitization/forbidden keys, Policy/read-only behavior, morph map stability, and financial redaction.

### Files to create

- Activity Log migration/model/DTO/Service/Policy/read-only Filament Resource and tests.

### Existing files to change

- Module Services/Actions add explicit logger calls.
- Service provider only for morph map/Policy binding if required.

### Risks

- Credential leakage, oversized payloads, duplicated history, missing critical events, recursive logging, or treating logs as ledger truth.

### Dependencies

- Phase 1A User identity and authorization.
- B22 is approved; implementation must preserve append-only, same-transaction, redaction, and role-scoped access rules.

### Out-of-scope items

- Third-party logging packages, full compliance reporting, log shipping/SIEM, behavioral monitoring, and screenshot access logs.

### Acceptance criteria

- Required events persist atomically where specified.
- Forbidden data cannot enter payloads.
- Records are read-only and authorized.
- Each implemented phase has event/test coverage.

## 16. Cross-Cutting — Filament Navigation and UX

### Purpose

Present Phases 1A–7 as a coherent, permission-aware operational UI without placing business logic in Resources.

### Scope

- Navigation groups, ordering, icons, labels, badges, forms, tables, infolists, lifecycle Actions, read-only ledger pages, and owner-only field visibility for the specified phases.

### Current repository state

- Admin panel auto-discovers Resources and uses Amber primary color.
- Employee, Product, and Team Resources use the same generic rectangle-stack icon and no explicit navigation grouping/order.
- Employee and Product pages expose delete/bulk-delete.
- Product UI labels `cost_price` “Average Cost,” omits warranty/selling price from Form, renders Product status as a boolean in Infolist, and exposes cost without inspected authorization.
- No Policies are present.

### Business rules

- Navigation visibility follows Policies/Gates and must not reveal inaccessible counts.
- Resources contain presentation/delegation only; Services/Actions perform workflows.
- Semantic colors: primary normal action, success completed/active, warning attention, danger destructive/failed/damaged, neutral information.
- Color never stands alone; labels/badges remain accessible.
- Immutable ledger/history Resources are read-only.
- High-impact Actions require confirmation, reason where applicable, and clear result.

### Database tables and columns

- None owned by navigation/UX.

### Relationships

- Relation Managers are used only for true subordinate data such as Purchase Items; they do not mutate inventory directly.

### PHP enums

- Forms/tables use approved Enums for roles, statuses, conditions, movement types, sections, and reasons.

### Services and actions

- Filament Actions validate presentation input, authorize, build DTOs, and call module Actions/Services.
- No direct balance, movement, approval, completion, reversal, or reservation updates.

### Policies and permissions

- Every Resource has an applicable Policy before navigation exposure.
- Owner-only financial fields are excluded server-side and visually.
- Bulk Actions are disabled for high-impact workflows unless a dedicated batch Service is approved.

### Filament resources and pages

- Approved groups/order: **People** (Employees, Teams), **Catalog** (Products, Suppliers), **Inventory** (Warehouses, Inventory Overview, Stock Movements, Opening Stock, Reservations), **Purchasing** (Purchases, Purchase Reversals).
- Activity Logs remain permission-restricted and may appear in an Owner/Admin system area without creating a new broad business navigation group.
- Use distinct semantic Heroicons consistent with the B23 groups, with stable labels and page titles; exact icon selection is a presentation detail for the implementation plan.
- Preserve Product field name while using approved UI label.
- Use status badges and clear confirmation dialogs. Completed records, Inventory Overview, and Stock Movements are read-only.
- No direct stock quantity editing. Navigation items are hidden when the Policy denies access. Maintain mobile responsiveness where practical.

### Validation rules

- Every form uses server validation and relationship scoping.
- UI disabled/hidden state never replaces Policy/Service validation.
- Monetary inputs apply approved scale and AED label; dates/times are explicit.

### Activity-log events

- UI invokes module events; it does not create duplicate generic CRUD events where the module already logs the business fact.
- High-risk export/access events follow Activity Log policy.

### Automated tests

- Resource visibility by role, Action authorization, field leakage, read-only ledgers, enum labels, absence of forbidden delete Actions, navigation grouping, and Service delegation.

### Files to create

- Module-specific Filament Resources/pages/schemas/tables/Relation Managers listed in earlier sections.
- Optional navigation support enum/class only if useful and approved.

### Existing files to change

- Existing Employee/Product Resource files and pages.
- `app/Providers/Filament/AdminPanelProvider.php` only if explicit navigation/dashboard behavior requires it.
- Team Resource only for approved People regrouping, not functional cleanup.

### Risks

- Authorization by hiding only, cost leakage, destructive generic Actions, inconsistent states/colors, or business logic creeping into Filament classes.

### Dependencies

- Policy/Gate foundation and each module's Services/Enums.
- B23 is approved with the exact People/Catalog/Inventory/Purchasing grouping above.

### Out-of-scope items

- Custom theme/package installation, future-module navigation, dashboards/reports, public portal, mobile UI, and broad Team redesign.

### Acceptance criteria

- Navigation is phase-appropriate, semantic, and permission-aware.
- No Resource contains business workflow logic.
- Owner-only data never renders or queries for unauthorized users.
- Immutable records have no generic mutation actions.
- Existing Employee/Product UX conflicts are resolved only in their approved phases.

## 17. Future Modules — Dependencies and Later Roadmap Only

The following modules are intentionally not designed here. They may depend on the first-phase foundation but require new business specifications and separate approval:

- Sales Orders — depends on Products, Inventory, Reservations, and authorization.
- Marketplace Orders — depends on Sales Orders, Inventory, idempotency, queues, and approved integrations.
- Returns — depends on Orders, Inventory, damaged transfers, and approved return/cost policy.
- Warranty — depends on Products, Orders/Returns, Inventory custody, and private files.
- Safe-T Claims — depends on Marketplace Returns and the Phase 7 damaged foundation.
- Brand Assignment — depends on Employees and Product brand policy; it never becomes alternate inventory.
- Tasks and Task Timelines — depend on Employee identity, Policies, private files, and immutable events.
- Internal Chat — depends on Employee identity, private attachments, retention, and approved real-time infrastructure.
- Employee Work Sessions, Activity Monitoring, and Screenshot Records — depend on separate privacy, retention, permission, and Windows agent security approval.
- Dashboards and Reports — depend on stable authoritative modules and owner-only query protection.
- Accounting — depends on approved immutable business events, chart/posting rules, periods, and reconciliation.
- Audit and Compliance — depends on Activity Logs, module ledgers, retention, and applicable compliance policy.
- External Integrations — depend on the relevant mature module plus credentials, idempotency, rate limits, reconciliation, and separate approval.

No tables, columns, state machines, Services, Filament Resources, or implementation files for these future modules are specified or authorized by this document.

## 18. Approved Decision Register — B01–B23

- **B01 — Initial Owner identity:** Confirmed User ID `1` (`Adil Hussain`, `adiljaved104@gmail.com`). Create exactly one linked Employee with name `Adil Hussain`, the same email, designation `Owner / Managing Director`, Owner role, active status, and `user_id = 1`; phone/team/joining date remain null. Generate a compatible `TPZ-####` reference. Do not duplicate or change User email/password. Legacy Employee password handling remains separately unresolved.
- **B02 — User–Employee backfill:** Match normalized email only for exactly one User and one Employee. Duplicate, missing, and conflicting cases remain unlinked in an exception report. Owner/Admin may manually link an approved pair. `employees.user_id` is nullable and unique.
- **B03 — Access lifecycle:** User remains authentication identity. After linkage, inactive/terminated Employee status blocks Filament access. Records/history are preserved; rehire/reactivation is explicit and authorized.
- **B04 — Phase permission matrix:** Owner has full Phase 1A–7 and financial authority. Admin manages operational master/workflow modules without financial visibility, cannot alter/remove Owner, may complete approved Purchases, and may approve only another actor's Purchase. Manager views operations and creates/submits Purchases without cost/approval/completion/reversal access. Staff has only assigned operational views. Policies/Services enforce independently of Filament.
- **B05 — Supplier data:** Name required; contact person, phone, email, address, VAT number, and notes optional. Phone/email is recommended but not mandatory. No automatic duplicate merge. Inactive Suppliers are unavailable for new Purchases.
- **B06 — Default Warehouse:** Required record is `Main Warehouse` / `MAIN`, active and default. Create it using an idempotent production data migration/deployment step, not only a development Seeder.
- **B07 — Product enums:** Conditions are New, Renewed, Used, OpenBox, Refurbished; statuses are Active, Inactive, Discontinued, with stable backed values. Report current distinct data before mapping and list unknown values for approval. Inspection found only condition `New` and status `Active`, one record each.
- **B08 — Product fields:** Retain all existing Product fields. Name remains `products.name`; warranty is months; `cost_price` is Owner-only; selling price may be visible to authorized operations; average cost/value is Owner-only; Product creation does not create stock.
- **B09 — References:** Preserve Employee `TPZ-####` with a concurrency-safe non-reusing sequence. Phase 2 formats are Stock Movement `SM-000001`, Opening Stock `OS-000001`, and Reservation `RSV-000001`, using six digits as minimum padding. References are server-generated, unique, gap-tolerant, never reused, and never use unsafe count/max-plus-one.
- **B10 — Movement catalog:** Types are `opening_stock`, `purchase`, `purchase_reversal`, `reservation`, `reservation_release`, `sale`, `sale_cancellation`, `return_ok`, `return_damaged`, `transfer`, `adjustment`, `write_off`, `warranty`, `safe_t`. Sections are `available`, `reserved`, `damaged`. Implement only Phase 1A–7 workflows now.
- **B11 — Costing edges:** Weighted average uses valuation quantity `available_quantity + damaged_quantity`; reserved is already included in available. Zero existing valuation quantity adopts incoming unit cost; positive valuation quantity with null average is invalid; zero cost is valid; reservations and available/damaged transitions do not recalculate; exact BCMath half-up rules apply.
- **B12 — Movement identity/source:** Polymorphic source with stable morph map; UUID `movement_group`; nullable unique `idempotency_key` where appropriate; Purchase movements have a unique Purchase Item safeguard.
- **B13 — Opening Stock:** Owner-only focused single-entry posting; exactly one Product/Warehouse entry; available and damaged quantities supported; reserved prohibited; unit cost and reason required; any prior movement blocks posting; posting is transactional/idempotent and immutable; later correction uses an authorized adjustment.
- **B14 — Purchase states/authority:** States are draft, approved, partially received, fully received, closed, cancelled. Owner approves/receives/closes. Admin creates/edits Drafts and receives but does not approve by default. Manager creates and edits owned/authorized Drafts but does not approve or receive. Sole-active-Owner self-approval requires confirmation, explicit reason, `self_approved`, and safe audit. Closed/Cancelled are terminal.
- **B15 — Purchase totals:** Include Supplier, Warehouse, purchase date, nullable Supplier invoice number/date, notes, item quantity/cost/line total, subtotal, discount, tax, shipping/other charges, final total. Server calculates all AED totals and ignores client totals. Multi-currency/payments/terms/AP are deferred.
- **B16 — Receipt scope:** First release supports partial receiving and multiple immutable GRNs. Accepted enters Available, damaged enters Damaged and remains valued, rejected enters no inventory. A rejected-only GRN preserves history and prevents cancellation. Owner may close an under-received Purchase with reason.
- **B17 — Cost examples:** Store `2100.0000` for 10 @ 2000 plus 5 @ 2300; `1750.0000` for zero stock plus 3 @ 1750; `2033.5184` for 4 @ 1999.9999 plus 2 @ 2100.5555 using decimal-safe half-up.
- **B18 — Reversal authority:** Owner may create/approve/complete; Admin may request only; reason mandatory. Prefer creator/approver separation. If only one active Owner exists, that Owner may self-approve with confirmation, reason, activity log, timestamps, and explicit self-approval record. Prohibit self-approval when another authorized approver exists. Original Purchase/movements remain unchanged.
- **B19 — Consumed reversal:** Exact reversal is blocked after sale, reservation, damage, transfer, write-off, or later cost-changing movement. No batch identity is inferred. Blocked cases require future Owner-authorized adjustment with explanation/audit; original Purchase remains Completed unless formal reversal succeeds.
- **B20 — Reservations:** Phase 2 states are active and released. Quantity cannot exceed Sellable; full release only; reason/actor/idempotency are required; duplicate release is prevented. Partial release, fulfilment, expiry, cancellation, and future Order linkage are deferred.
- **B21 — Damaged/Safe-T foundation:** Phase 2 supports available-to-damaged and damaged-to-available transitions with quantity, reason, actor, idempotency, and immutable movements. Physical quantity and average cost are preserved. Safe-T claims, Warranty, write-off, repair, disposal, and other dispositions remain deferred.
- **B22 — Activity Logs:** Project-owned, append-only, same transaction where practical. Owner sees all; Admin sees operational/redacted logs; Manager/Staff have no broad access. Never store credentials/secrets. Avoid financial old/new JSON; IP/User Agent may be stored when available.
- **B23 — Filament UX:** Groups are People (Employees, Teams), Catalog (Products, Suppliers), Inventory (Warehouses, Inventory Overview, Stock Movements, Opening Stock, Reservations), Purchasing (Purchases, Purchase Reversals). Use badges/confirmations; completed/overview/movements are read-only; no direct quantity edit; financial queries/exports and navigation are Policy-scoped; preserve practical mobile responsiveness.

### Remaining Phase 1A blocker

The only unresolved Employee field is legacy `employees.password`: the current column is non-nullable and the current Form requires it when creating an Employee. No value can be safely supplied without inventing or duplicating a credential. Before Phase 1A implementation, approve a new migration that keeps the legacy column but makes it nullable for linked User-backed Employees, preserves existing data, and removes the Phase 1A Form requirement for linked Employees. No password, Owner Employee, link, or migration is created by this documentation task.

## 19. Repository Conflict Matrix

| Required area | Current repository state | Approved target / treatment | Phase |
|---|---|---|---|
| User model | Confirmed Owner User ID 1; User is the only authentication model and has no Employee relationship | Keep authentication unchanged; add `employee()` and exact approved Employee link during approved Phase 1A | 1A |
| Employee model | No Employee rows exist; Model has Team relationship but no User link or status/role casts | Create exactly the approved Owner Employee and add nullable unique User relationship after legacy password handling is approved | 1A |
| Employee credentials | Employee email/password are required; password column/Form cannot be safely populated for the linked Owner | Keep fields; propose nullable legacy password for linked User-backed Employees; do not invent/copy credentials; later removal remains separate | 1A staged / later |
| Employee role | Database-native enum with title-case stored values and hard-coded UI | Safely map to PHP backed enum lowercase values | 1A |
| Employee ID generation | Latest record plus one generates `TPZ-####`; database currently has no Employee rows | Preserve `TPZ-####` and replace only its unsafe allocator with an atomic non-reusing sequence | 1A |
| Product name | Existing `products.name` | Accepted; do not rename; UI may say Product/Product Title | Accepted legacy structure |
| Product cost precision | `DECIMAL(10,2)`, cast decimal:2, UI calls it Average Cost | Safely change cost price to `DECIMAL(15,4)`, label it “Cost Price,” keep it separate from average cost, and protect it as financial data | 1C |
| Product selling-price precision | `DECIMAL(10,2)`, missing Model fillable/cast and Form input | Safely change to `DECIMAL(15,2)` and add only approved protected input/cast | 1C |
| Product warranty | Signed integer default 12; Form omits it; UI lacks month formatting | Preserve/map to unsigned integer months with validation/formatting | 1C |
| Product SKU generation | `max(id) + 1` in Model hook | Replace with approved concurrency-safe strategy; never reuse | 1C |
| Product enum data | Stored values are currently `New` and `Active`; Filament also offers `Open Box` while approved case is `OpenBox` | Re-report distinct values before implementation; map known values and stop on unknowns | 1C |
| SQLite environment | `.env` and standard tests use SQLite/in-memory SQLite | Continue unchanged; later run real concurrency tests on approved MySQL/InnoDB environment | Accepted environment |
| Missing inventory tables | No Product Inventory, Movement, Adjustment, Opening Stock, Purchase, Reversal, or Reservation tables | Add only through separately approved new migrations; Adjustment requires focused approval before Phase 3 | 2–6 |
| Missing policies | No Policy files found | Add baseline default-deny Policies in 1A, complete per phase before Resource exposure | 1A onward |
| Existing Filament resources | Employee/Product/Team Resources exist; generic icons, deletes, hard-coded roles; Product cost exposed/mislabeled | Remediate Employee/Product in their phases; only regroup Team if approved; no business logic in Resources | 1A/1C/cross-cutting |

No existing migration or application file is changed by this documentation. The matrix records future remediation scope only.

## 20. Contradiction Resolution Status

- Initial Owner identity/profile is confirmed; only legacy Employee password nullability remains unresolved.
- Employee references preserve `TPZ-####`; only the unsafe allocator changes.
- Product `OpenBox`/`open_box`/“Open Box” case, stored value, and label are aligned, with mandatory pre-migration distinct-value reporting.
- Product UI will use “Cost Price,” protect it as financial data, and never present it as Inventory average cost.
- Purchase-authorized Manager/Admin receive scoped Purchase cost visibility without general Inventory financial visibility.
- Sole-active-Owner reversal self-approval is allowed only as the fully audited exception recorded in B18.
- Opening Stock corrections use a separately authorized Adjustment; no first-release Opening Stock reversal document.
- Purchase receiving uses separate immutable GRNs, supports partial/multiple receipts, and automatically maintains Partially Received/Fully Received status. Manual Owner closure preserves outstanding quantity.
- A focused Adjustment specification is included before Phase 3 and still needs separate implementation approval.

No contradiction listed in the prior revision remains unresolved in this document. The only Phase 1A blocker is the legacy Employee password requirement recorded in Section 18.

## 21. Approval Boundary

Approval of this document does not authorize:

- Any listed file creation or modification
- Migration creation, editing, or execution
- Owner bootstrap or data backfill
- Role or Product data mapping
- Database, `.env`, or configuration changes
- Package installation
- MySQL environment setup
- Application tests or formatting that could mutate generated/configured state
- Future-module design or implementation
- Deployment or push to `main`

Before each phase, present the approved decisions, exact migrations and data mapping, exact files, transaction/locking behavior, authorization matrix, audit events, tests, recovery plan, and implementation approval request. Phase 1A additionally requires explicit approval of the proposed nullable legacy Employee-password change; the Owner identity/profile is already confirmed.
