#!/usr/bin/env bash
# Run only for an approved staging or UAT release. The Windows wrapper validates
# arguments and sends this script over one authenticated SSH session.
set -Eeuo pipefail
umask 077

step=preflight
backup_path=NOT_REQUIRED
run=
baseline=
data_change=unknown
maintenance_attempted=false
maintenance_preflight_state=UNKNOWN
migrations_started=false
declare -a pending=()

maintenance_state() {
    php /dev/stdin <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo $app->isDownForMaintenance() ? 'ACTIVE' : 'INACTIVE';
PHP
}

ensure_not_in_maintenance() {
    maintenance_preflight_state=$(maintenance_state) || return 1
    if [[ "$maintenance_preflight_state" != INACTIVE ]]; then
        printf 'MAINTENANCE_PREFLIGHT=FAILED_ALREADY_ACTIVE_OR_UNKNOWN\n' >&2
        return 1
    fi
    printf 'MAINTENANCE_PREFLIGHT=INACTIVE\n'
}

repair_cache_tree() {
    local tree=$1 entry
    [[ "$tree" == bootstrap/cache || "$tree" == storage/framework/views ]]
    [[ -d "$tree" && ! -L "$tree" && -O "$tree" ]]
    [[ -z "$(find "$tree" -type l -print -quit)" ]]
    while IFS= read -r -d '' entry; do
        [[ -O "$entry" ]]
        if [[ "$(stat -c %G "$entry")" != www-data ]]; then
            chgrp www-data "$entry"
        fi
        if [[ -d "$entry" ]]; then
            chmod 2770 "$entry"
        else
            chmod 0640 "$entry"
        fi
    done < <(find "$tree" \( -type d -o \( -type f -name '*.php' \) \) -print0)
}

verify_cache_tree() {
    local tree=$1 entry expected_mode
    [[ "$tree" == bootstrap/cache || "$tree" == storage/framework/views ]]
    while IFS= read -r -d '' entry; do
        expected_mode=640
        [[ -d "$entry" ]] && expected_mode=2770
        [[ "$(stat -c %G "$entry")" == www-data ]]
        [[ "$(stat -c %a "$entry")" == "$expected_mode" ]]
    done < <(find "$tree" \( -type d -o \( -type f -name '*.php' \) \) -print0)
}

