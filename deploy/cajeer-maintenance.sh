#!/bin/bash
# Install a reviewed copy OUTSIDE the PHP-writable tree, root:root 0755.
# Never run this supervisor from an automatically updated application directory.
set -euo pipefail
umask 077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
state_dir=/var/lib/cajeer-maintenance
runtime_dir=/run/cajeer-maintenance

log() { printf 'cajeer-maintenance: %s\n' "$*" >&2; }
fail() { log "$*"; return 1; }
system() { /usr/bin/systemctl --no-ask-password "$@"; }
prop() { system show --property="$2" --value -- "$1"; }
# Only this fixed, operator-installed guard may admit service startup during a
# persistent gate. /run is wiped on reboot; no permission survives a crash/reboot.
guard() { [[ ! -e "$state_dir/active" || -f "$runtime_dir/start-permitted" ]]; }

worker() {
    local quiesced=$1; shift
    # No CMS-writable executable runs as root. Only the fixed CLI and vetted
    # operator environment reach this site account; no shell or eval.
    /usr/sbin/runuser -u "$site_user" -- /usr/bin/env -i \
        PATH=/usr/bin:/bin HOME="$site_home" LANG=C.UTF-8 \
        CAJEER_UPDATE_CONTROL_WORKER=1 \
        CAJEER_AUTO_UPDATE_ENABLED="${CAJEER_AUTO_UPDATE_ENABLED:-0}" \
        CAJEER_AUTO_UPDATE_INSTALL="${CAJEER_AUTO_UPDATE_INSTALL:-0}" \
        CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL="${CAJEER_AUTO_UPDATE_ALLOW_PREVIEW_INSTALL:-0}" \
        CAJEER_AUTO_UPDATE_GITHUB_REPO="${CAJEER_AUTO_UPDATE_GITHUB_REPO:-}" \
        CAJEER_UPDATE_TRUSTED_KEY_FILE="${CAJEER_UPDATE_TRUSTED_KEY_FILE:-}" \
        CAJEER_AUTO_UPDATE_WINDOW_UTC="${CAJEER_AUTO_UPDATE_WINDOW_UTC:-}" \
        CAJEER_AUTO_UPDATE_WORKERS_QUIESCED="$quiesced" \
        /usr/bin/php "$site_root/bin/cajeer" update "$@" 9>&-
}

