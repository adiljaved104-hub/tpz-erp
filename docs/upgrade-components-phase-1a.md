# Upgrade Components — Phase 1A boundary

## Implemented foundation

- Components are `products` with `inventory_item_type = component` and a one-to-one `components` specification record.
- Component receipts reuse Purchase, Purchase Receipt, ProductInventory, StockMovement, and the existing four-decimal weighted-average costing service.
- `ProductInventory.average_cost` remains the acquisition-cost ledger. It is not an OEM recovery value.
- Central approved OEM recovery value lives on the component specification, defaults to zero, and may only be changed through the protected approval service with a reason and immutable audit event.
- Normal Product, Order, Web Sales, and Quotation selection paths exclude component subtype records. Purchase selectors deliberately include them and label them as components.

## Approved recovery accounting contract for later execution

- Unknown OEM recovery value is zero.
- Selling price and selling add-on are never recovery values.
- The execution snapshot will copy the central approved value (or a separately authorized recipe override) into the immutable Order upgrade execution.
- Returned component inventory value and the COGS recovery credit use the same snapshotted amount.
- Total recovery credit cannot exceed the base unit COGS.
- Product/recipe-specific overrides are deferred to the recipe phase and must record permission, reason, actor, and timestamp.

## Mixed configurations in one Order

Phase 1A intentionally does not alter Order lines. Phase 1C must remove product identity as the line identity before adding upgrade execution:

1. Add immutable positive `line_number` and stable `line_key` to `order_items`, backfilling existing rows deterministically without changing quantities, prices, status, or references.
2. Replace `orders + product_id` uniqueness with `orders + line_number` and `orders + line_key` uniqueness.
3. Change reservation, fulfillment, COGS, upgrade execution, quotation conversion, return, invoice, Web Sales, and report joins/idempotency keys to use `order_item_id`/line identity rather than `product_id` maps.
4. Preserve `product_id` as the physical base Product foreign key and permit multiple lines for that Product when their Sales Configuration snapshots differ.
5. Migrate and test existing Orders unchanged before enabling mixed configuration entry.

This permits, in one Order, separate 8GB/256GB, 16GB/512GB, and 32GB/1TB lines for the same physical base SKU without creating fake physical Product stock.
