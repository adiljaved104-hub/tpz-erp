# TPZ ERP Database Standards

This document is the permanent database architecture reference for the TPZ ERP. It must be read together with `PROJECT_CONTEXT.md`, `SYSTEM_ARCHITECTURE.md`, and `AGENTS.md` before any schema or data work is proposed.

This document does not authorize implementation. No migration, schema execution, data change, package installation, authentication change, database configuration change, or deployment may occur without a separate explained plan and explicit approval.

## 1. Purpose and Scope

These standards define how ERP data is named, related, constrained, stored, retained, protected, migrated, tested, and reconciled. Their purpose is to keep the database consistent, auditable, secure, portable between approved environments, and aligned with the system's business rules.

They apply to:

- Authentication
- Employees
- Suppliers
- Warehouses
- Products
- Purchases
- Inventory
- Orders, including sales and marketplace orders
- Returns
- Warranty
- Safe-T claims
- Tasks and immutable task timelines
- Internal chat
- Employee work sessions
- Employee activity monitoring
- Employee screenshot records
- Accounting
- Operational and financial reports
- System activity and audit logs

The database supports domain Services; it does not replace them. Constraints are the final integrity safeguard, while authorization, state transitions, costing, inventory mutations, and workflow coordination remain in approved Services and Policies.

## 2. Database Environments

- SQLite may be used for local development and standard automated tests.
- MySQL with InnoDB is the production target.
- SQLite behavior must not be treated as proof of MySQL behavior. In particular, SQLite locking does not behave like MySQL row-level locking.
- Concurrency-sensitive workflows must be verified in a dedicated MySQL test environment configured to resemble production.
- Schema definitions, queries, constraints, indexes, and tests should remain compatible with both approved engines unless an intentional difference is documented and approved.
- MySQL production tables containing ERP business data must use InnoDB so transactions, foreign keys, and row locks are available.
- Database configuration must never be changed without approval. This includes `.env`, connection defaults, credentials, server settings, SQL modes, isolation levels, and database configuration files.
- The current SQLite configuration remains unchanged until a separately approved environment task. Approval of MySQL as the production target does not authorize a configuration change now.
- Automated tests must use an isolated test database and must never destructively reset a development, staging, or production database.

## 3. Table Naming Standards

- Use plural `snake_case` table names.
- Use complete, descriptive domain names and avoid unclear abbreviations.
- A table name must identify the business entity or event it owns.
- Names should remain stable even if UI labels change.
- Module-specific names are preferred over vague generic names such as `records`, `details`, or `data`.

Approved naming examples include:

- `stock_purchases`
- `stock_purchase_items`
- `product_inventories`
- `stock_movements`
- `task_activities`
- `chat_messages`
- `employee_work_sessions`
- `employee_screenshots`

Pivot tables should follow Laravel's conventional singular model names in alphabetical order, such as `employee_role`, unless an approved domain-specific name better expresses additional meaning. A relationship table with its own identity, state, dates, permissions, or history is a domain entity rather than a simple pivot and should receive a descriptive plural name, such as `chat_conversation_members`.

## 4. Primary Key Standards

- The default internal primary key is an unsigned big-integer auto-increment `id`, created with Laravel's `$table->id()` convention.
- Internal primary keys are database implementation details and must not be exposed as public document references when enumeration or cross-system coupling would be harmful.
- UUIDs or ULIDs may be introduced only where their operational value justifies their index, storage, and debugging costs.

Justified cases may include:

- Public identifiers
- Secure API references
- Cross-system synchronization identifiers
- Stock-movement or posting group identifiers
- Upload-session and device-enrollment identifiers

Do not replace all primary keys with UUIDs or ULIDs without approval.

Public-facing business documents use a separate, unique, immutable reference column. Examples include:

- `PO-2026-000001`
- `OS-2026-000001`
- `PR-2026-000001`
- `TASK-2026-000001`

Reference formats and prefixes remain subject to business approval. Internal IDs must not be embedded into a reference in a way that exposes row counts or prevents a future sequencing policy.

## 5. Foreign Key Standards

- Every real relationship must use an explicit foreign key where practical.
- Foreign-key columns use `<model>_id`, for example `supplier_id`, `warehouse_id`, `product_id`, `employee_id`, and `user_id`.
- Actor columns use clear action names, including `created_by`, `submitted_by`, `approved_by`, `completed_by`, `cancelled_by`, `reversed_by`, and `reviewed_by`.
- Actor columns reference `users.id` because User is the authenticated security identity. Employee references are added separately when the business relationship is specifically employment-related.
- Foreign-key columns are indexed unless already covered by a suitable composite or unique index.
- Required relationships are non-nullable. Nullable foreign keys require a documented meaning for the absent relationship.

The approved authentication linkage is a staged, nullable, one-to-one relationship from Employee to User:

