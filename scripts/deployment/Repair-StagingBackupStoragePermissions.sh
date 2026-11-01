#!/usr/bin/env bash
set -Eeuo pipefail

# One-time, Staging-only metadata repair. Dry-run is the default. This script
# neither reads evidence contents nor changes backup archives or manifests.
mode="${1:---dry-run}"
app=/var/www/tpz-erp-staging
private="$app/storage/app/private"
baseline=1565d292e6e7a5df6a65d8a22d4c60b8b4e60b28
directories=(
    "$private/customer-return-evidence"
    "$private/customer-return-evidence/upload-sessions"
    "$private/customer-return-evidence/originals"
    "$private/customer-return-evidence/labels"
    "$private/customer-return-evidence/labels/tmp"
    "$private/qc"
    "$private/qc/originals"
    "$private/qc/customer"
)

[[ "$(hostname -s)" == srv1952424 && $EUID -eq 0 ]] || { echo 'GUARD=SERVER_OR_SUDO_FAILED'; exit 1; }
[[ -d "$app/.git" || -f "$app/.git" ]] || { echo 'GUARD=APPLICATION_PATH_FAILED'; exit 1; }
id -nG tpzadmin | tr ' ' '\n' | grep -qx www-data || { echo 'GUARD=BACKUP_GROUP_FAILED'; exit 1; }

if [[ "$mode" == --rollback ]]; then
    state="${2:-}"
    [[ -n "$state" && ! -L "$state" && -d "$state" ]] || { echo 'ROLLBACK=INVALID_SNAPSHOT'; exit 1; }
    state="$(realpath -e -- "$state")"
    [[ "$state" == /home/tpzadmin/.tpz-backup-permission-repair-* ]] || { echo 'ROLLBACK=INVALID_SNAPSHOT'; exit 1; }
    [[ "$(stat -c '%a:%U' -- "$state")" == 700:root ]] || { echo 'ROLLBACK=INVALID_SNAPSHOT'; exit 1; }
    (cd "$state" && sha256sum -c snapshot.sha256 >/dev/null) || { echo 'ROLLBACK=SNAPSHOT_CHECKSUM_FAILED'; exit 1; }
    mapfile -t saved < "$state/snapshot.txt"
    [[ ${#saved[@]} -eq ${#directories[@]} ]] || { echo 'ROLLBACK=SNAPSHOT_SCOPE_FAILED'; exit 1; }
    for index in "${!directories[@]}"; do
        [[ "${saved[$index]}" == "700:www-data:www-data|${directories[$index]}" ]] || { echo 'ROLLBACK=SNAPSHOT_SCOPE_FAILED'; exit 1; }
        [[ ! -L "${directories[$index]}" && -d "${directories[$index]}" ]] || { echo 'ROLLBACK=TARGET_CHANGED'; exit 1; }
        [[ "$(stat -c '%a:%U:%G' -- "${directories[$index]}")" == 750:www-data:www-data ]] || { echo 'ROLLBACK=TARGET_CHANGED'; exit 1; }
    done
    chmod 0700 -- "${directories[@]}"
    for directory in "${directories[@]}"; do
        [[ "$(stat -c '%a:%U:%G' -- "$directory")" == 700:www-data:www-data ]] || { echo 'ROLLBACK=VERIFY_FAILED'; exit 1; }
    done
    echo 'ROLLBACK=PASS'
    exit 0
fi

[[ "$mode" == --dry-run || "$mode" == --execute ]] || { echo 'GUARD=INVALID_MODE'; exit 1; }
[[ "$(git -C "$app" rev-parse HEAD)" == "$baseline" ]] || { echo 'GUARD=STAGING_BASELINE_CHANGED'; exit 1; }
[[ -z "$(git -C "$app" status --porcelain)" ]] || { echo 'GUARD=STAGING_WORKTREE_DIRTY'; exit 1; }
[[ "$(stat -c '%a:%U:%G' -- "$private")" == 770:tpzadmin:www-data ]] || { echo 'GUARD=PRIVATE_ROOT_CHANGED'; exit 1; }

for directory in "${directories[@]}"; do
    [[ ! -L "$directory" && -d "$directory" ]] || { echo 'GUARD=TARGET_CHANGED'; exit 1; }
    [[ "$(stat -c '%a:%U:%G' -- "$directory")" == 700:www-data:www-data ]] || { echo 'GUARD=TARGET_CHANGED'; exit 1; }
done
expected="$(printf '%s\n' "${directories[@]}" | LC_ALL=C sort)"
actual="$(find "$private" -name livewire-tmp -prune -o -type d ! -perm -g+rx -print | LC_ALL=C sort)"
[[ "$actual" == "$expected" ]] || { echo 'GUARD=UNREVIEWED_PRIVATE_DIRECTORY'; exit 1; }
[[ -z "$(find "$private" -name livewire-tmp -prune -o -type f ! -perm -g+r -print -quit)" ]] || { echo 'GUARD=UNREADABLE_PRIVATE_FILE'; exit 1; }
[[ -z "$(find "$private" -name livewire-tmp -prune -o -type f ! -group www-data ! -user tpzadmin -print -quit)" ]] || { echo 'GUARD=UNREVIEWED_FILE_OWNERSHIP'; exit 1; }

printf 'SERVER_ID=VERIFIED\nSTAGING_BASELINE=VERIFIED\nBACKUP_ACCOUNT=tpzadmin\n'
for directory in "${directories[@]}"; do
    printf 'PERMISSION_PREVIEW=%s 0700->0750 owner/group unchanged\n' "${directory#"$private/"}"
done
printf 'FILES_TO_CHANGE=0\nDIRECTORIES_TO_CHANGE=%s\n' "${#directories[@]}"
if [[ "$mode" == --dry-run ]]; then
    echo 'REPAIR=DRY_RUN_ONLY'
    exit 0
fi

state="$(mktemp -d /home/tpzadmin/.tpz-backup-permission-repair-XXXXXXXX)"
chmod 0700 -- "$state"
for directory in "${directories[@]}"; do
    printf '700:www-data:www-data|%s\n' "$directory"
done > "$state/snapshot.txt"
(cd "$state" && sha256sum snapshot.txt > snapshot.sha256)
chmod 0600 -- "$state/snapshot.txt" "$state/snapshot.sha256"

changed=false
restore_on_error() {
    if [[ "$changed" == true ]]; then
        chmod 0700 -- "${directories[@]}" || true
        echo 'PERMISSION_RECOVERY=ATTEMPTED'
    fi
    echo 'REPAIR=FAILED'
}
trap restore_on_error ERR
changed=true
chmod 0750 -- "${directories[@]}"
for directory in "${directories[@]}"; do
    [[ "$(stat -c '%a:%U:%G' -- "$directory")" == 750:www-data:www-data ]]
    runuser -u tpzadmin -- test -r "$directory"
    runuser -u tpzadmin -- test -x "$directory"
    runuser -u www-data -- test -r "$directory"
    runuser -u www-data -- test -x "$directory"
    if runuser -u nobody -- test -x "$directory"; then
        echo 'GUARD=UNAUTHORIZED_TRAVERSAL'
        false
    fi
done
runuser -u tpzadmin -- find "$private" -name livewire-tmp -prune -o -type f -print >/dev/null
trap - ERR
printf 'REPAIR=PASS\nSNAPSHOT_DIRECTORY=%s\nUNAUTHORIZED_TRAVERSAL=DENIED\n' "$state"
