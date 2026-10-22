#!/usr/bin/env bash
set -euo pipefail

script=$(cd "$(dirname "$0")/.." && pwd)/Apply-TpzRelease.sh

run_case() {
    local scenario=$1 expected_code=$2 expected_stage=$3 expected_recovery=$4 expected_state=$5
    local output status
    set +e
    output=$(TPZ_SCENARIO="$scenario" TPZ_DEPLOY_SCRIPT="$script" bash -c '
        php() {
            if [[ "$1" == /dev/stdin ]]; then
                printf "%s" "$test_state"
            elif [[ "$1" == artisan && "$2" == up ]]; then
                if [[ "$TPZ_SCENARIO" == recovery_fails ]]; then return 1; fi
                test_state=INACTIVE
            else
                return 1
            fi
        }
        git() {
            [[ "$1" == rev-parse && "$2" == HEAD ]] || return 1
            printf "%s" "$test_head"
        }
        source <(awk '\''/^\[\[ \$# == 8 \]\]/{exit} {print}'\'' "$TPZ_DEPLOY_SCRIPT")
        baseline=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        test_head=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
        test_state=ACTIVE
        data_change=false
        case "$TPZ_SCENARIO" in
            preexisting_maintenance)
                maintenance_attempted=false; step=maintenance_preflight
                ensure_not_in_maintenance; exit 99 ;;
            before_maintenance)
                maintenance_attempted=false; step=remote_identity; exit 23 ;;
            ui_failure)
                maintenance_attempted=true; step=fast_forward; exit 37 ;;
            recovery_fails)
                maintenance_attempted=true; step=permissions_and_cache; exit 41 ;;
            already_disabled)
                maintenance_attempted=true; test_state=INACTIVE; step=website_health; exit 43 ;;
            migration_started)
                maintenance_attempted=true; pending=(2026_10_22_000000_example)
                migrations_started=true; step=approved_migrations; exit 47 ;;
            migration_before_change)
                maintenance_attempted=true; pending=(2026_10_22_000000_example)
                test_head=$baseline; step=fast_forward; exit 53 ;;
        esac
    ' 2>&1)
    status=$?
    set -e
    [[ "$status" == "$expected_code" ]]
    grep -Fq "ORIGINAL_EXIT_CODE=$expected_code" <<<"$output"
    grep -Fq "STOPPED_STAGE=$expected_stage" <<<"$output"
    grep -Fq "MAINTENANCE_RECOVERY=$expected_recovery" <<<"$output"
    grep -Fq "MAINTENANCE_STATUS=$expected_state" <<<"$output"
    printf 'PASS %s\n' "$scenario"
}

run_case preexisting_maintenance 1 maintenance_preflight NOT_NEEDED ACTIVE
run_case before_maintenance 23 remote_identity NOT_NEEDED UNKNOWN
run_case ui_failure 37 fast_forward DISABLED INACTIVE
run_case recovery_fails 41 permissions_and_cache FAILED ACTIVE_OR_UNKNOWN
run_case already_disabled 43 website_health ALREADY_DISABLED INACTIVE
run_case migration_started 47 approved_migrations SKIPPED_UNSAFE ACTIVE
run_case migration_before_change 53 fast_forward DISABLED INACTIVE