- A future approved migration will add nullable `employees.user_id` referencing `users.id`.
- `employees.user_id` must be unique so one User cannot be linked to multiple Employees.
- User remains the only authentication model, and the staged migration must not break the current Filament login.
- The first migration must not remove `employees.email` or `employees.password`.
- Redundant Employee credential columns may be removed only by a later, separately approved migration after linkage, authentication, rollback, and production data have been verified.

Deletion rules are explicit:

- Use restrictive deletion for historical, inventory, financial, completed, security, and compliance records.
- Cascade deletion is permitted only for safe dependent draft records whose entire meaning and retention lifecycle belong to the parent.
- Use set-null only where the historical record remains valid and understandable without the related record.
- Never cascade-delete immutable ledgers, completed documents, stock movements, audit records, task timeline events, screenshot access logs, or financial transactions.
- A foreign key must not be omitted merely to make deletion easier.

## 6. Deletion and Retention Policy

- Draft records may be deleted only when deletion is safe, authorized, audited where appropriate, and has no posted or externally relied-upon consequences.
- Completed business documents must never be hard-deleted.
- Stock movements are append-only and immutable.
- Audit records must not be edited or silently removed.
- Suppliers and warehouses referenced by transactions should be deactivated rather than deleted.
- Products with transaction history should be deactivated rather than deleted.
- Screenshot files and metadata may be deleted only through approved retention workflows.
- Every deletion or anonymization action affecting financial, employee, security, monitoring, or compliance obligations must be audited.

Soft deletes are suitable when a record is operationally recoverable, ordinary queries should exclude it, relationships and uniqueness rules remain well-defined, and no immutable-history requirement is weakened. Examples may include selected unposted drafts or recoverable non-ledger content after module-specific approval.

A status field is preferable when the business needs explicit lifecycle states such as active, inactive, suspended, completed, cancelled, reversed, archived, or disposed; when the record must remain normally visible in history; or when reactivation is a controlled transition rather than restoration from deletion.

Soft deletes must not be added automatically to every table. They are not appropriate for append-only ledgers, audit events, completed documents, or records governed by explicit retention deletion. A module plan must define hard-delete, soft-delete, deactivation, anonymization, and retention behavior separately.

## 7. Timestamp Standards

- Use `created_at` and `updated_at` for normal mutable records.
- Use domain timestamps to represent business events instead of inferring them from generic timestamps.
- Store timestamps consistently in UTC at the application/database boundary.
- Display timestamps in the authorized user's configured timezone; timezone conversion must not change stored event meaning.
- Server-controlled timestamps are authoritative for postings, audit events, security events, and workflow transitions.

Common domain timestamps include:

- `submitted_at`
- `approved_at`
- `completed_at`
- `cancelled_at`
- `reversed_at`
- `occurred_at`
- `captured_at`
- `reviewed_at`
- `started_at`
- `ended_at`
- `retention_until`
- `deleted_at`, only where soft deletion is approved

Append-only records that must never change should normally use `created_at` or a domain timestamp such as `occurred_at` and omit `updated_at`. If ingestion time and business occurrence time differ, store both with explicit names. Never rewrite an occurrence time to conceal a late or corrected posting.

## 8. Monetary and Decimal Standards

- The system currency is AED unless a separately approved multi-currency module changes this rule.
- Use fixed-precision decimal database columns and decimal-safe PHP calculations. Never use `FLOAT`, `DOUBLE`, JavaScript number arithmetic, or PHP binary floating-point for authoritative money, cost, tax, or valuation calculations.
- Authoritative totals are always calculated and validated on the server. Browser calculations are estimates or presentation only.

Approved precision baseline:

| Value | Database precision |
|---|---:|
| Unit cost | `DECIMAL(15,4)` |
| Weighted-average cost | `DECIMAL(15,4)` |
| Selling price | `DECIMAL(15,2)` |
| Line total | `DECIMAL(15,2)` |
| Document total | `DECIMAL(15,2)` |
| Tax rate or percentage | Explicitly documented per field; no implicit default |
| Product quantity | Integer unless fractional products are approved later |

The ERP rounding policy is:

- Internal unit costing and weighted-average calculations round half-up to four decimal places at the approved calculation boundary.
- AED presentation, line totals, and document totals round half-up to two decimal places.
- Intermediate calculations retain sufficient decimal precision until the defined rounding boundary.
- Allocation residuals are assigned by one documented deterministic rule so line totals reconcile exactly to document totals.
- Rounding behavior is centralized and covered by unit and reconciliation tests.

This domain-specific precision is the approved project standard and `SYSTEM_ARCHITECTURE.md` must use the same values. Existing Product fields remain unchanged for now. Their precision, casts, statuses, and SKU generation will be handled only through a separately approved Product and inventory/purchasing remediation plan that preserves existing data.

## 9. Quantity Standards

Current operational inventory balances exist only in `product_inventories`. The recommended balance fields are:

- `available_quantity`
- `reserved_quantity`
- `damaged_quantity`

Required invariants are:

