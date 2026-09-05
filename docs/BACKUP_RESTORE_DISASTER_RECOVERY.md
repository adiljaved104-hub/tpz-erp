# ERP Backup, Restore, and Disaster Recovery

## Scope and recovery targets

This runbook covers the TPZ ERP database and recoverable application files. It does not replace infrastructure snapshots, encrypted off-server copies, or production monitoring.

- Initial recovery point objective (RPO): up to 24 hours with a successful daily backup.
- Initial recovery time objective (RTO): a few hours for a practiced database and storage restore.
- Increase backup frequency if losing up to one business day is unacceptable. The command is safe to schedule more frequently, subject to storage and database capacity.

## Commands

Create a complete backup:

```text
php artisan erp:backup
```

Optional modes:

```text
php artisan erp:backup --database-only
php artisan erp:backup --storage-only
php artisan erp:backup --retention-dry-run
```

Validate a completed backup without restoring it:

```text
php artisan erp:restore <backup-directory> --verify-only
```

Restore SQLite and storage to disposable targets for a rehearsal:

```text
php artisan erp:restore <backup-directory> \
  --target-database=<disposable-database-path> \
  --target-storage=<disposable-storage-directory>
```

The restore command intentionally has no implicit active target. It refuses to overwrite an existing target unless `--force` is supplied. `--force` must only be used with a path whose resolved target has been verified as disposable.

## Backup contents and location

The default backup repository is `storage/app/backups`. Production must set `ERP_BACKUP_PATH` to an absolute path on a durable volume outside the deployed release directory.

Each successful backup is finalized atomically as:

```text
<backup-root>/
  YYYY-MM-DD_HHMMSS/
    database.sqlite  (development SQLite)
    database.sql     (production MySQL)
    storage.zip
    manifest.json
```

The storage archive includes recoverable files from:

- `storage/app/public`: Company logo, Company stamp, dedicated login branding, and other public uploads.
- `storage/app/private`: private ERP documents and uploads.

The archive excludes `.gitignore` control files, Livewire temporary uploads, the backup repository, framework caches, sessions, compiled views, and application logs. Tax Invoice PDFs are generated from database snapshots and the snapshotted branding references; the referenced uploaded branding files are included. If future modules store durable files elsewhere, add that location explicitly to `config/backup.php` and add a focused inclusion test.

## Database behavior

### SQLite development

SQLite backups use `VACUUM INTO`, which produces a consistent independent database image without overwriting the active file. The command opens the completed image separately and requires:

- `PRAGMA integrity_check = ok`
- zero results from `PRAGMA foreign_key_check`
- a SHA-256 checksum recorded in the manifest

A normal file copy of a live SQLite database is not the approved operational backup method.

### MySQL 8 / InnoDB production

MySQL backups use the configured `mysqldump` executable with:

- `--single-transaction` and `--quick` for an InnoDB-consistent logical dump without disruptive table locks
- schema and data
- routines, triggers, and events
- `utf8mb4`
- `--no-tablespaces`

Credentials are written to a short-lived, permission-restricted client option file and are not placed in the command arguments, output, or manifest. Configure `ERP_MYSQLDUMP_BINARY` if the executable is not on the production service account's PATH.

An SQLite backup cannot be restored directly into MySQL. Production MySQL recovery must use the MySQL-native `database.sql` produced from that MySQL environment.

## Manifest and validation

`manifest.json` contains no secrets. It records:

- manifest and backup-command version
- successful/failed status
- UTC backup timestamp
- application name, Laravel version, and Git commit when available
- database driver
- latest recorded migration
- database/storage filenames, sizes, and SHA-256 checksums

Restore validation rejects incomplete backups, unsupported manifest versions, missing components, and checksum mismatches before writing a target.

An interrupted or failed backup remains under an `.incomplete-*` directory with a failed manifest and a safe application-log diagnostic. It is never advertised as successful and retention is not run after failure.

## Retention

The default successful-backup retention is:

- 14 daily recovery points
- 8 weekly recovery points
- 6 monthly recovery points

The newest successful backup is always retained. Only directories with a valid successful manifest are candidates for automatic pruning. Failed/incomplete backups are not automatically deleted. Use `--retention-dry-run` to inspect candidates before enabling scheduled cleanup.

Retention settings:

```text
ERP_BACKUP_KEEP_DAILY=14
ERP_BACKUP_KEEP_WEEKLY=8
ERP_BACKUP_KEEP_MONTHLY=6
```

## Scheduling and health

The Laravel scheduler registers `erp:backup` daily at the configurable application/business time with overlap protection. Production must explicitly enable it:

```text
ERP_BACKUP_SCHEDULE_ENABLED=true
ERP_BACKUP_SCHEDULE_TIME=02:00
ERP_BACKUP_OVERLAP_MINUTES=180
```

The host cron/system scheduler is configured separately; this phase does not install cron. Do not add `onOneServer()` until the final production topology and shared locking store are confirmed.

