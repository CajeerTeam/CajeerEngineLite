#!/bin/bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
# Exercise the deployed supervisor's actual state machine without controlling
# any services on the test host. Override only the OS/CLI boundary in this shell.
source "$root/deploy/cajeer-maintenance.sh"
fixture_dir=$(mktemp -d)
trap 'rm -rf "$fixture_dir"' EXIT
state_dir=$fixture_dir/state; runtime_dir=$fixture_dir/run
mkdir "$state_dir" "$runtime_dir" "$fixture_dir/units"
site_root=/srv/cajeer
units=(job.timer job.service php-test.service); fpm=php-test.service
logfile=$fixture_dir/events
reset() {
    rm -f "$state_dir/active" "$runtime_dir/start-permitted" "$state_dir/hold"
    printf 'active\n' > "$fixture_dir/units/job.timer"
    printf 'inactive\n' > "$fixture_dir/units/job.service"
    printf 'active\n' > "$fixture_dir/units/$fpm"
    : > "$logfile"
    mock_decision=install; bad_stop=; bad_start=; bad_tick=0; bad_verify=0; bad_guard=0; bad_mount=0; bad_trust=0; bad_history=0; bad_health=; install_new=0; bad_rollback=0
    printf '1.5.5 - - -\n' > "$fixture_dir/version"
}
validate_trust() { [[ $bad_trust == 0 ]]; }
health_probe() {
    printf 'health %s\n' "$1" >> "$logfile"
    [[ $(prop "$fpm" ActiveState) == active && "$1" != "$bad_health" ]]
}
prop() {
    case "$2" in
        LoadState) echo loaded;;
        KillMode) echo control-group;;
        SendSIGKILL|NoNewPrivileges) echo yes;;
        ReadOnlyPaths) [[ $bad_history == 0 ]] && echo "$site_root $site_root/storage/app/updates" || echo "$site_root";;
        ReadWritePaths) [[ ${bad_mount:-0} == 0 ]] && echo "$site_root/storage" || echo "$site_root";;
        ExecCondition) [[ $bad_guard == 0 ]] && echo '{ path=/usr/local/sbin/cajeer-maintenance ; argv[]=/usr/local/sbin/cajeer-maintenance guard ; ignore_errors=no ; }' || echo '';;
        Triggers) echo job.service;;
        ActiveState) cat "$fixture_dir/units/$1";;
        MainPID|ControlPID) echo 0;;
        *) return 1;;
    esac
}
system() {
    local cmd=$1 unit=$3
    printf '%s %s\n' "$cmd" "$unit" >> "$logfile"
    [[ -f "$state_dir/active" ]] || return 1
    if [[ $cmd == stop ]]; then
        if guard; then return 1; fi
        [[ $unit != "$bad_stop" ]] || return 1
        echo inactive > "$fixture_dir/units/$unit"
    else
        guard || return 1
        [[ $unit != "$bad_start" ]] || return 1
        echo active > "$fixture_dir/units/$unit"
    fi
}
worker() {
    local q=$1; shift
    printf '%s %s\n' "$q" "$*" >> "$logfile"
    case "$*" in
        'maintenance plan') echo "$mock_decision";;
        'status')
            local v id from to
            read -r v id from to < "$fixture_dir/version"
            if [[ $id == - ]]; then printf '{"installed_version":"%s","pending":null,"last":null}\n' "$v"
            else printf '{"installed_version":"%s","pending":null,"last":{"id":"%s","from":"%s","to":"%s"}}\n' "$v" "$id" "$from" "$to"; fi;;
        'rollback 0123456789abcdef01234567')
            [[ $q == 1 && $rollback_confirmation == ROLLBACK && -f "$state_dir/hold" && $(prop "$fpm" ActiveState) == inactive ]] || return 1
            [[ $bad_rollback == 0 ]] || return 1
            printf '1.5.5 - - -\n' > "$fixture_dir/version";;
        'scheduler tick')
            if [[ $q == 1 ]]; then
                [[ -f "$state_dir/active" ]] || return 1
                for unit in "${units[@]}"; do [[ $(prop "$unit" ActiveState) == inactive ]] || return 1; done
            else [[ ! -f "$state_dir/active" ]] || return 1; fi
            if [[ $q == 1 && $install_new == 1 ]]; then
                printf '1.5.6 0123456789abcdef01234567 1.5.5 1.5.6\n' > "$fixture_dir/version"
            fi
            return "$bad_tick";;
        'maintenance recover'|'maintenance verify')
            [[ $q == 1 && -f "$state_dir/active" && $(prop "$fpm" ActiveState) == inactive ]] || return 1
            if guard; then return 1; fi
            [[ "$*" != 'maintenance verify' || $bad_verify == 0 ]];;
        *) return 1;;
    esac
}
assert_restored() {
    [[ ! -e "$state_dir/active" && ! -e "$runtime_dir/start-permitted" ]]
    [[ $(prop "$fpm" ActiveState) == active && $(prop job.timer ActiveState) == active && $(prop job.service ActiveState) == inactive ]]
}
reset; mock_decision=tick; cycle; [[ $(cat "$logfile") == $'0 maintenance plan\n0 scheduler tick' ]]; assert_restored
reset; mock_decision=blocked; if cycle; then exit 1; fi; assert_restored
reset; bad_trust=1; if cycle; then exit 1; fi; assert_restored
reset; bad_history=1; if cycle; then exit 1; fi; assert_restored
reset; bad_mount=1; if cycle; then exit 1; fi; assert_restored
reset; bad_guard=1; if cycle; then exit 1; fi; assert_restored
reset; cycle; assert_restored
[[ $(cat "$logfile") == $'0 maintenance plan\n0 status\nhealth 1.5.5\nstop job.timer\nstop job.service\nstop php-test.service\n1 scheduler tick\n1 maintenance recover\n0 status\n1 maintenance verify\nstart php-test.service\nhealth 1.5.5\nstart job.timer\nhealth 1.5.5' ]]
reset; bad_tick=7; rc=0; cycle || rc=$?; [[ $rc == 7 ]]; assert_restored
reset; bad_stop=php-test.service; if cycle; then exit 1; fi; [[ -f "$state_dir/active" ]]; if grep -q 'scheduler tick' "$logfile"; then exit 1; fi
bad_stop=; : > "$logfile"; cycle; assert_restored; if grep -q 'scheduler tick\|maintenance plan' "$logfile"; then exit 1; fi
reset; bad_verify=1; if cycle; then exit 1; fi; [[ -f "$state_dir/active" && $(prop "$fpm" ActiveState) == inactive ]]; if guard; then exit 1; fi
bad_verify=0; : > "$logfile"; cycle; assert_restored; if grep -q 'scheduler tick' "$logfile"; then exit 1; fi
reset; bad_start=job.timer; if cycle; then exit 1; fi; [[ -f "$state_dir/active" ]]; if guard; then exit 1; fi
bad_start=; : > "$logfile"; cycle; assert_restored; if grep -q 'scheduler tick' "$logfile"; then exit 1; fi
reset; bad_stop=php-test.service; if cycle; then exit 1; fi
printf 'corrupt\n' > "$state_dir/active"; : > "$logfile"; if cycle; then exit 1; fi; [[ -f "$state_dir/active" && ! -s "$logfile" ]]
# Candidate health failure -> exact rollback, old version health, persistent hold.
reset; install_new=1; bad_health=1.5.6; rc=0; cycle || rc=$?; [[ $rc == 3 ]]; assert_restored
[[ -f "$state_dir/hold" && $(cat "$fixture_dir/version") == '1.5.5 - - -' ]]
grep -q '1 rollback 0123456789abcdef01234567' "$logfile"
: > "$logfile"; if cycle; then exit 1; fi; [[ ! -s "$logfile" ]]
# Failed rollback preserves the gate and rollback phase; reboot/retry resumes it.
reset; install_new=1; bad_health=1.5.6; bad_rollback=1; if cycle; then exit 1; fi
[[ -f "$state_dir/active" && -f "$state_dir/hold" ]]; grep -q ' rollback$' "$state_dir/active"
bad_rollback=0; : > "$logfile"; rc=0; cycle || rc=$?; [[ $rc == 3 ]]; assert_restored
if grep -q 'scheduler tick' "$logfile"; then exit 1; fi
# Unhealthy baseline never begins an installation.
reset; bad_health=1.5.5; if cycle; then exit 1; fi; assert_restored
if grep -q 'scheduler tick\|stop ' "$logfile"; then exit 1; fi
# A previous unrelated receipt is never rolled back when no new install occurred.
reset; printf '1.5.5 111111111111111111111111 1.5.4 1.5.5\n' > "$fixture_dir/version"; bad_verify=1
if cycle; then exit 1; fi; [[ -f "$state_dir/active" ]]; if grep -q '1 rollback ' "$logfile"; then exit 1; fi
# New release succeeds; no hold is created.
reset; install_new=1; cycle; assert_restored; [[ ! -e "$state_dir/hold" ]]; grep -q 'health 1.5.6' "$logfile"
echo 'Maintenance supervisor: gate before drain, no downtime for checks, stop/start order, interrupted recovery, fail-closed guard/journal and failure propagation OK'