```text
available_quantity >= 0
reserved_quantity >= 0
damaged_quantity >= 0
reserved_quantity <= available_quantity
sellable_quantity = available_quantity - reserved_quantity
physical_quantity = available_quantity + damaged_quantity
```

For these definitions, `available_quantity` represents non-damaged physical stock including units currently reserved. `sellable_quantity` is the unreserved portion. If the domain later chooses available to mean immediately sellable stock, these names and formulas must be revised together before implementation.

- Quantities are integers because current products are indivisible laptops and electronics.
- Introduce fractional quantities only with separate approval of precision, units of measure, conversions, rounding, and inventory rules.
- Do not duplicate current stock quantities in `products`, purchase items, orders, report snapshots, dashboard tables, or integration tables.
- Purchase and order line quantities describe a document, not the current balance.
- Historical before-and-after snapshots may be stored for audit and diagnostics, but they must never become an alternative operational balance.
- All changes to `product_inventories` must be made by Inventory Services in the same transaction as immutable `stock_movements`.

## 10. Enum Standards

- Use PHP backed enums for controlled business states and types.
- Store stable, documented string values in the database, normally lowercase `snake_case` values independent of display labels.
- Do not persist translated text or display labels such as `Owner`, `In Progress`, or `Damaged Return` as enum identities.
- Prefer string columns plus PHP enums and validation over database-native enum columns when workflow values may evolve across SQLite and MySQL.
- Database check constraints may reinforce stable values when cross-engine behavior is tested and approved.

Enum candidates include:

- Purchase statuses
- Stock movement types
- Inventory sections or stock states
- Task statuses
- Priorities
- Return conditions
- Warranty statuses
- Chat conversation types
- Screenshot review statuses
- Employee roles

The approved Employee role enum has PHP cases `Owner`, `Admin`, `Manager`, and `Staff`, backed by stable stored values `owner`, `admin`, `manager`, and `staff`. A future approved migration must safely map the existing title-case database values to the backed values without losing or misclassifying existing data. User authorization remains Policy/Gate based and must not be implemented through scattered display-label comparisons.

Transitions between enum states are enforced by Services and Policies, not only by Filament forms or database acceptance. Every enum defines stable stored values, allowed transitions, terminal states, labels, and migration strategy before implementation.

## 11. Boolean and Status Standards

- Use booleans only for genuine yes/no conditions with an unambiguous meaning.
- Boolean columns use positive names such as `is_default`, `is_active`, and `requires_review`.
- Avoid negative booleans that require double-negative logic.
- Use a backed enum or documented status string for workflows with more than two states.

Avoid vague columns such as:

- `status` without a documented enum or explicit binary replacement such as `is_active`
- `type` without a documented enum
- `flag`
- `value`
- `data`

Every status column must identify its enum, initial state, allowed transitions, terminal states, authorization, timestamp and actor requirements, and cancellation or reversal behavior.

## 12. Index Standards

Every migration plan must identify required indexes before implementation and connect each composite index to an expected query or integrity constraint.

Index:

- Foreign keys
- Unique business references
- Frequently filtered workflow statuses
- Dates used for reporting and retention
- Polymorphic source columns
- Employee and task relationships
- Warehouse/product balance combinations
- Conversation/message ordering combinations
- Screenshot employee/session/capture-time combinations

Expected examples include:

- Unique `warehouse_id, product_id` on `product_inventories`
- Unique `stock_purchase_id, product_id` when duplicate product lines are prohibited
- `warehouse_id, product_id, occurred_at` on `stock_movements`
- `conversation_id, created_at` on `chat_messages`
- `employee_id, captured_at` on `employee_screenshots`
- `task_id, created_at` on `task_activities`
- `source_type, source_id` on polymorphic source lookups

Column order must match common equality filters and sort/range behavior. Do not add speculative or redundant indexes without understanding their write, storage, maintenance, and query-planning cost. High-volume modules must verify representative query plans on MySQL.

## 13. Unique Constraint Standards

Application validation improves error messages, but database uniqueness is the final safeguard for data integrity and concurrency.

Use unique constraints for:

- Document references
- User-to-Employee one-to-one linkage
- Warehouse/product inventory balances
- Duplicate purchase product lines where one line per product is the approved rule
- Duplicate opening-stock product lines
- Purchase completion movement identity
- Reversal processing identity
- Idempotency keys
- External marketplace identifiers within their source channel
- Message client identifiers where offline retry is supported

Uniqueness must include tenant, warehouse, source, channel, year, or other scope columns when the business rule is scoped rather than global. Nullable uniqueness semantics differ between engines and must be tested. Services must catch unique violations and return a safe idempotent result or a meaningful business conflict.

## 14. Polymorphic Relationship Standards

Polymorphic relationships may be used for:

- Activity subjects
- Stock-movement sources
- Attachments
- Comments
- Notifications
- Links from tasks to business records
- Links from chat conversations to approved business context

Risks include:

- Weaker database-level referential integrity
- Stored morph-class breakage when PHP classes or namespaces change
- More complex joins, indexes, cleanup, authorization, and reporting

A Laravel morph map is mandatory so stored type values remain short, stable domain identifiers if PHP namespaces change. Morph values are treated like persisted enum values and require a migration plan before renaming.

Use explicit foreign keys instead when the relationship is fixed, integrity-critical, frequently queried, or central to financial and inventory meaning. Polymorphism must never be used merely to avoid designing an important relationship.

## 15. Immutable Ledger Standards

The following records should normally be append-only:

- `stock_movements`
- Inventory activity and valuation events
- `task_activities`
- Financial journal entries and lines
- `screenshot_access_logs`
- Security and compliance audit events

Immutable records:

- Must not expose edit, delete, bulk-edit, restore, or inline-edit Actions in Filament
- Must be protected against update and delete by Policies and Services
- Should record the acting User or explicit system actor and authoritative occurrence time
- Should retain source type, source identifier, correlation or group identifier, and reason where required
- Use correction, reversal, or superseding records instead of mutation
- Must be included in reconciliation and integrity processes
- Must not be removed through parent cascades

Draft documents may be mutable, but their posted ledger consequences are immutable. If database-level immutability controls are proposed, their portability and emergency recovery procedure require separate review.

## 16. Audit Metadata Standards

Important business documents include actor fields where the workflow justifies them:

- `created_by`
- `submitted_by`
- `approved_by`
- `completed_by`
- `cancelled_by`
- `reversed_by`
- `reviewed_by`

These columns reference Users because User is the authenticated identity. Associated Employee data can be resolved through the one-to-one relationship, while the User reference preserves the security actor.

Audit payloads must exclude:

- Passwords and password hashes
- Tokens and API secrets
- Session identifiers and payloads
- Payment credentials
- Private authentication information
- Private file content or reusable private URLs

Generic activity logs must either exclude owner-only financial fields or protect the complete payload with owner-only authorization. Sensitive values must not leak through old/new snapshots, exceptions, exports, notifications, or debug context.

## 17. Activity Log Standards

Use a project-owned activity logging design until installation of any logging package is separately approved.

Activity records should include:

- `actor_id`, referencing `users.id` or a clearly represented system actor
- Stable `event` value
- `subject_type`
- `subject_id`
- Sanitized `old_values`
- Sanitized `new_values`
- IP address where appropriate and lawful
- User agent where appropriate and lawful
- `occurred_at` or `created_at`
- Correlation/request identifier where useful
- Reason or source channel where required

Activity logging for critical business changes should occur in the same transaction where possible so the business change and its required audit evidence succeed or fail together. After-commit delivery may build non-authoritative projections, but it must not be the only record of a critical mutation.

Activity records are append-only, access-controlled, retention-governed, and sanitized before storage. Logging must not become an unrestricted copy of entire database rows.

## 18. Migration Standards

- Never modify an existing migration that has already been applied or shared.
- Create a new migration for every schema change.
- Migration filenames and class intent must clearly describe the change.
- Every migration must be reviewed before execution.
- Explain the schema, data, locking, deployment, rollback, and compatibility impact before approval.
- Provide safe `down()` methods where practical.
- Document destructive `down()` behavior carefully; reversibility must not imply that production history may be safely destroyed.
- Never use `migrate:fresh`, `db:wipe`, destructive reset commands, or equivalent operations without explicit approval.
- Do not rename or remove important columns without a data migration, compatibility period where required, validation, backup, and rollback/recovery plan.
- Separate large or long-running data migrations from schema migrations where practical.
- Production-required records use an approved idempotent data migration or deployment process, not an uncontrolled development Seeder.
- Avoid slow external calls and business Service execution inside schema migrations.
- Consider SQLite and MySQL behavior for column alterations, indexes, constraints, defaults, timestamps, JSON, and enums.

Existing migrations are historical artifacts. A new standard never authorizes silently rewriting them.

## 19. Seeder and Factory Standards

### Seeders

- Required reference-data Seeders must be idempotent.
- Seeders must not overwrite production transactional data.
- Seeders must never create insecure default passwords or shared employee accounts.
- Development/demo data and required production reference data must use separate, clearly named flows.
- Production Seeders or data deployments require explicit approval, stable keys, safe reruns, and an audit/recovery plan.

### Factories

- Factories support automated tests and generate valid relationships and state combinations.
- Named states represent meaningful workflow stages such as draft, approved, completed, damaged, or inactive.
- Defaults should be minimal and realistic.
- Factories must not hide invalid business behavior through callbacks that bypass Services or create impossible state combinations.
- Tests for invalid behavior should override inputs intentionally and assert rejection.

## 20. Transaction Standards

Use database transactions for every multi-record critical workflow, including:

