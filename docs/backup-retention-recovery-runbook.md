# TPZ ERP — protected backup retention and release runbook

## Incident facts (02 Nov 2026)

On Main/UAT, backups `2026-11-01_141816` and `2026-11-01_142930` were absent from checked backup roots. Automatic rotation previously considered **all** successful backups together; the later scheduled `2026-11-01_210016` occupied the same daily, weekly and monthly buckets. No deletion log exists for the missing backups, so the retention mechanism is a strong inference, **not forensic proof** of the exact deletions.

The following backups were checked for manifest, database/storage SHA-256, MySQL logical dump structural coverage and ZIP CRC, but **not restore-rehearsed**:

- `2026-10-31_073134` (nearest older full backup)
- `2026-11-01_210016` (scheduled full backup)
- `2026-11-02_061237` (pre-reset protected backup)

Independent, verified copies of the first two have been stored outside automatic rotation under the restricted `/home/tpzadmin/.tpz-backup-recovery-pins` path. These copies are still **on the same VPS**: they are not offsite disaster recovery.

## New retention contract

- New backup manifests carry `purpose`: `scheduled`, `manual`, `deployment`, or `operational_reset`.
- New `retention_protected` is `false` **only** for scheduled backups.
- Old manifests with missing/unrecognized fields, manually protected backups, deployment backups and reset backups **never enter automatic prune candidates**.
- Daily/weekly/monthly rotation applies only to explicitly unprotected scheduled backups.
- Deletions are logged with backup ID, purpose and reason; a pre-delete manifest recheck ensures a newly protected backup isn't pruned.
- Manual/UI backups default to protected. `erp:backup --full --purpose=deployment --retention-dry-run` explicitly creates both database and storage components.
- Before any release, inspect a **dry-run plan**. Never manually edit a successful manifest to make a formerly protected backup prunable.

### Required tests before deployment

```
php -l app/Services/Backups/BackupRetentionService.php
php -l app/Services/Backups/ErpBackupService.php
php -l app/Console/Commands/BackupErp.php
php artisan test --filter=BackupRestoreTest
php artisan test --filter=BackupSettingsMigrationTest
php artisan test --filter=BackupSettingsUiTest
php artisan test --filter=BackgroundServicesTest
```

Use a **disposable SQLite test DB**, not UAT or staging MySQL. Validate protected manual/deployment/legacy records remain after rotation, scheduled rotation still works, and a full deployment backup includes both `database.sqlite` and `storage.zip`.

### Controlled Staging rollout

1. Verify current deployed Staging SHA, database `tpz_erp_staging`, clean Git worktree, no queued migrations, and config integrity.
2. Create and verify a fresh **full Staging database+storage backup without pruning** on the existing baseline. Copy it into a protected path outside rotation, verify SHA-256 and ZIP CRC. Stop if either backup component is absent or unreadable.
3. Prepare the advanced Staging integration commit using the existing Staging branch as first parent. Never overwrite concurrent Staging changes.
4. Deploy **Staging only** using the guarded deployment helper and exact SHA (no migrations or asset build for this change unless separately identified).
5. Post-deploy: verify app login 200, config, protected backup manifests, retention dry-run plan and expected schedule. Exercise the CLI full deployment backup with dry-run retention on Staging only.
6. Owner confirms Staging UAT. Promote to Main/UAT only by a separate explicit approval and fresh protected recovery snapshot.
7. Carry out a disposable MySQL restore rehearsal separately; archive checksums alone do not establish full restore capability.

### First-release compatibility

Deployment wrappers stream the local `Apply-TpzRelease.sh` script to the server. The updated helper invokes `--full --purpose=deployment` **before** checking out the new commit; these flags will not be recognized by the prior baseline if the release requests a migration or `--DataChange`. The first deployment of this update therefore must have **zero pending migrations and DataChange=false** and its backup must be created/protected in a separate preflight as above. Do **not** bypass this requirement. Subsequent schema releases can use the updated full-protection helper.

### Cautions

- Keep both independent protected copies on the server until offsite backups are configured and tested.
- Backup verification currently checks file integrity and dump structure, not a full MySQL restore.
- Do not run `erp:backup` without `--retention-dry-run` on the old deployed code in any environment until retention fix is deployed.
- Main/UAT operational records and invoices must remain unchanged during release QA.
