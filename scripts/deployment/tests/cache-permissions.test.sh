#!/usr/bin/env bash
set -euo pipefail

script=$(cd "$(dirname "$0")/.." && pwd)/Apply-TpzRelease.sh
preflight_line=$(grep -n '^step=cache_permission_preflight$' "$script" | cut -d : -f 1)
maintenance_line=$(grep -n '^step=maintenance$' "$script" | cut -d : -f 1)
[[ -n "$preflight_line" && -n "$maintenance_line" ]]
(( preflight_line < maintenance_line ))
grep -Fq 'cache_group_repair_capable' "$script"
grep -Fq 'chgrp www-data "$entry"' "$script"

run_case() {
    local scenario=$1 expected_code=$2 expected_stage=$3 expected_recovery=$4
    local fixture output status
    fixture=$(mktemp -d)
    mkdir -p "$fixture/bootstrap/cache" "$fixture/storage/framework/views"
    : >"$fixture/.env"
    : >"$fixture/bootstrap/cache/config.php"
    : >"$fixture/storage/framework/views/compiled.php"
    set +e
    output=$(TPZ_SCENARIO="$scenario" TPZ_DEPLOY_SCRIPT="$script" TPZ_FIXTURE="$fixture" bash -c '
        source <(awk '\''/^\[\[ \$# == 8 \]\]/{exit} {print}'\'' "$TPZ_DEPLOY_SCRIPT")
        cd "$TPZ_FIXTURE"
        step=cache_permission_preflight
        data_change=false
        test_state=ACTIVE
        deploy_uid=$(id -u)
        declare -A groups=() modes=() owners=()
        calls=
        id() {
            if [[ "$1" == -nG ]]; then
                if [[ "$TPZ_SCENARIO" == missing_membership ]]; then
                    printf "tpzadmin\n"
                else
                    printf "tpzadmin www-data\n"
                fi
            else
                command id "$@"
            fi
        }
        php() {
            if [[ "$1" == /dev/stdin ]]; then printf "%s" "$test_state"; return; fi
            if [[ "$1" == artisan && "$2" == up ]]; then test_state=INACTIVE; return; fi
            return 1
        }
        stat() {
            case "$2" in
                %G) printf "%s" "${groups[$3]:-www-data}" ;;
                %a)
                    if [[ -d "$3" ]]; then
                        printf "%s" "${modes[$3]:-2770}"
                    else
                        printf "%s" "${modes[$3]:-640}"
                    fi ;;
                %u) printf "%s" "${owners[$3]:-$deploy_uid}" ;;
                %U)
                    if [[ "${owners[$3]:-$deploy_uid}" == 33 ]]; then printf www-data; else printf tpzadmin; fi ;;
                *) return 1 ;;
            esac
        }
        chgrp() {
            if [[ "$TPZ_SCENARIO" == group_change_fails ]]; then return 45; fi
            groups["$2"]=$1
            calls="$calls chgrp:$2"
        }
        chmod() {
            if [[ "$TPZ_SCENARIO" == mode_change_fails ]]; then return 44; fi
            modes["$2"]=${1#0}
            calls="$calls chmod:$2"
        }
        case "$TPZ_SCENARIO" in
            mixed_ownership)
                owners[storage/framework/views/compiled.php]=33
                groups[bootstrap/cache/config.php]=tpzadmin
                modes[bootstrap/cache/config.php]=600
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache
                cache_tree_preflight storage/framework/views
                repair_cache_tree bootstrap/cache
                repair_cache_tree storage/framework/views
                verify_cache_tree bootstrap/cache
                verify_cache_tree storage/framework/views
                [[ "$calls" == *chgrp:bootstrap/cache/config.php* && "$calls" == *chmod:bootstrap/cache/config.php* ]]
                [[ "$calls" != *compiled.php* && "$calls" != *\.env* ]]
                printf "CACHE_REPAIR=PASS\n"; exit 0 ;;
            already_secure)
                owners[bootstrap/cache/config.php]=33
                owners[storage/framework/views/compiled.php]=33
                modes[bootstrap/cache/config.php]=755
                modes[storage/framework/views/compiled.php]=770
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache
                cache_tree_preflight storage/framework/views
                repair_cache_tree bootstrap/cache
                repair_cache_tree storage/framework/views
                verify_cache_tree bootstrap/cache
                verify_cache_tree storage/framework/views
                [[ -z "$calls" ]]
                printf "CACHE_REPAIR=PASS\n"; exit 0 ;;
            foreign_owner_needs_chmod)
                owners[bootstrap/cache/config.php]=33
                modes[bootstrap/cache/config.php]=600
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache; exit 99 ;;
            foreign_owner_wrong_group)
                owners[bootstrap/cache/config.php]=33
                groups[bootstrap/cache/config.php]=tpzadmin
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache; exit 99 ;;
            foreign_owner_world_writable)
                owners[bootstrap/cache/config.php]=33
                modes[bootstrap/cache/config.php]=777
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache; exit 99 ;;
            missing_membership)
                cache_group_repair_capable; exit 99 ;;
            generated_cache_wrong_group)
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache
                cache_tree_preflight storage/framework/views
                modes[bootstrap/cache]=770
                modes[storage/framework/views]=770
                groups[bootstrap/cache/config.php]=tpzadmin
                modes[bootstrap/cache/config.php]=660
                groups[storage/framework/views/compiled.php]=tpzadmin
                modes[storage/framework/views/compiled.php]=770
                maintenance_attempted=true
                step=permissions_and_cache
                repair_cache_tree bootstrap/cache
                repair_cache_tree storage/framework/views
                verify_cache_tree bootstrap/cache
                verify_cache_tree storage/framework/views
                [[ "$calls" == *chgrp:bootstrap/cache/config.php* && "$calls" == *chgrp:storage/framework/views/compiled.php* ]]
                printf "CACHE_REPAIR=PASS\n"; exit 0 ;;
            group_change_fails)
                groups[bootstrap/cache/config.php]=tpzadmin
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache
                maintenance_attempted=true
                step=permissions_and_cache
                repair_cache_tree bootstrap/cache; exit 99 ;;
            mode_change_fails)
                modes[bootstrap/cache/config.php]=600
                cache_group_repair_capable
                cache_tree_preflight bootstrap/cache
                maintenance_attempted=true
                step=permissions_and_cache
                repair_cache_tree bootstrap/cache; exit 99 ;;
            missing_repair)
                modes[bootstrap/cache/config.php]=600
                cache_group_repair_capable
                maintenance_attempted=true
                step=permissions_and_cache
                verify_cache_tree bootstrap/cache; exit 99 ;;
        esac
    ' 2>&1)
    status=$?
    set -e
    [[ "$fixture" == /tmp/* && -d "$fixture" ]]
    rm -r -- "$fixture"
    [[ "$status" == "$expected_code" ]]
    if [[ "$expected_code" == 0 ]]; then
        grep -Fq 'CACHE_REPAIR=PASS' <<<"$output"
    else
        grep -Fq "STOPPED_STAGE=$expected_stage" <<<"$output"
        grep -Fq "ORIGINAL_EXIT_CODE=$expected_code" <<<"$output"
        grep -Fq "MAINTENANCE_RECOVERY=$expected_recovery" <<<"$output"
    fi
    printf 'PASS %s\n' "$scenario"
}

run_case mixed_ownership 0 cache_permission_preflight NOT_NEEDED
run_case already_secure 0 cache_permission_preflight NOT_NEEDED
run_case foreign_owner_needs_chmod 1 cache_permission_preflight NOT_NEEDED
run_case foreign_owner_wrong_group 1 cache_permission_preflight NOT_NEEDED
run_case foreign_owner_world_writable 1 cache_permission_preflight NOT_NEEDED
run_case missing_membership 1 cache_permission_preflight NOT_NEEDED
run_case generated_cache_wrong_group 0 permissions_and_cache NOT_NEEDED
run_case group_change_fails 45 permissions_and_cache DISABLED
run_case mode_change_fails 44 permissions_and_cache DISABLED
run_case missing_repair 1 permissions_and_cache DISABLED