- Purchase submission
- Purchase approval
- Purchase completion
- Purchase reversal
- Opening stock
- Reservations
- Reservation release and fulfilment
- Returns
- Damaged-stock and Safe-T transfers
- Stock adjustments
- Warranty transitions affecting stock
- Accounting posting and reversal
- Task completion workflows when multiple records change
- Required audit entries associated with those changes

Transactions must remain short. Validate immutable input before acquiring locks where safe, lock only necessary rows, use a consistent lock order, and commit before dispatching ordinary notifications or slow work.

Do not perform external API calls, email delivery, large file processing, private-storage network calls, or other slow network operations while database locks are held. Use an approved after-commit event, outbox pattern, or idempotent Job when an external effect follows a committed transaction.

## 21. Locking and Concurrency Standards

### MySQL

- Use InnoDB.
- Use Eloquent `lockForUpdate()` for authoritative documents and inventory balances during critical mutations.
- Lock records in a documented consistent order, normally by stable primary key, to reduce deadlocks.
- Use unique constraints in addition to locks for final idempotency and identity guarantees.
- Keep lock duration short and queries indexed.
- Detect deadlocks and retry only transactions proven safe and idempotent, with bounded attempts.
- Use idempotency keys for external requests, imports, webhooks, desktop-agent uploads, and retried operations.

### SQLite

- Do not claim row-level concurrency guarantees from SQLite tests.
- Keep ordinary functional tests SQLite-compatible where practical.
- Do not weaken production locking merely to make SQLite simulate behavior it does not provide.
- Run genuine concurrency, deadlock, isolation, and duplicate-posting tests against MySQL before approving a concurrency-sensitive module.

Service tests must cover both optimistic business checks and final database safeguards. Locks do not replace non-negative-stock constraints, idempotency, or reconciliation.

## 22. Reference Sequence Standards

- Generate Employee and business-document references on the server through one approved concurrency-safe sequence Service backed by a sequence table or another database-atomic reservation strategy.
- Never generate references using table row counts, `max(id) + 1`, or “latest record plus one.”
- Use an approved sequence table, atomic database reservation, or another strategy that guarantees uniqueness under concurrent transactions.
- Store a unique constraint on the final reference.
- References are immutable after assignment and never reused, including after cancellation, reversal, failed posting, or deletion of an eligible draft.
- Define whether gaps are acceptable; a promise of gapless numbering has significant locking and legal implications and requires explicit approval.

The sequence mechanism will cover at least:

- Employee references
- Purchase references
- Opening-stock references
- Reversal references
- Sales and marketplace order references
- Task references
- Other approved business-document references

Employee references preserve the existing compatible `TPZ-####` format. Only the unsafe latest-record/max-plus-one allocator changes; the replacement must be concurrency-safe, unique, non-reusing, and must leave any existing Employee reference unchanged. Employee references do not switch to an `EMP-{YEAR}-...` format.

Recommended formats are:

- `PO-{YEAR}-{SEQUENCE}`
- `OS-{YEAR}-{SEQUENCE}`
- `PR-{YEAR}-{SEQUENCE}`
- `RSV-{YEAR}-{SEQUENCE}`
- `SO-{YEAR}-{SEQUENCE}`
- `RET-{YEAR}-{SEQUENCE}`
- `WAR-{YEAR}-{SEQUENCE}`
- `TASK-{YEAR}-{SEQUENCE}`

Purchase Reversal uses the approved `PR-{YEAR}-{SEQUENCE}` prefix. References must never be reused.

Final prefixes, digit widths, year/timezone boundary, sequence scope, assignment point, and gap policy remain subject to business approval.

## 23. JSON Column Standards

- Use JSON only for genuinely flexible, secondary metadata where normal relational columns are not appropriate.
- Do not store core searchable, sortable, constrained, financial, inventory, identity, authorization, or relationship data inside JSON.
- Do not use JSON to avoid creating a required related table.

Every JSON column must document:

- Expected schema and data types
- Allowed and required keys
- Sensitive-data restrictions
- Server-side validation
- Query and index requirements
- Versioning and backward-compatibility considerations
- Retention and sanitization behavior

JSON payloads use stable keys and a documented schema version where their shape can evolve. Cross-engine query behavior must be verified before JSON is used in operational filters or reports.

## 24. File Metadata Standards

- Store files using an approved Laravel storage disk.
- Store metadata and private opaque paths in the database; do not store large binary files in normal relational columns.
- Do not trust original filenames as storage paths.

File metadata may include:

- `original_name`
- `storage_disk`
- `storage_path`
- `mime_type`
- `size`
- `checksum`
- `uploaded_by`, referencing the User actor
- Related model reference
- `retention_until`
- `created_at`

Screenshot files always use private storage and separate server-side authorization. Every screenshot view, download/export, review, retention change, legal hold, and deletion must be auditable. Metadata-to-object reconciliation must detect missing and orphaned files without silently deleting or rewriting either side.

## 25. Chat Database Standards

Chat tables are separate from task tables. Likely entities are:

