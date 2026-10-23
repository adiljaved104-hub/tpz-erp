# TPZ ERP release workflow

This is a deployment helper, not an approval mechanism. Run it only for a release already approved for the named environment. Keep Main/UAT promotion separate from Staging approval.

The Windows PowerShell wrapper verifies a clean local worktree at the exact approved commit, optionally builds and uploads frontend assets, then uses the existing SSH key with strict host-key checking. The server script verifies the VPS, Git origin, target branch, current baseline, database identity and exact pending migration filenames before changing code or schema. It uses `git merge --ff-only`; it never force-pushes, resets, seeds, refreshes, truncates or rolls back a database.

For a UI-only release, pass no expected migrations or data-change flag. No new database backup is created. For a migration or approved data-changing release, list **every** expected migration (without `.php`), or use `-DataChange` as appropriate. The helper creates exactly one database-only ERP backup, runs checksum verification, and retains its path before applying the approved migration files individually. A storage-changing release needs its own separately approved storage backup plan; this helper does not claim to cover uploads.

Example after Staging has been promoted to the reviewed commit (replace SHAs with verified values):

```powershell
powershell.exe -ExecutionPolicy Bypass -NoProfile -File .\scripts\deployment\Deploy-TpzRelease.ps1 `
    -Environment staging -Commit <approved-40-character-sha> `
    -ExpectedBaseline <current-staging-40-character-sha> -BuildAssets
```

Add `-ExpectedMigrations @('2026_...', '2026_...')` for an approved schema release. Use `-DataChange` when an approved deployment changes database data without a migration. The wrapper does not install packages. It can use a previously loaded Windows SSH agent; without one, the existing key may prompt during the asset upload and SSH execution. No credential or passphrase is stored by the helper.

The server script retains per-release logs outside the web directory and corrects PHP/Blade/frontend read access under the protected application root. After Artisan generates caches, it repairs group ownership and modes for PHP files and directories under `bootstrap/cache` and `storage/framework/views` (`www-data`, files `0640`, directories `2770`). It excludes `.env`, application source, uploads and backups. It verifies the resulting metadata, then checks readability directly as `www-data` when noninteractive `sudo -u www-data` is available. Otherwise, the PHP-FPM worker check and a successful HTTP 200 login request provide the runtime check. `PERMISSIONS_STATUS=PASS` is emitted only after that HTTP check succeeds; earlier output says `AWAITING_HTTP_CHECK`.

The script refuses to start if Laravel is already in maintenance mode. On failure, it retains the original exit code and `STOPPED_STAGE`, reports the final maintenance state, and attempts `artisan up` for a UI-only release or an untouched pre-migration baseline. It never automatically exposes a partially migrated release. Never rerun a partially failed release blindly: use its existing evidence and inspect the current Git/migration/backup state first.

Do not put an `.env` file, database credentials, SSH private key, backup contents or employee data in this repository or deployment output. The helper checks that `.env` contents remain unchanged. Automated tests belong on a disposable local database, never on the VPS.
