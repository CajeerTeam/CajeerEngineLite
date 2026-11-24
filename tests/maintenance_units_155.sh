#!/bin/bash
set -euo pipefail
root=$(cd "$(dirname "$0")/.." && pwd)
command -v systemd-analyze >/dev/null
fixture_dir=$(mktemp -d)
trap 'rm -rf "$fixture_dir"' EXIT
cp "$root/deploy/cajeer-maintenance.sh" "$fixture_dir/cajeer-maintenance"
chmod +x "$fixture_dir/cajeer-maintenance"
cp "$root/deploy/cajeer-maintenance.env.conf" "$fixture_dir/maintenance.env"
sed -e "s#/usr/local/sbin/cajeer-maintenance#$fixture_dir/cajeer-maintenance#g" \
    -e "s#/etc/cajeer/maintenance.env#$fixture_dir/maintenance.env#g" \
    -e "s#/srv/cajeer#$fixture_dir#g" "$root/deploy/cajeer-maintenance.service" > "$fixture_dir/cajeer-maintenance.service"
cp "$root/deploy/cajeer-maintenance.timer.conf" "$fixture_dir/cajeer-maintenance.timer"
cat > "$fixture_dir/fixture-fpm.service" <<UNIT
[Unit]
Description=Maintenance test fixture (not started)
[Service]
ExecStart=/usr/bin/sleep infinity
UNIT
sed -e "s#/usr/local/sbin/cajeer-maintenance#$fixture_dir/cajeer-maintenance#g" \
    -e "s#/srv/cajeer#$fixture_dir#g" "$root/deploy/cajeer-maintenance-guard.conf" >> "$fixture_dir/fixture-fpm.service"
SYSTEMD_UNIT_PATH="$fixture_dir:/usr/lib/systemd/system:/lib/systemd/system" systemd-analyze verify \
    "$fixture_dir/cajeer-maintenance.service" "$fixture_dir/cajeer-maintenance.timer" "$fixture_dir/fixture-fpm.service"
systemd-analyze calendar '*-*-* *:*:00' >/dev/null
echo 'Maintenance unit, timer and guard syntax OK (not live service control)'