- `chat_conversations`
- `chat_conversation_members`
- `chat_messages`
- `chat_message_reads`
- `chat_attachments`

Requirements include:

- Stable conversation identity and membership history
- Explicit membership periods or immutable membership events where required
- Sender and server timestamp indexes
- Per-member read/unread tracking
- Message retention and authorized correction/deletion policy
- Private attachment authorization
- Idempotent client message identifiers for offline retry where supported
- No storage of chat messages directly inside tasks
- Optional, controlled links to tasks or business records without duplicating their data
- Server-side conversation membership authorization for every query and download

Stored messages remain authoritative when real-time delivery is delayed or reconnects. Do not finalize the chat schema, install broadcasting infrastructure, or select a real-time transport without a separate approved architecture and implementation plan.

## 26. Task and Timeline Database Standards

Likely entities are:

- `tasks`
- `task_assignees`
- `task_checklist_items`
- `task_dependencies`
- `task_comments`
- `task_attachments`
- `task_activities`

The `task_activities` timeline is append-only. It records the actor, event, occurrence time, source, sanitized context, and task reference. Corrections append a new event instead of changing history.

Current task status and progress may exist on `tasks` for efficient operational queries. The timeline explains how that state was reached, and reconciliation must detect divergence between current state and the latest valid events.

Assignment, dependency, checklist, progress, completion, reopening, and due-date rules are implemented by Services. Filament, APIs, imports, and Jobs must not arbitrarily update progress or terminal states. The exact progress model—percentage, checklist, milestones, or another approach—requires business approval.

## 27. Employee Monitoring Database Standards

Keep device, work-session, activity-summary, screenshot, review, acknowledgement, and access-log concerns separate. Likely entities are:

- `employee_devices`
- `employee_work_sessions`
- `employee_activity_summaries`
- `employee_screenshots`
- `screenshot_reviews`
- `screenshot_access_logs`
- `monitoring_acknowledgements`

Requirements include:

- No camera or microphone data
- No intentional password, credential, token, or private authentication capture
- Private opaque file paths
- A defined `retention_until`
- Audits for access, export, retention change, legal hold, and deletion
- Employee explanation or dispute fields with their own immutable review history where appropriate
- Enrolled desktop-agent and device identity
- Upload-session/client idempotency keys
- Monitoring only during authorized Employee Work Sessions
- Clear server timestamps and separately recorded device capture timestamps
- Permission and privacy separation between supervisors, administrators, and owner-level reviewers

Do not implement monitoring until policies, employee notice or acknowledgement, permissions, retention, private storage, capture exclusions, review rules, and desktop-agent security are separately approved. The Windows desktop agent is responsible for actual capture; Laravel manages policy, upload authorization, metadata, access, retention, and audit.

## 28. Reporting and Reconciliation Standards

Operational balance/projection tables and immutable ledgers serve different purposes. Projections support efficient operations; ledgers preserve authoritative history. Reconciliation compares them and reports discrepancies.

Provide diagnostic reconciliation for:

- Product inventory balances versus stock movements
- Reserved balances versus active reservations
- Purchase document totals versus purchase-item totals
- Task current status/progress versus the latest valid timeline state
- Screenshot metadata versus private storage objects
- Accounting balances and summaries versus immutable journal entries
- Posted source documents versus their expected idempotent stock or financial entries

Reconciliation output records run time, scope, rule version, counts, discrepancies, and correlation references. Access follows module and owner-only financial authorization.

Reconciliation must never silently rewrite balances, movements, timelines, file metadata, or journals. Repairs require a separately authorized correction or reversal workflow with audit evidence.

## 29. Data Privacy and Retention

Classify data before collection as:

- Operational
- Financial
- Employee
- Monitoring
- Security
- Compliance

Each class requires documented ownership, lawful/business purpose, authorized roles, collection scope, storage protection, export rules, retention period, deletion or anonymization method, backup behavior, and audit obligations.

Define and approve retention rules before collecting sensitive employee monitoring data. Collect only the minimum approved information. Support authorized retention or legal holds where required, including reason, approver, scope, start, review, and release evidence.

Deletion and anonymization must preserve required financial, inventory, security, and audit integrity. Personal fields may be anonymized when legally and operationally permitted, while immutable transactions retain non-sensitive stable references needed for reconciliation. Backups and replicas must follow an approved expiry and recovery policy.

## 30. Database Review Checklist

Before approving any migration, verify:

- [ ] Is the table or column necessary?
- [ ] Is there one clear source of truth?
- [ ] Are relationships explicit and constrained where practical?
- [ ] Are nullability and delete rules safe and documented?
- [ ] Are money fields fixed precision with the approved rounding policy?
- [ ] Are quantity definitions and invariants explicit?
- [ ] Are indexes justified by queries or constraints?
- [ ] Are required unique constraints present?
- [ ] Are workflow states backed by stable PHP enums?
- [ ] Are transitions enforced in Services and Policies?
- [ ] Is record, field, export, file, and owner-only authorization considered?
- [ ] Is immutable and audit history preserved?
- [ ] Is an idempotency key or unique posting identity required?
- [ ] Are transaction and lock order documented?
- [ ] Are SQLite/MySQL differences considered?
- [ ] Are functional, policy, integrity, and MySQL concurrency tests planned?
- [ ] Is data migration separated where practical?
- [ ] Is a rollback, reversal, or operational recovery plan documented?
- [ ] Has execution received separate explicit approval?

