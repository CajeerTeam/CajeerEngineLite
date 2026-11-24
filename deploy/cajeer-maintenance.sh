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
        CAJEER_UPDATE_TRUST_POLICY_FILE="${CAJEER_UPDATE_TRUST_POLICY_FILE:-}" \
        CAJEER_AUTO_UPDATE_WINDOW_UTC="${CAJEER_AUTO_UPDATE_WINDOW_UTC:-}" \
        CAJEER_AUTO_UPDATE_WORKERS_QUIESCED="$quiesced" \
        CAJEER_UPDATE_CONFIRM="${rollback_confirmation:-}" \
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
    health_host=${CAJEER_MAINTENANCE_HEALTH_HOST:-}
    health_port=${CAJEER_MAINTENANCE_HEALTH_PORT:-8443}
    health_ca=${CAJEER_MAINTENANCE_HEALTH_CA_FILE:-}
    # Configuration is root-owned. Never derive an address or CA from CMS data.
    [[ "$health_host" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ && ${#health_host} -le 253 && "$health_host" != *..* && "$health_port" =~ ^[1-9][0-9]{0,4}$ && "$health_port" -le 65535 ]] || { fail 'Configure a canonical HTTPS health host and loopback port'; return 1; }
    if [[ -n "$health_ca" ]]; then
        [[ "$health_ca" == /* && -f "$health_ca" && ! -L "$health_ca" && $(stat -c '%u' "$health_ca") == 0 && $(stat -c '%a' "$health_ca") =~ ^[46][04][04]$ ]] || { fail 'Health CA must be a root-owned non-writable-by-others regular file'; return 1; }
    fi
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
            [[ " $read_only " == *" $site_root "* && " $read_only " == *" $site_root/storage/app/updates "* && $(prop "$unit" NoNewPrivileges) == yes ]] || { fail "Missing code read-only mount or NoNewPrivileges: $unit"; return 1; }
            writable=$(prop "$unit" ReadWritePaths) || return 1
            read -r -a rw_paths <<< "$writable"
            for path in "${rw_paths[@]}"; do
                path=${path#-}; path=${path#+}
                case "$path" in
                    "$site_root/storage/app/updates"|"$site_root/storage/app/updates/"*) fail "Writable update transaction storage in $unit"; return 1;;
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
# Require a managed external policy before any new unattended installation.
# Recovery remains possible after key revocation and even after reverting to
# pre-policy code. The old version remains held for operator review.
validate_trust() {
    [[ -n ${CAJEER_UPDATE_TRUST_POLICY_FILE:-} ]] || { fail 'Managed update trust policy required'; return 1; }
    worker 0 trust status | /usr/bin/head -c 16384 | /usr/bin/python3 -I -c '
import json,sys
s=json.load(sys.stdin)
assert s["mode"]=="managed" and len(s["active_fingerprints"])>0
' || { fail 'Invalid or unavailable managed update trust policy'; return 1; }
}
# Read only bounded, validated values from the unprivileged CLI. No returned
# path, command, environment or shell code is ever consumed by root.
read_status() {
    local line
    line=$(worker 0 status | /usr/bin/head -c 16384 | /usr/bin/python3 -I -c '
import json,re,sys
s=json.load(sys.stdin)
v=s["installed_version"]; last=s["last"]
version=lambda x: isinstance(x,str) and re.fullmatch(r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)",x) and len(x)<=64
assert version(v) and s["pending"] is None
if last is None: print(v,"- - -")
else:
    assert isinstance(last["id"],str) and re.fullmatch(r"[0-9a-f]{24}",last["id"]) and version(last["from"]) and version(last["to"])
    print(v,last["id"],last["from"],last["to"])
') || return 1
    read -r current_version current_id current_from current_to <<< "$line"
    [[ -n "$current_version" && -n "$current_id" ]] || return 1
}
health_probe() {
    local version=$1 tmp code headers attempt
    local -a ca=()
    [[ -z "$health_ca" ]] || ca=(--cacert "$health_ca")
    tmp=$(/usr/bin/mktemp -d "$runtime_dir/.health.XXXXXXXX") || return 1
    for attempt in 1 2 3 4 5; do
        # Fixed loopback destination, canonical TLS SNI/Host, no redirect,
        # no proxy or curlrc, bounded response. Never send credentials.
        if code=$(/usr/bin/curl --disable --noproxy '*' --proto '=https' \
            --connect-to "$health_host:443:127.0.0.1:$health_port" \
            --connect-timeout 2 --max-time 5 --max-filesize 4096 \
            --silent --show-error "${ca[@]}" --header 'Cache-Control: no-cache' \
            --dump-header "$tmp/headers" --output "$tmp/body" --write-out '%{http_code}' \
            "https://$health_host/health/ready" 2>/dev/null) && [[ "$code" == 200 ]] && \
            /usr/bin/python3 -I - "$tmp/headers" "$tmp/body" "$version" 2>/dev/null <<'PYHEALTH'
import json,sys
from pathlib import Path
h,b,v=sys.argv[1:]
headers=Path(h).read_text(encoding='iso-8859-1').splitlines()
assert len(headers)>0 and headers[0].split()[1]=='200'
def values(name):
    return [x.split(':',1)[1].strip() for x in headers if x.lower().startswith(name+':')]
assert values('x-cajeer-release')==[v]
assert len(values('content-type'))==1 and values('content-type')[0].split(';')[0].strip().lower()=='application/json'
assert any('no-store' in [i.strip().lower() for i in x.split(',')] for x in values('cache-control'))
assert json.loads(Path(b).read_bytes())=={'status':'ready'}
PYHEALTH
        then
            rm -rf -- "$tmp"; log "HTTPS readiness passed for $version"; return 0
        fi
        [[ $attempt == 5 ]] || sleep 2
    done
    rm -rf -- "$tmp"; fail "HTTPS readiness failed for $version"
}
# The gate itself contains the baseline and rollback intent. Atomic replacement
# keeps the public gate continuously present across phase changes and crashes.
write_gate() {
    local tmp i
    tmp=$(/usr/bin/mktemp "$state_dir/.active.XXXXXXXX") || return 1
    if ! { printf '%s\n' 'cajeer-maintenance-v2' "$baseline $baseline_id $phase" > "$tmp" && \
        for i in "${!units[@]}"; do printf '%s %s\n' "${previous[i]}" "${units[i]}"; done >> "$tmp" && \
        chmod 0644 "$tmp" && /usr/bin/sync -f "$tmp" && mv -T "$tmp" "$state_dir/active" && /usr/bin/sync -f "$state_dir"; }; then
        rm -f -- "$tmp"; return 1
    fi
}
prepare_gate() {
    local unit status
    previous=()
    for unit in "${units[@]}"; do
        status=$(prop "$unit" ActiveState) || return 1
        [[ "$status" == active || "$status" == inactive ]] || { fail "Unit not in a stable state: $unit"; return 1; }
        [[ "$unit" != "$fpm" || "$status" == active ]] || { fail 'FPM must be active before a new update'; return 1; }
        previous+=("$status")
    done
    phase=check
    write_gate || return 1
    log 'Persistent HTTP maintenance gate published'
}
load_gate() {
    local -a lines=()
    [[ ! -L "$state_dir/active" && -f "$state_dir/active" && $(stat -c '%u:%a:%h' "$state_dir/active") == "$EUID:644:1" ]] || { fail 'Unsafe maintenance journal'; return 1; }
    mapfile -t lines < "$state_dir/active"
    [[ ${#lines[@]} == $((${#units[@]}+2)) && ${lines[0]} == cajeer-maintenance-v2 ]] || { fail 'Invalid or legacy maintenance journal; operator recovery required'; return 1; }
    local extra
    read -r baseline baseline_id phase extra <<< "${lines[1]}"
    [[ "$baseline" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ && ( "$baseline_id" == - || "$baseline_id" =~ ^[a-f0-9]{24}$ ) && ( "$phase" == check || "$phase" == failed || "$phase" == rollback ) && -z "$extra" ]] || { fail 'Invalid health recovery state'; return 1; }
    previous=()
    local i value
    for i in "${!units[@]}"; do
        value=${lines[i+2]}
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
# Keep background jobs stopped until the new FPM serves verified readiness.
start_and_probe() {
    worker 1 maintenance verify || return 1
    : > "$runtime_dir/start-permitted" || return 1
    chmod 0644 "$runtime_dir/start-permitted" || return 1
    system start -- "$fpm" && [[ $(prop "$fpm" ActiveState) == active ]] && health_probe "$current_version" || { rm -f -- "$runtime_dir/start-permitted"; return 1; }
    local i unit
    for ((i=${#units[@]}-2;i>=0;i--)); do
        unit=${units[i]}
        if [[ ${previous[i]} == active ]]; then
            system start -- "$unit" && [[ $(prop "$unit" ActiveState) == active ]] || { rm -f -- "$runtime_dir/start-permitted"; return 1; }
        fi
    done
    [[ $(prop "$fpm" ActiveState) == active ]] && health_probe "$current_version" || { rm -f -- "$runtime_dir/start-permitted"; return 1; }
}
finish_gate() {
    rm -f -- "$state_dir/active" || return 1
    /usr/bin/sync -f "$state_dir" || return 1
    rm -f -- "$runtime_dir/start-permitted" || return 1
    log 'Services restored and healthy; HTTP maintenance gate removed'
}
# Only roll back the exact new engine transaction from this lifecycle. Never
# guess an ID or roll back an earlier unrelated install. Recovery is idempotent
# even if the process died during rollback or before the old FPM restarted.
identify_candidate() {
    read_status || return 1
    if [[ "$current_version" == "$baseline" ]]; then
        # Same version: previous transaction or a completed recovery/rollback.
        [[ "$current_id" == "$baseline_id" || "$current_id" == - ]] || { fail 'Unexpected same-version update transaction'; return 1; }
        candidate=0
    else
        [[ "$current_id" != - && "$current_id" != "$baseline_id" && "$current_from" == "$baseline" && "$current_to" == "$current_version" ]] || { fail 'Cannot identify exact update transaction'; return 1; }
        candidate=1
    fi
}
rollback_and_resume() {
    phase=rollback
    write_gate || return 1
    # Persistent hold survives rollback to older PHP code and host reboot.
    # Require explicit operator acknowledgement before retrying any update.
    local tmp
    tmp=$(/usr/bin/mktemp "$state_dir/.hold.XXXXXXXX") || return 1
    if ! { printf 'Automatic health rollback; baseline %s\n' "$baseline" > "$tmp" && /usr/bin/sync -f "$tmp" && mv -T "$tmp" "$state_dir/hold" && /usr/bin/sync -f "$state_dir"; }; then
        rm -f -- "$tmp"; return 1
    fi
    stop_units && worker 1 maintenance recover && identify_candidate || return 1
    if [[ "$candidate" == 1 ]]; then
        log "Rolling back transaction $current_id ($current_version -> $baseline)"
        rollback_confirmation=ROLLBACK worker 1 rollback "$current_id" || return 1
    fi
    identify_candidate && [[ "$candidate" == 0 ]] || return 1
    if ! start_and_probe; then stop_units || return 1; fail 'Rollback version failed readiness; gate retained'; return 1; fi
    finish_gate || return 1
    log 'Previous version healthy; automatic updates held pending operator review'
    return 3
}
restore_services() {
    worker 1 maintenance recover && identify_candidate || return 1
    if [[ "$phase" == rollback || ( "$phase" == failed && "$candidate" == 1 ) ]]; then rollback_and_resume || return $?; return 0; fi
    if start_and_probe; then finish_gate; return $?; fi
    if [[ "$candidate" == 1 ]]; then rollback_and_resume || return $?; return 0; fi
    # Baseline itself is unhealthy, or no update was installed. Never roll
    # back a pre-existing transaction. Keep the gate for operator/retry.
    stop_units || return 1
    fail 'Baseline failed verification; maintenance gate retained'
}
cycle() {
    local decision rc=0
    if [[ -e "$state_dir/active" || -L "$state_dir/active" ]]; then
        log 'Recovering interrupted maintenance before scheduling any new job'
        validate_units && load_gate && stop_units || return 1
        restore_services || return $?
        return 0
    fi
    rm -f -- "$runtime_dir/start-permitted" || return 1
    if [[ -e "$state_dir/hold" || -L "$state_dir/hold" ]]; then
        fail 'Automatic updates held after rollback; operator review required'; return 1
    fi
    decision=$(worker 0 maintenance plan | /usr/bin/head -c 32) || return 1
    case "$decision" in
        tick) worker 0 scheduler tick; return $?;;
        blocked) fail 'Unmanaged recovery/maintenance pending; operator action required'; return 1;;
        install) ;;
        *) fail 'Invalid maintenance decision'; return 1;;
    esac
    validate_trust && validate_units && read_status && health_probe "$current_version" || return 1
    baseline=$current_version; baseline_id=$current_id
    prepare_gate && load_gate && stop_units || return 1
    worker 1 scheduler tick || rc=$?
    # A worker can fail after publishing a valid receipt (e.g. its queue or
    # heartbeat write fails). Do not accept that candidate solely on readiness.
    # Persist this intent before attempting recovery; reboot must not lose it.
    if [[ "$rc" != 0 ]]; then phase=failed; write_gate || return 1; fi
    restore_services || return $?
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
