# Manual Sourcing, Mixed Fulfilment, and Marketplace-Loss Architecture

## Current boundary

Existing warehouse fulfilment remains the source of truth for physical stock, reservations, stock movements, allocation ledgers, COGS, returns, and invoice snapshots. Existing `order_fulfillments` and `order_fulfillment_items` are immutable and require warehouse inventory; they must not be repurposed for supplier-direct lines.

## Additive model

Introduce an immutable `order_line_fulfillments` table keyed by `order_item_id`, `source_type`, and an idempotency key. A row records the source (`warehouse`, `manual_receipt`, or `supplier_direct`), requested/fulfilled quantity, linked reservation or GRN where applicable, supplier reference, unit cost/currency snapshot, evidence reference, actor, timestamps, and `accounting_status` (`posted` or `pending`). Supplier-direct rows never receive a warehouse inventory or stock-movement foreign key.

Introduce append-only `order_line_fulfillment_events` for dispatch, delivery, exception, cancellation, and reconciliation events. Partial delivery is represented by event quantities and a locked cumulative total; retries return the existing idempotent event.

Marketplace loss keeps the existing transfer-loss incident table and adds a separate immutable reconciliation record linked to the destination inventory, allocation account, loss incident, and stock movement. Inbound shortage remains a transfer item shortage and cannot create a post-receipt loss record.

## Workflow

1. Manual Web Sales creation materializes or matches the catalogue product and stores immutable price/description snapshots. No reservation, GRN, or stock is created.
2. Warehouse-source lines become reservable only after a posted GRN is linked and the cumulative accepted quantity covers the line quantity.
3. Supplier-direct lines record authorized dispatch and delivery evidence in line-fulfilment events. They never post a GRN or warehouse stock movement.
4. Mixed orders reserve only warehouse-backed lines. Supplier-direct lines are completed through the additive line-fulfilment records.
5. Order completion requires every line’s cumulative fulfilled quantity to reach its requested quantity. Cancellation releases only active warehouse reservations.
6. A post-receipt marketplace loss validates destination inventory and allocation ownership, posts one immutable loss movement, and records a pending accounting obligation if an accounting posting is unavailable. Reimbursement changes the claim/accounting state only; it never restores physical stock.

## Migration and rollback policy

All schema changes are forward-only additive migrations with explicit short constraint names for MySQL. Existing migrations and historical rows are not rewritten. Rollback is limited to empty newly-created tables; once immutable events exist, rollback is refused.

## Required validation

The implementation must run Linux PHPUnit and disposable MySQL 8.4 tests for mixed orders, multiple GRNs, supplier-direct partial delivery, reservation/dispatch retries, marketplace-loss reconciliation, claims, cancellation, authorization, and legacy warehouse orders. Migration-only checks are insufficient.