## 31. Existing Project Observations

These observations are factual findings from a read-only repository inspection on 2026-08-06. They describe files currently present in the working tree; they do not assert that every migration has been executed. The Product model and Product migration were already untracked before this document was created.

### Current platform and database configuration

- `composer.lock` contains Laravel Framework `v13.23.0`; `composer.json` requires `laravel/framework` `^13.8` and PHP `^8.3`.
- `.env` and `.env.example` currently select `DB_CONNECTION=sqlite`.
- `config/database.php` defaults to SQLite when no connection is supplied and includes a MySQL connection definition with its engine option currently `null` rather than explicitly set to InnoDB.
- `phpunit.xml` uses in-memory SQLite with `DB_DATABASE=:memory:` for standard automated tests.

### Existing tables represented by migrations

- Laravel framework/support tables: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, and `failed_jobs`.
- Current ERP tables: `teams`, `employees`, and `products`.
- No migration files currently define suppliers, warehouses, purchases, `product_inventories`, `stock_movements`, orders, returns, warranty, Safe-T, tasks, chat, work sessions, monitoring, screenshots, accounting, or project-owned audit logs.

### Existing keys and indexes

- `users.email`, `employees.employee_id`, `employees.email`, `products.sku`, and `failed_jobs.uuid` are unique.
- `employees.team_id` is an explicit nullable foreign key with `nullOnDelete()`.
- `sessions.user_id` is indexed but its migration does not add a foreign-key constraint.
- Framework cache, session, queue, and failure tables include their standard primary and supporting indexes.
- No current ERP migration defines composite business indexes or idempotency constraints.

### Existing models, casts, and relationships

- `User` is the only `Authenticatable` model. It casts `email_verified_at` to datetime and `password` as hashed.
- `Employee` extends the base Eloquent Model, defines a `belongsTo(Team::class)` relationship, and hashes its own `password` using an Attribute mutator.
- No User-to-Employee relationship or unique `user_id` exists in the inspected model and migration files.
- `Product` casts `cost_price` to `decimal:2`; no cast is defined for `selling_price`.
- `Team` and Product have no inspected Eloquent relationships. Team has no inverse employees relationship in its current model.
- Employee and Team boolean `status` fields do not have inspected boolean casts.

### Existing naming and financial fields

- Tables and most columns use plural table names and `snake_case`, consistent with the general naming standard.
- `teams.status` and `employees.status` are booleans with a vague workflow-style name rather than `is_active`.
- `employees.role` is a database-native enum storing display-style title-case values: `Owner`, `Admin`, `Manager`, and `Staff`.
- `products.condition` and `products.status` store title-case/default display values in unconstrained strings.
- Products use `name`, while the approved project context describes products by title and SKU; the canonical field name requires a business decision before the Product module is finalized.
- `products.warranty` is an integer defaulting to 12. The approved meaning is an unsigned number of months: `12` means 12 months. UI formatting may display “12 months” or “1 year”; mixed text values such as “1 Year” must not be stored.
- `products.cost_price` and `products.selling_price` use `DECIMAL(10,2)`. The approved future standard is `DECIMAL(15,4)` for unit cost and `DECIMAL(15,2)` for selling price, but the existing table is not changed now.
- The Product model's fillable list includes `cost_price` but currently omits `selling_price` and `warranty`, even though the migration defines both.
- No `average_cost` field exists. The existing `cost_price` must remain distinct from weighted-average inventory cost.

### Existing reference generation

- Employee creation generates `employee_id` by reading the latest Employee and incrementing the suffix.
- Product creation generates SKU using `max(id) + 1` when the SKU is blank.
- Both approaches can race under concurrent creation and do not conform to the sequence standards in this document.

### Approved remediation decisions

- User remains the only authentication model. Employee will receive a nullable unique `user_id` through a staged, separately approved Phase 1 migration that preserves the current Filament login and initially retains Employee email and password columns.
- Employee roles will use a PHP backed enum with `Owner`, `Admin`, `Manager`, and `Staff` cases and a safe mapping plan for existing data.
- Employee and business-document references will use a shared concurrency-safe sequence Service or atomic sequence table strategy and will never be reused. Employee output remains compatible with `TPZ-####`; document sequences may be scoped separately by type/year.
- `products.name` remains the canonical database field. It will not be renamed to `title`; Filament may label it “Product” or “Product Title.”
- Product warranty is an unsigned integer count of months.
- SQLite remains the local-development and standard-test database. MySQL with InnoDB remains the production and genuine-concurrency-test target. Current configuration is not changed now.
- Unit and average cost use `DECIMAL(15,4)` with four-decimal half-up internal costing. Selling prices, lines, documents, and AED presentation use `DECIMAL(15,2)` with two-decimal half-up rounding.
- Current Product precision, casts, statuses, and SKU generation remain unchanged until a separately approved remediation during the Product and inventory/purchasing phases. Existing data must be preserved.
- Broad legacy cleanup is prohibited. Each item is handled only in its classified phase or through separate business approval.