deployment_exit() {
    local original_code=$1 original_stage=$step recovery=NOT_NEEDED state=UNKNOWN recovery_log=/dev/null
    trap - EXIT
    if (( original_code == 0 )); then
        return 0
    fi
    set +e
    if [[ -n "$run" && -d "$run" ]]; then
        recovery_log=$run/maintenance-recovery.log
    fi
    if [[ "$maintenance_attempted" == true ]]; then
        state=$(maintenance_state 2>/dev/null)
        [[ -n "$state" ]] || state=UNKNOWN
        if [[ "$state" == INACTIVE ]]; then
            recovery=ALREADY_DISABLED
        elif (( ${#pending[@]} == 0 )) && [[ "$data_change" == false ]] \
            || { [[ "$migrations_started" == false && -n "$baseline" ]] \
                && [[ "$(git rev-parse HEAD 2>/dev/null)" == "$baseline" ]]; }; then
            # A UI-only release cannot have changed schema or data. For schema
            # releases, only the untouched baseline is safe to bring back up.
            php artisan up --no-interaction >>"$recovery_log" 2>&1
            if [[ "$(maintenance_state 2>/dev/null)" == INACTIVE ]]; then
                state=INACTIVE
                recovery=DISABLED
            else
                state=ACTIVE_OR_UNKNOWN
                recovery=FAILED
            fi
        else
            # Never expose a partially migrated release automatically.
            recovery=SKIPPED_UNSAFE
        fi
    else
        state=$maintenance_preflight_state
    fi
    printf 'DEPLOYMENT=FAILED\nORIGINAL_EXIT_CODE=%s\nSTOPPED_STAGE=%s\nBACKUP_LOCATION=%s\nMAINTENANCE_STATUS=%s\nMAINTENANCE_RECOVERY=%s\nNO_AUTOMATIC_ROLLBACK=true\n' \
        "$original_code" "$original_stage" "$backup_path" "$state" "$recovery" >&2
    exit "$original_code"
}
trap 'deployment_exit "$?"' EXIT

[[ $# == 8 ]]
environment=$1
approved=$2
baseline=$3
expected_db=$4
expected_csv=$5
data_change=$6
uploaded_asset=$7
asset_sha=$8
[[ "$environment" == staging || "$environment" == uat ]]
[[ "$approved" =~ ^[a-f0-9]{40}$ && "$baseline" =~ ^[a-f0-9]{40}$ ]]
[[ "$expected_db" =~ ^[a-z0-9_]+$ && "$data_change" =~ ^(true|false)$ ]]
[[ "$asset_sha" == NONE || "$asset_sha" =~ ^[a-f0-9]{64}$ ]]
[[ "$uploaded_asset" == NONE || "$uploaded_asset" =~ ^tpz-[a-z0-9-]+-assets\.tar\.gz$ ]]
[[ "$(hostname -s)" == srv1952424 ]]

if [[ "$environment" == staging ]]; then
    app=/var/www/tpz-erp-staging
    branch=staging
    url=https://staging.tpzerp.cloud
else
    app=/var/www/tpz-erp-uat
    branch=main
    url=https://tpzerp.cloud
fi
[[ -d "$app" && ! -L "$app" && "$(realpath "$app")" == "$app" ]]
[[ -d /home/tpzadmin/.tpz-deployments && ! -L /home/tpzadmin/.tpz-deployments ]]
run=$(mktemp -d "/home/tpzadmin/.tpz-deployments/${environment}-${approved:0:8}-XXXXXXXX")
chmod 0700 "$run"
cd "$app"
[[ "$(git branch --show-current)" == "$branch" ]]
[[ "$(git rev-parse HEAD)" == "$baseline" ]]
[[ -z "$(git status --porcelain --untracked-files=normal)" ]]
[[ -f .env && -d vendor && ! -L vendor ]]
[[ "$(stat -c %G .)" == www-data ]]
for path in bootstrap/cache storage/framework storage/framework/views storage/framework/cache storage/framework/sessions storage/logs; do
    [[ -d "$path" && ! -L "$path" && -O "$path" && "$(stat -c %G "$path")" == www-data ]]
done
# Mode repair runs after migrations. Verify now that the deploy account can
# actually chmod the code tree, so an unexpected owner cannot strand a release
# after its schema has changed. Symlinks are excluded from mode changes.
for path in app config database routes resources public vendor; do
    [[ -d "$path" && ! -L "$path" ]]
    [[ -z "$(find "$path" \( -type d -o -type f \) ! -user "$(id -u)" -print -quit)" ]]
done
[[ -z "$(find bootstrap -path bootstrap/cache -prune -o \( -type d -o -type f \) ! -user "$(id -u)" -print -quit)" ]]
env_sha_before=$(sha256sum .env | cut -d ' ' -f 1)
step=maintenance_preflight
ensure_not_in_maintenance

step=remote_identity
origin=$(git remote get-url origin)
case "$origin" in
    https://github.com/adiljaved104-hub/tpz-erp|https://github.com/adiljaved104-hub/tpz-erp.git) ;;
    git@*:[Aa]diljaved104-hub/tpz-erp|git@*:[Aa]diljaved104-hub/tpz-erp.git)
        alias_name=${origin#git@}
        alias_name=${alias_name%%:*}
        [[ "$alias_name" =~ ^[a-zA-Z0-9._-]+$ ]]
        [[ "$(ssh -G "$alias_name" 2>/dev/null | awk '$1 == "hostname" { print $2; exit }')" == github.com ]]
        ;;
    *) printf 'ORIGIN=UNVERIFIED\n' >&2; exit 1 ;;
esac
GIT_SSH_COMMAND='ssh -o StrictHostKeyChecking=yes -o BatchMode=yes' git fetch --no-tags origin "$branch" >"$run/git-fetch.log" 2>&1
[[ "$(git rev-parse "refs/remotes/origin/$branch")" == "$approved" ]]
git merge-base --is-ancestor "$baseline" "$approved"
git diff --quiet "$baseline" "$approved" -- composer.json composer.lock
printf 'REMOTE_STATUS=VERIFIED\nPREVIOUS_COMMIT=%s\nDEPLOY_COMMIT=%s\n' "$baseline" "$approved"

step=database_and_migration_preflight
db_identity=$(php /dev/stdin "$expected_db" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$actual = $db->selectOne('SELECT DATABASE() AS database_name')->database_name;
if ($db->getDriverName() !== 'mysql' || $db->getConfig('database') !== $argv[1] || $actual !== $argv[1]) {
    fwrite(STDERR, "DATABASE_IDENTITY=UNVERIFIED\n");
    exit(1);
}
echo $actual;
PHP
)
[[ "$db_identity" == "$expected_db" ]]
applied_text=$(php /dev/stdin <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (Illuminate\Support\Facades\DB::table('migrations')->pluck('migration') as $name) {
    echo $name, "\n";
}
PHP
)
mapfile -t pending < <(comm -23 \
    <(git ls-tree -r --name-only "$approved" database/migrations | sed -n 's#^database/migrations/\(.*\)\.php$#\1#p' | sort) \
    <(printf '%s\n' "$applied_text" | sed '/^$/d' | sort))
IFS=',' read -r -a expected_migrations <<< "$expected_csv"
if [[ -z "$expected_csv" ]]; then expected_migrations=(); fi
for name in "${expected_migrations[@]}"; do
    [[ "$name" =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$ ]]
done
actual_sorted=$(printf '%s\n' "${pending[@]}" | sed '/^$/d' | sort)
expected_sorted=$(printf '%s\n' "${expected_migrations[@]}" | sed '/^$/d' | sort)
[[ "$actual_sorted" == "$expected_sorted" ]]
printf 'DATABASE_IDENTITY=VERIFIED\nMIGRATIONS_EXPECTED=%s\nMIGRATION_PREFLIGHT=PASS\n' "${#pending[@]}"

if (( ${#pending[@]} > 0 )) || [[ "$data_change" == true ]]; then
    step=single_verified_backup
    php artisan erp:backup --database-only --retention-dry-run --no-interaction >"$run/backup.log" 2>&1
    backup_path=$(sed -n 's/^Location: //p' "$run/backup.log" | head -n 1)
    [[ -n "$backup_path" && -d "$backup_path" && ! -L "$backup_path" ]]
    backup_path=$(realpath "$backup_path")
    [[ "$backup_path" == "$app/storage/app/backups/"* ]]
    php artisan erp:restore "$backup_path" --verify-only --no-interaction >"$run/backup-verify.log" 2>&1
    printf 'BACKUP_STATUS=VERIFIED\nBACKUP_LOCATION=%s\n' "$backup_path"
else
    printf 'BACKUP_STATUS=NOT_REQUIRED_UI_ONLY\n'
fi

step=asset_preflight
if [[ "$uploaded_asset" != NONE ]]; then
    asset=/home/tpzadmin/.tpz-deployments/$uploaded_asset
    [[ -f "$asset" && ! -L "$asset" ]]
    [[ "$(sha256sum "$asset" | cut -d ' ' -f 1)" == "$asset_sha" ]]
    mv -- "$asset" "$run/frontend.tar.gz"
    mkdir -m 0700 "$run/frontend"
    tar -tzf "$run/frontend.tar.gz" >"$run/frontend-files.txt"
    while IFS= read -r entry; do
        [[ "$entry" == build || "$entry" == build/* ]]
        [[ "$entry" != *..* && "$entry" != /* ]]
    done <"$run/frontend-files.txt"
    tar -xzf "$run/frontend.tar.gz" --no-same-owner -C "$run/frontend"
    [[ -f "$run/frontend/build/manifest.json" ]]
    printf 'FRONTEND_ARCHIVE=VERIFIED\n'
fi

step=maintenance
maintenance_attempted=true
php artisan down --retry=60 --no-interaction >"$run/maintenance.log" 2>&1
[[ "$(maintenance_state)" == ACTIVE ]]

step=fast_forward
git merge --ff-only "$approved" >"$run/fast-forward.log" 2>&1
[[ "$(git rev-parse HEAD)" == "$approved" ]]
if [[ "$uploaded_asset" != NONE ]]; then
    [[ ! -L public/build ]]
    if [[ -d public/build ]]; then mv -- public/build "$run/previous-build"; fi
    mv -- "$run/frontend/build" public/build
fi
composer dump-autoload --no-dev --optimize --no-scripts --no-interaction >"$run/autoload.log" 2>&1

step=approved_migrations
for name in "${pending[@]}"; do
    migrations_started=true
    php artisan migrate --force --no-interaction --path="database/migrations/$name.php" >"$run/migrate-$name.log" 2>&1
done
if (( ${#pending[@]} > 0 )); then
    php /dev/stdin "${pending[@]}" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$applied = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
foreach (array_slice($argv, 1) as $name) {
    if (! in_array($name, $applied, true)) {
        fwrite(STDERR, "MIGRATION_VERIFY=FAILED\n");
        exit(1);
    }
}
echo "MIGRATION_VERIFY=PASS\n";
PHP
fi
printf 'MIGRATIONS_EXECUTED=%s\n' "${#pending[@]}"

step=permissions_and_cache
# The application root stays inaccessible to unrelated local users. Code files
# are readable through that protected root; .env and storage/app are excluded.
chmod 2750 .
for path in app config database routes resources public vendor; do
    [[ -d "$path" && ! -L "$path" ]]
    find "$path" -type d -exec chmod u+rwx,g+rx,o+rx {} +
    find "$path" -type f -exec chmod u+rw,g+r,o+r {} +
done
chmod u+rwx,g+rx,o+rx bootstrap
find bootstrap -path bootstrap/cache -prune -o -type d -exec chmod u+rwx,g+rx,o+rx {} +
find bootstrap -path bootstrap/cache -prune -o -type f -exec chmod u+rw,g+r,o+r {} +
for path in bootstrap/cache storage/framework storage/framework/views storage/framework/cache storage/framework/sessions storage/logs; do
    chmod 2770 "$path"
done
umask 0007
for action in config:clear route:clear view:clear event:clear filament:clear-cached-components package:discover config:cache route:cache view:cache event:cache; do
    php artisan "$action" --no-interaction >"$run/cache-${action//:/-}.log" 2>&1
done
umask 0077
# Artisan may atomically replace cache files with mode 0600 despite the umask.
# Restrict these generated files to the deploy owner and www-data group only.
# Never include .env, storage/app, backup files, or application source here.
repair_cache_tree bootstrap/cache
repair_cache_tree storage/framework/views
verify_cache_tree bootstrap/cache
verify_cache_tree storage/framework/views
[[ -f bootstrap/cache/config.php ]]
[[ -n "$(find storage/framework/views -type f -name '*.php' -print -quit)" ]]
[[ "$env_sha_before" == "$(sha256sum .env | cut -d ' ' -f 1)" ]]
[[ -z "$(git status --porcelain --untracked-files=normal)" ]]
printf 'CACHE_PERMISSIONS=GROUP_AND_MODE_VERIFIED\n'
if sudo -n -u www-data true 2>/dev/null; then
    unreadable=$(sudo -n -u www-data find bootstrap/cache storage/framework/views \
        \( -type d ! -executable -o -type f -name '*.php' ! -readable \) -print -quit)
    [[ -z "$unreadable" ]]
    sudo -n -u www-data test -r public/index.php
    sudo -n -u www-data test -r resources/views/filament/pages/hr/leave.blade.php
    printf 'WWW_DATA_READABILITY=DIRECT_PASS\n'
else
    # On hosts without noninteractive sudo, the PHP-FPM worker and the actual
    # HTTP request below provide the effective www-data execution check.
    ps -eo user=,args= | grep -Eq '^www-data[[:space:]].*php-fpm: pool'
    printf 'WWW_DATA_READABILITY=AWAITING_HTTP_CHECK\n'
fi
printf 'PERMISSIONS_STATUS=AWAITING_HTTP_CHECK\nWORKTREE_STATUS=CLEAN\n'

step=website_health
php artisan up --no-interaction >"$run/online.log" 2>&1
[[ "$(maintenance_state)" == INACTIVE ]]
http_code=$(curl --silent --show-error --location --max-time 30 --output /dev/null --write-out '%{http_code}' "$url/admin/login")
[[ "$http_code" == 200 ]]
printf 'LOGIN_HTTP_STATUS=%s\nWWW_DATA_HTTP_READABILITY=PASS\n' "$http_code"
[[ "$env_sha_before" == "$(sha256sum .env | cut -d ' ' -f 1)" ]]
[[ -z "$(git status --porcelain --untracked-files=normal)" ]]
printf 'DEPLOYMENT=SUCCESS\nDEPLOYED_COMMIT=%s\nBACKUP_LOCATION=%s\nMIGRATIONS_EXECUTED=%s\nPERMISSIONS_STATUS=PASS\nMAINTENANCE_STATUS=INACTIVE\nMAINTENANCE_RECOVERY=NOT_NEEDED\nWORKTREE_STATUS=CLEAN\nSTOPPED_STAGE=NONE\n' "$approved" "$backup_path" "${#pending[@]}"
