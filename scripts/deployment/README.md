# TPZ ERP release workflow

This is a reusable deployment helper, not an approval mechanism. Run it only for a release already approved for the named environment. Keep Main/UAT promotion separate from Staging approval. Do not create a new PowerShell wrapper for each release.

The Windows PowerShell helper verifies a clean local worktree at the exact approved commit, optionally builds and uploads frontend assets, then uses the existing SSH key with strict host-key checking. It requires that exact key to be unlocked in the existing Windows OpenSSH agent before starting, so an unattended deployment fails early instead of repeatedly prompting. The server script verifies the VPS, Git origin, target branch, current baseline, database identity and exact pending migration filenames before changing code or schema. It uses `git merge --ff-only`; it never force-pushes, resets, seeds, refreshes, truncates or rolls back a database.

For a UI-only release, pass no expected migrations or data-change flag. No new database backup is created. For a migration or approved data-changing release, list **every** expected migration (without `.php`), or use `-DataChange` as appropriate. The helper creates exactly one database-only ERP backup, runs checksum verification, and retains its path before applying the approved migration files individually. A storage-changing release needs its own separately approved storage backup plan; this helper does not claim to cover uploads.

Example after Staging has been promoted to the reviewed commit (replace SHAs with verified values):

```powershell
powershell.exe -ExecutionPolicy Bypass -NoProfile -File .\scripts\deployment\Deploy-TpzRelease.ps1 `
    -Environment staging -Commit <approved-40-character-sha> `
    -ExpectedBaseline <current-staging-40-character-sha> -BuildAssets
```

Add `-ExpectedMigrations @('2026_...', '2026_...')` for an approved schema release. Use `-DataChange` when an approved deployment changes database data without a migration. The helper does not install packages or store credentials. Unlock the existing key in Windows OpenSSH agent once per workstation session if needed; thereafter routine SSH and asset transfers are noninteractive. The private key and passphrase never belong in the repository or deployment arguments.

The server script retains per-release logs outside the web directory and corrects PHP/Blade/frontend read access under the protected application root. Before maintenance, it checks that the deploy user belongs to `www-data`, cache directories are writable, and any needed group or mode change is owner-authorized. It then repairs only deployer-owned cache entries to group `www-data` and secure modes; already-secure files owned by `www-data` are left alone. This requires no per-release sudo. A foreign-owned entry needing repair or missing group membership fails before maintenance.

After Artisan generates caches, it rechecks and repairs groups and modes for deployer-owned PHP files and directories under `bootstrap/cache` and `storage/framework/views` (group `www-data`, files `0640`, directories `2770`). Laravel may atomically move files into those directories with the deploy user's primary group, so directory setgid alone is insufficient. `www-data`-owned runtime files remain untouched when their group can read them and they are not world-writable; PHP-FPM may create these with mode `0755`. This is accepted only while the application root remains mode `2750`, preventing unrelated OS users from traversing it. The repair excludes `.env`, application source, uploads and backups. It verifies the resulting metadata, then checks readability directly as `www-data` when noninteractive `sudo -u www-data` is available. Otherwise, the PHP-FPM worker check and a successful HTTP 200 login request provide the runtime check. `PERMISSIONS_STATUS=PASS` is emitted only after that HTTP check succeeds; earlier output says `AWAITING_HTTP_CHECK`.

The script refuses to start if Laravel is already in maintenance mode. On failure, it retains the original exit code and `STOPPED_STAGE`, reports the final maintenance state, and attempts `artisan up` for a UI-only release or an untouched pre-migration baseline. It never automatically exposes a partially migrated release. Never rerun a partially failed release blindly: use its existing evidence and inspect the current Git/migration/backup state first.

Do not put an `.env` file, database credentials, SSH private key, backup contents or employee data in this repository or deployment output. The helper checks that `.env` contents remain unchanged. Automated tests belong on a disposable local database, never on the VPS.
