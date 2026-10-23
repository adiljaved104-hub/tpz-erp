#!/usr/bin/env bash
set -euo pipefail

script=$(cd "$(dirname "$0")/.." && pwd)/Apply-TpzRelease.sh

run_case() {
    local scenario=$1 expected_code=$2 fixture output status
    fixture=$(mktemp -d)
    mkdir -p "$fixture/bootstrap/cache" "$fixture/storage/framework/views"
    : >"$fixture/.env"
    : >"$fixture/bootstrap/cache/config.php"
    : >"$fixture/storage/framework/views/compiled.php"
    set +e
    output=$(TPZ_SCENARIO="$scenario" TPZ_DEPLOY_SCRIPT="$script" TPZ_FIXTURE="$fixture" bash -c '
        source <(awk '\''/^\[\[ \$# == 8 \]\]/{exit} {print}'\'' "$TPZ_DEPLOY_SCRIPT")
        cd "$TPZ_FIXTURE"
        step=permissions_and_cache
        data_change=false
        maintenance_attempted=true
        test_state=ACTIVE
        declare -A groups=() modes=()
        calls=
        php() {
            if [[ "$1" == /dev/stdin ]]; then printf "%s" "$test_state"; return; fi
            if [[ "$1" == artisan && "$2" == up ]]; then test_state=INACTIVE; return; fi
            return 1
        }
        stat() {
            case "$2" in
                %G) printf "%s" "${groups[$3]:-wrong}" ;;
                %a) printf "%s" "${modes[$3]:-600}" ;;
                *) return 1 ;;
            esac
        }
        chgrp() {
            if [[ "$TPZ_SCENARIO" == group_change_fails ]]; then return 42; fi
            groups["$2"]=$1
            calls="$calls chgrp:$2"
        }
        chmod() {
            modes["$2"]=${1#0}
            calls="$calls chmod:$2"
        }
        case "$TPZ_SCENARIO" in
            repaired)
                repair_cache_tree bootstrap/cache
                repair_cache_tree storage/framework/views
                verify_cache_tree bootstrap/cache
                verify_cache_tree storage/framework/views
                [[ "${groups[bootstrap/cache/config.php]}" == www-data ]]
                [[ "${modes[bootstrap/cache/config.php]}" == 640 ]]
                [[ "${groups[storage/framework/views/compiled.php]}" == www-data ]]
                [[ "${modes[storage/framework/views/compiled.php]}" == 640 ]]
                [[ "$calls" != *\.env* ]]
                printf "CACHE_REPAIR=PASS\n"
                exit 0 ;;
            missing_repair)
                verify_cache_tree bootstrap/cache; exit 99 ;;
            group_change_fails)
                repair_cache_tree bootstrap/cache; exit 99 ;;
        esac
    ' 2>&1)
    status=$?
    set -e
    [[ "$fixture" == /tmp/* && -d "$fixture" ]]
    rm -r -- "$fixture"
    [[ "$status" == "$expected_code" ]]
    if [[ "$scenario" == repaired ]]; then
        grep -Fq 'CACHE_REPAIR=PASS' <<<"$output"
    else
        grep -Fq 'STOPPED_STAGE=permissions_and_cache' <<<"$output"
        grep -Fq "ORIGINAL_EXIT_CODE=$expected_code" <<<"$output"
        grep -Fq 'MAINTENANCE_RECOVERY=DISABLED' <<<"$output"
    fi
    printf 'PASS %s\n' "$scenario"
}

run_case repaired 0
run_case missing_repair 1
run_case group_change_fails 42