### Conflict and decision register

| Observation | Classification | Required resolution or decision |
|---|---|---|
| Employee stores separate email and password but has no one-to-one link to User | **Phase 1 authentication remediation** | Add nullable unique `employees.user_id` through an approved staged migration; keep User as the only authentication model, preserve Filament login, and retain Employee credentials until later verified removal is separately approved. |
| Employee role is a database-native enum with title-case display values | **Phase 1 authentication remediation** | Introduce the approved PHP backed role enum and safely map existing Owner/Admin/Manager/Staff data. Do not edit the existing migration. |
| Employee reference generation uses latest-record incrementing | **Phase 1 authentication remediation** | Preserve `TPZ-####` output and existing references while replacing only the allocator with the approved concurrency-safe non-reusing sequence mechanism. |
| Product SKU generation uses `max(id) + 1` | **Product remediation** | Replace it with the approved concurrency-safe sequence strategy during the Product remediation phase; do not change it now. |
| Product has `DECIMAL(10,2)` cost and selling fields | **Inventory/purchasing remediation** | Preserve current data and use a separately approved migration to adopt `DECIMAL(15,4)` unit cost and `DECIMAL(15,2)` selling price where required. |
| Product database field is `name` | **Accepted legacy structure** | Keep `products.name`; Filament may label it “Product” or “Product Title.” No rename is planned. |
| Product warranty column does not state its unit | **Product remediation** | Treat and validate it as an unsigned integer number of months; plan any constraint/cast remediation separately without mixed text values. |
| Product status and condition values are vague or display-oriented | **Product remediation** | Define stable Product enums and safe value mapping during the separately approved Product remediation. |
| Employee boolean `status` is vaguely named and lacks an inspected cast | **Phase 1 authentication remediation** | Define the active-login/employment meaning and safe cast or schema plan as part of the staged User/Employee work. |
| Team boolean `status` is vaguely named and lacks an inspected cast | **Separate business approval required** | Define Team lifecycle behavior before proposing any Team cleanup; do not change it as part of unrelated work. |
| Product fillable/casts do not cover every existing financial/warranty column | **Product remediation** | Review authorized inputs, warranty casting, money casts, and field protection without broadly mass-enabling sensitive fields. |
| Team has only the Employee-to-Team relationship, no inverse model relationship | **Accepted legacy structure** | Add an inverse relationship only if an approved Employee/Team workflow needs it; no cleanup is required now. |
| Sessions `user_id` is indexed without a database foreign key | **Accepted legacy structure** | Preserve the Laravel framework structure unless a separately approved authentication/session plan justifies changing it. |
| Framework cache, queue, password-reset, and session naming differs from ERP domain naming examples | **Accepted legacy structure** | Preserve Laravel framework conventions. |
| Local runtime and standard tests use SQLite while production targets MySQL/InnoDB | **Accepted legacy structure** | Continue the current setup. A MySQL test environment and configuration require a later approved task before concurrency verification. |
| Product cost precision differs from the now-aligned documentation standard | **Inventory/purchasing remediation** | Do not change the Product table now; remediate safely with preservation and rounding tests during the approved phase. |
| No product inventory, stock movement, purchase, or inventory audit tables exist in inspected migrations | **Inventory/purchasing remediation** | Design them one approved module at a time; their absence does not authorize schema creation. |
| Tables for the other planned ERP modules do not yet exist | **Separate business approval required** | Design each module only after its own business and implementation approval. |
| Exact sequence table schema, prefix widths, year boundary, and gap policy are not finalized | **Separate business approval required** | Present these choices in the relevant implementation plan before creating the sequence schema or Service. |

No existing structure is silently changed or retroactively declared migrated by this document.

## 32. Approval Rules

Database implementation requires separate explicit approval for the relevant module and migration plan.

Creating or approving this documentation does not by itself authorize:

- New migrations
- Editing existing migrations
- Running migrations or other database execution
- Data migration, backfill, seeding, or correction
- Package installation
- `.env` or database configuration changes
- Authentication or User/Employee changes
- Production deployment
- Destructive database or filesystem actions

Before implementation, present the proposed tables and columns, keys, constraints, indexes, enum values, transaction and lock strategy, authorization, audit behavior, SQLite/MySQL differences, test plan, data transition, rollout, rollback or recovery plan, and exact changed files. Stop and wait for approval before making those changes.