`php artisan erp:production-check` reports the configured backup location, latest successful backup time/age, and latest manifest checksum status. Production fails readiness when no successful backup exists, the latest backup is older than `ERP_BACKUP_MAX_AGE_HOURS`, or checksums are invalid. Local development receives warnings rather than an unnecessary hard failure.

## Off-server copies and encryption

A local backup alone does not protect against server or disk loss. Copy every successful backup directory to a separately administered target such as:

- S3-compatible object storage with versioning/retention
- a secure remote backup server
- an encrypted NAS
- a vendor-neutral cloud backup service

The finalized directory and manifest are portable, so an off-server transport can upload only successful backups without changing backup creation. No cloud credentials are configured by this phase.

Backups contain employee, customer, operational, and financial data and must be encrypted at rest before production use. Use storage-level or standard archive/object encryption with a key held in the production secret manager. Never store the encryption key beside the archive, in Git, in the manifest, or in logs. Application-level archive encryption is not enabled in this phase; this is a production blocker until the chosen key-management and off-server platform are approved.

## Restore rehearsal and verification

Perform a disposable restore regularly and after material schema changes:

1. Run `erp:restore <backup> --verify-only`.
2. Restore to a newly created disposable target.
3. Confirm database integrity and zero FK violations.
4. Compare counts and fingerprints for Users, Employees, Products, Orders, Product Inventories, Stock Movements, Returns, Claims, Warranty Repairs, Tasks, Tax Invoices, Notification Rules, Expenses, and Web Sales fields.
5. Confirm the restored latest migration matches the manifest.
6. Confirm required storage files exist and open correctly.
7. Remove only the explicitly verified disposable targets.

Never point the rehearsal at the active database or active storage.

## Production MySQL restore procedure

This is a high-risk operator procedure and must be rehearsed on a disposable MySQL 8 database first.

1. Declare the incident and stop web, queue, and scheduler writes or place the ERP in maintenance mode.
2. Preserve the failed/current database before changing it.
3. Validate the selected backup manifest and checksum with `erp:restore --verify-only`.
4. Create a new empty disposable MySQL database with `utf8mb4`; do not overwrite the active schema first.
5. Import `database.sql` with the standard MySQL client using a protected client option file/secret injection—not a password-bearing command line.
6. Run migrations status, table/count/fingerprint comparisons, application smoke checks, and business invariants against the disposable database.
7. Restore `storage.zip` to a separate directory and verify branding/documents.
8. Switch application configuration or database routing only after approval and a verified maintenance window.
9. Run `erp:production-check`, then controlled smoke tests before reopening traffic.
10. Keep the pre-restore database and prior storage intact until the recovery is accepted.

Do not import an SQLite file into MySQL. Do not run `migrate:fresh`, `db:wipe`, or destructive schema commands during recovery.

## Emergency SQLite recovery

1. Stop all writers.
2. Copy and checksum the current damaged database for forensic retention.
3. Validate the intended backup.
4. Restore it to a disposable path and complete all integrity/count checks.
5. Take a timestamped pre-restore copy of the active file.
6. Replace the active database only within an approved maintenance window.
7. Ensure file ownership/permissions are correct and Laravel has FK enforcement enabled.
8. Restore storage separately, then run production checks and smoke tests.

The command does not silently perform steps 5–6; this deliberate boundary prevents an accidental active-database overwrite.

## Failure handling and rollback

- Database backup failure: the backup is failed/incomplete; storage is not presented as a successful recovery set; no retention runs.
- Storage archive failure: the whole set is failed/incomplete; no retention runs.
- Insufficient or unwritable disk: backup fails before database work where detectable.
- Restore validation failure: do not write any target; select another recovery point or investigate the copied artifact.
- Restore verification failure: keep active data untouched, retain diagnostics, and discard only the verified disposable target.
- Post-cutover failure: stop writes, point the ERP back to the preserved pre-restore database/storage, clear only safe application caches, and repeat checks.

Backup failures are logged without credentials. Integrating these failures with the existing Owner/Admin operational alert transport is a later production configuration task; tests must not send real email.

## Disaster recovery checklist

- [ ] Incident owner and maintenance window declared
- [ ] Web, queue, scheduler, and integrations stopped from writing
- [ ] Current failed state preserved and checksummed
- [ ] Off-server backup selected and manifest/checksums validated
- [ ] Database driver matches the backup
- [ ] Disposable database restore verified
- [ ] Required business counts/fingerprints match
- [ ] Storage archive restored and sampled
- [ ] Latest migration/status verified
- [ ] Application secrets supplied from the secret manager (never restored from backup)
- [ ] Queue/scheduler configuration verified before restart
- [ ] `erp:production-check` and smoke tests pass
- [ ] Controlled cutover approved
- [ ] Monitoring increased after recovery
- [ ] Incident and achieved RPO/RTO documented

## Remaining production prerequisites

- Select and configure a durable off-server destination.
- Approve encryption and key-management ownership.
- Install/verify the scheduler host entry and service-account permissions.
- Verify the exact MySQL 8 client/dump binary paths on the production host.
- Complete a MySQL-native backup/restore rehearsal in the production-like environment.
- Define operational ownership for backup-failure alerts and periodic restore drills.