# Background timers first, then background services, FPM last. Restore reverse.
configure() {
    site_root=${CAJEER_MAINTENANCE_ROOT:-/srv/cajeer}
    site_user=${CAJEER_MAINTENANCE_USER:-cajeer}
    fpm=${CAJEER_MAINTENANCE_FPM_UNIT:-}
    [[ "$site_root" =~ ^/[A-Za-z0-9_./-]+$ && "$site_root" == "$(realpath -e -- "$site_root")" && -f "$site_root/bin/cajeer" ]] || { fail 'Invalid application root (use its canonical absolute path)'; return 1; }
    [[ "$site_user" =~ ^[a-z_][a-z0-9_-]*$ ]] && [[ $(/usr/bin/id -u "$site_user") =~ ^[1-9][0-9]*$ ]] || { fail 'Non-root site user required'; return 1; }
    site_home=$(/usr/bin/getent passwd "$site_user" | /usr/bin/cut -d: -f6)
    [[ -n "$site_home" && "$fpm" == *.service ]] || { fail 'Dedicated FPM service required'; return 1; }
    local -a background=() timers=() services=()
    read -r -a background <<< "${CAJEER_MAINTENANCE_BACKGROUND_UNITS:-}"
    local unit
    declare -A seen=()
    for unit in "${background[@]}" "$fpm"; do
        [[ "$unit" =~ ^[A-Za-z0-9][A-Za-z0-9_.@-]*\.(service|timer)$ && "$unit" != cajeer-maintenance* && "$unit" != cajeer-update-scheduler* && ! ${seen[$unit]+present} ]] || { fail 'Invalid or duplicate managed unit'; return 1; }
        seen[$unit]=1
        if [[ "$unit" == *.timer ]]; then timers+=("$unit"); else services+=("$unit"); fi
    done
    units=("${timers[@]}" "${services[@]}")
}
validate_units() {
    local unit mode condition load trigger candidate found path read_only writable
    local -a rw_paths=()
    for unit in "${units[@]}"; do
        load=$(prop "$unit" LoadState) || return 1
        [[ "$load" == loaded ]] || { fail "Unit not loaded: $unit"; return 1; }
        if [[ "$unit" == *.service ]]; then
            mode=$(prop "$unit" KillMode) || return 1
            [[ "$mode" == control-group || "$mode" == mixed ]] || { fail "Unsafe KillMode: $unit"; return 1; }
            [[ $(prop "$unit" SendSIGKILL) == yes ]] || { fail "SendSIGKILL must be enabled: $unit"; return 1; }
            read_only=$(prop "$unit" ReadOnlyPaths) || return 1
            [[ " $read_only " == *" $site_root "* && $(prop "$unit" NoNewPrivileges) == yes ]] || { fail "Missing code read-only mount or NoNewPrivileges: $unit"; return 1; }
            writable=$(prop "$unit" ReadWritePaths) || return 1
            read -r -a rw_paths <<< "$writable"
            for path in "${rw_paths[@]}"; do
                path=${path#-}; path=${path#+}
                case "$path" in
                    "$site_root/storage"|"$site_root/storage/"*) continue;;
                    "$site_root"|"$site_root/"*) fail "Writable application code in $unit"; return 1;;
                esac
                [[ "$site_root/" != "${path%/}/"* ]] || { fail "Writable application ancestor in $unit"; return 1; }
            done
            condition=$(prop "$unit" ExecCondition) || return 1
            [[ "$condition" == *'argv[]=/usr/local/sbin/cajeer-maintenance guard ;'* && "$condition" != *'ignore_errors=yes'* ]] || { fail "Missing mandatory startup guard: $unit"; return 1; }
        else
            trigger=$(prop "$unit" Triggers) || return 1
            found=0
            for candidate in "${units[@]}"; do
                if [[ "$candidate" == *.service && "$trigger" == "$candidate" ]]; then found=1; fi
            done
            [[ "$found" == 1 ]] || { fail "Timer must trigger exactly one managed service: $unit"; return 1; }
        fi
    done
}
prepare_gate() {
    local unit status tmp
    local -a entries=()
    for unit in "${units[@]}"; do
        status=$(prop "$unit" ActiveState) || return 1
        [[ "$status" == active || "$status" == inactive ]] || { fail "Unit not in a stable state: $unit"; return 1; }
        [[ "$unit" != "$fpm" || "$status" == active ]] || { fail 'FPM must be active before a new update'; return 1; }
        entries+=("$status $unit")
    done
    tmp=$(/usr/bin/mktemp "$state_dir/.active.XXXXXXXX") || return 1
    if ! { printf '%s\n' 'cajeer-maintenance-v1' "${entries[@]}" > "$tmp" && chmod 0644 "$tmp" && /usr/bin/sync -f "$tmp" && mv -T "$tmp" "$state_dir/active" && /usr/bin/sync -f "$state_dir"; }; then
        rm -f -- "$tmp"; return 1
    fi
    log 'Persistent HTTP maintenance gate published'
}
load_gate() {
    local -a lines=()
    [[ ! -L "$state_dir/active" && -f "$state_dir/active" && $(stat -c '%u:%a:%h' "$state_dir/active") == "$EUID:644:1" ]] || { fail 'Unsafe maintenance journal'; return 1; }
    mapfile -t lines < "$state_dir/active"
    [[ ${#lines[@]} == $((${#units[@]}+1)) && ${lines[0]} == cajeer-maintenance-v1 ]] || { fail 'Invalid maintenance journal'; return 1; }
    previous=()
    local i value
    for i in "${!units[@]}"; do
        value=${lines[i+1]}
        case "$value" in
            "active ${units[i]}") previous+=(active);;
            "inactive ${units[i]}") previous+=(inactive);;
            *) fail 'Managed unit configuration changed during maintenance'; return 1;;
        esac
    done
    [[ ${previous[${#previous[@]}-1]} == active ]] || { fail 'Invalid original FPM state'; return 1; }
}
stop_units() {
    rm -f -- "$runtime_dir/start-permitted" || return 1
    local unit
    for unit in "${units[@]}"; do
        system stop -- "$unit" || return 1
    done
    for unit in "${units[@]}"; do
        [[ $(prop "$unit" ActiveState) == inactive ]] || { fail "Service not stopped: $unit"; return 1; }
        if [[ "$unit" == *.service ]]; then
            [[ $(prop "$unit" MainPID) == 0 && $(prop "$unit" ControlPID) == 0 ]] || { fail "Service still has processes: $unit"; return 1; }
        fi
    done
    log 'All managed services stopped'
}
restore_services() {
    # Recover first, then use a new PHP process to verify the actual runtime.
    worker 1 maintenance recover || return 1
    worker 1 maintenance verify || return 1
    : > "$runtime_dir/start-permitted" || return 1
    chmod 0644 "$runtime_dir/start-permitted" || return 1
    local i unit
    for ((i=${#units[@]}-1;i>=0;i--)); do
        unit=${units[i]}
        if [[ ${previous[i]} == active ]]; then
            system start -- "$unit" || { rm -f -- "$runtime_dir/start-permitted"; return 1; }
            [[ $(prop "$unit" ActiveState) == active ]] || { rm -f -- "$runtime_dir/start-permitted"; fail "Service did not restart: $unit"; return 1; }
        fi
    done
    # FPM must still be active after background service dependencies start.
    [[ $(prop "$fpm" ActiveState) == active ]] || { rm -f -- "$runtime_dir/start-permitted"; fail 'FPM not active after restart'; return 1; }
    # Remove durable gate only after services have actually started. If power
    # fails before this point, the next invocation follows the recovery branch.
    rm -f -- "$state_dir/active" || return 1
    /usr/bin/sync -f "$state_dir" || return 1
    rm -f -- "$runtime_dir/start-permitted" || return 1
    log 'Services restored; HTTP maintenance gate removed'
}
cycle() {
    local decision rc=0
    if [[ -e "$state_dir/active" || -L "$state_dir/active" ]]; then
        log 'Recovering interrupted maintenance before scheduling any new job'
        validate_units && load_gate && stop_units && restore_services || return 1
        return 0
    fi
    rm -f -- "$runtime_dir/start-permitted" || return 1
    decision=$(worker 0 maintenance plan | /usr/bin/head -c 32) || return 1
    case "$decision" in
        tick) worker 0 scheduler tick; return $?;;
        blocked) fail 'Unmanaged recovery/maintenance pending; operator action required'; return 1;;
        install) ;;
        *) fail 'Invalid maintenance decision'; return 1;;
    esac
    validate_units && prepare_gate && load_gate && stop_units || return 1
    worker 1 scheduler tick || rc=$?
    # Do not replay the install even if the child failed. Recover only an
    # outstanding engine transaction, restart the original service set, and
    # propagate the failure for journald/monitoring.
    restore_services || return 1
    return "$rc"
}
main() {
    [[ $# == 0 && $EUID == 0 ]] || { fail 'Root supervisor (no arguments) required'; return 1; }
    cd /
    configure || return 1
    local directory
    for directory in "$state_dir" "$runtime_dir"; do
        [[ ! -L "$directory" ]] || { fail 'Unsafe state directory'; return 1; }
        /usr/bin/install -d -m 0755 -o root -g root "$directory" || return 1
        [[ $(stat -c '%u:%a' "$directory") == '0:755' ]] || return 1
    done
    [[ ! -L "$state_dir/lock" ]] || return 1
    exec 9>>"$state_dir/lock"
    /usr/bin/flock -n 9 || { log 'Another supervisor is running'; return 0; }
    cycle
}
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    if [[ $# == 1 && $1 == guard ]]; then guard; exit $?; fi
    main "$@"
fi
