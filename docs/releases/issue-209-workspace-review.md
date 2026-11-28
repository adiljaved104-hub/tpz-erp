# Inventory and Sales Workspace — Issue 209

Base: Staging `bed7de3ef855288013c80f4e291c9efda8d2b2ed`. Development only; no deployment or Main promotion.

## Implemented

- One Inventory by Location tab replaces the redundant Inventory tab. Classic routes remain available. My Inventory counts and displays positive personal allocations, not company totals.
- Permission-scoped SKU/location rows, full wrapped clickable titles, Apply/Reset search/location/stock filters, authorized employee filter, filtered summary denominators and quantity breakdowns. Allocations are never added to physical totals.
- Existing financial grants control cost/value queries and the existing received-GRN purchase-cost-history view. Business dates and valuation services are unchanged.
- Scoped reservation rows, required-reason full Sales order release, original-account allocation release, upgrade reservation bundle support, atomic rollback and repeat-confirmation protection. Orders return to Draft; reservation cycles preserve released history and allow a later authorized Reserve.
- Release is not offered for transfer holds, employee allocations, fulfilled/consumed stock, Web Sales manual-sourcing orders or unrelated employee orders. Existing specialized workflows remain in place. Partial line release is intentionally not introduced because it would leave an incomplete order reservation bundle.
- Existing platform-scoped external identity hash, database uniqueness and shared OrderService validation remain the submit authority. The common OrderForm now gives a scoped inline duplicate warning, including the Sales Workspace modal/page. Hidden orders produce generic warnings. The actual OrderService create paths remain centralized. Web Sales creation currently accepts no external identifier; it is not given a fabricated marketplace identity. URLs remain conservatively literal (trim/case normalization), not merged by guessed tracking parameters or extracted IDs.
- Read-only allocation events and physical receipt status, explicitly labelled as pooled provenance rather than serial/lot identity or extra spendable stock.
- Explicit Owner-only brand/product responsibility-and-stock handoff with preview, required reason/review, stable snapshot comparison, row locks, idempotency, reference preallocation and existing ledger helper reuse. Reserved/transfer-held, unreconciled, inactive, damaged/QC and marketplace stock blocks the entire transition. Broader category-only or quantity-only transitions retain their existing reviewed workflows; no silent automatic transfer is added to ordinary scope edits.

## Safety / review limits

- No migrations or Composer lock changes.
- Historical orders, invoices, purchases, posted accounting and stock movements are not rewritten. A handoff appends new allocation events; physical totals stay unchanged.
- Independent existing grants can still permit old employees to see records after a handoff; this action does not erase historical access or silently override other responsibilities.
- No Ahmad Ali live correction is embedded in this feature. Its private per-SKU/permission preflight and backup evidence are outside the public repository and require a separate final release approval.
- Owner UI screenshots use actual rendered components with clearly labelled synthetic in-memory records. They are not live/UAT balance screenshots. Browser review server is GET-only and exposes no operational actions.

## Validation

### Owner visual/review follow-up

- Compact one-row metrics, horizontal filters, explicit Quick View, title-first wrapping, separate financial columns and grouped Product Details. The Tailwind source scan now includes the Livewire views; scoped CSS does not alter Classic tables.
- Reserved quantities open the reservation section; Release still covers eligible complete regular-Sales bundles only and explicitly returns the order to Draft.
- The real shared Sales Order form includes a scoped Duplicate Order Detected notice and authorized existing-order link. Submit-time platform identity and database uniqueness remain authoritative.
- Responsibility handoff allocation events now contain the actual Owner-entered handoff reason, not the unrelated Stock Request reason. Original Stock Request callers retain their default reason.
- Two navigation assertions were stale after intentional tab/personal-ownership changes: the old inventory alias now routes to Locations; the My Inventory fixture now has an actual personal balance. Both are included in focused CI.
- Accounting B2's `ai_assistant.manage_settings` registry reconciliation failure is already present in the pinned Staging registry, which this PR does not change. It is a separate baseline issue, not an accounting regression introduced here; no permission assertion is disabled or weakened.

Dedicated non-deploying PHP 8.3 / MySQL 8.4 workflow checks changed-file syntax/Pint, locked dependencies, Vite build, additive schema baseline and Blade, plus focused workspace, Sales identity, responsibility, allocation and upgrade tests. Separate non-transactional MySQL tests keep the production reference guard enabled and race actual duplicate-create, order-release and brand-handoff requests. No destructive reset commands or operational databases are used.
