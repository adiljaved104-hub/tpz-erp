# Temporary Renewed QC → Sellable Exception (inventory-entry period)

Status: code-only feature; **disabled by default**. Requires staging UAT and Owner approval before Main/UAT.

## Business scope

On **Quality Control → Purchase Stock QC**, an explicitly authorized employee may release a chosen quantity of **unstarted Renewed GRN stock** from QC Pending into Sellable, without creating any QC inspection, certificate, or device. The original Purchase Receipt and its received quantities remain intact. This affects **inventory intake only**: existing Renewed order shipment and marketplace-transfer QC gates remain in force.

Every release includes a non-empty reason, idempotency key, actor and timestamp, an immutable bypass release record, stock movement, QC Pending bucket/value change, and original GRN employee allocation ledger entry. The stock balance and ledger are updated in one InnoDB transaction.

## Activation

Configuration defaults to disabled. To activate temporarily in **Staging only** (after tests, migration, and Owner approval), set:

```dotenv
RENEWED_QC_EXCEPTION_ENABLED=true
RENEWED_QC_EXCEPTION_EXPIRES_AT=2026-11-09T23:59:59+05:00
```

Then rebuild Laravel config cache on Staging. A missing, invalid or past expiry disables the action. To stop it immediately, set `RENEWED_QC_EXCEPTION_ENABLED=false` and rebuild config cache.

**Do not automatically turn on the setting when migrating or deploying.**

In Access Control, grant the named Renewed employees:
- Quality Control: **View**, **Start**, **Release Without QC** (sensitive permission).
- Inventory: **View**, scoped to their authorized Product/Location Responsibility.

Do not grant broad permissions to a whole role. Owner/Admin have role-default QC permission only while the explicit global feature flag is active. Only employees with valid product/warehouse responsibility scope can release.

## Workflow

1. GRN is received and visible in Purchase Stock QC.
2. Authorized user opens **Move to Sellable — Temporary QC Exception** on an **unstarted** Renewed intake, enters a positive quantity not exceeding remaining QC intake and a reason (10–2000 characters).
3. User confirms the warning that no QC was performed, and that sale shipment QC enforcement remains unchanged.
4. System locks intake and warehouse inventory rows; checks category, permission, holder, unit cost and QC Pending quantity/value; writes immutable exception and stock/bucket/allocation records in one transaction.
5. Queue quantity falls by the bypassed amount. Original Purchase Receipt accepted count does **not** change. Operations reports show the no-QC stock movement type.
6. Disable setting after the inventory-entry exception period ends.

## Mandatory Staging tests

```sh
php artisan migrate --force
php artisan test --filter=RecordedRenewedStockQcIntakeTest
php artisan test --filter=RenewedQcDispatchTest
php artisan test --filter=InventoryAllocation
```

Verify in Staging:
- Default disabled; expired configuration blocks action.
- Authorized Owner/staff can release only in their assigned inventory scope.
- Existing QC-started units cannot use this exception.
- Idempotent retries do not duplicate balances.
- QC Pending units/value decrease by the exact amount; Sellable, average cost, stock movement, allocation balances and receipt allocation lines reconcile.
- No QC certificate or inspection appears.
- Renewed sales dispatch still **requires QC**; no implicit dispatch bypass.
- Unrelated products, tax invoices, real purchases/orders, HR and normal QC workflows remain unchanged.

**No deployment to Main/UAT without Owner's explicit Staging signoff.**
