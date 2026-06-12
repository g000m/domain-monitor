#!/usr/bin/env bash
# Smoke test against a running wp-env instance.
# Verifies the behaviors unit tests cannot: real activation, schema creation,
# cron registration, the localhost auto-detection guard, the
# DOMAIN_MONITOR_PRIMARY_DOMAIN override, and a live check over the network.
#
# Usage: npx wp-env start && bash bin/smoke-test.sh
# Assertions are written to tolerate pre-existing data (a developer's local
# wp-env) as well as a fresh CI instance.
set -euo pipefail

SMOKE_DOMAIN="${SMOKE_DOMAIN:-example.com}"

cli() {
    npx wp-env run cli "$@" 2>/dev/null
}

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

pass() {
    echo "ok: $1"
}

echo "=== domain-monitor smoke test (domain: ${SMOKE_DOMAIN}) ==="

# Start from a known plugin state.
cli wp plugin deactivate domain-monitor >/dev/null 2>&1 || true
cli wp config delete DOMAIN_MONITOR_PRIMARY_DOMAIN >/dev/null 2>&1 || true
cli wp plugin activate domain-monitor >/dev/null || fail "plugin activation"
pass "plugin activates"

# 1. Schema: both tables exist after activation.
tables=$(cli wp db query "SHOW TABLES LIKE '%domainmon%'")
echo "$tables" | grep -q "domainmon_domains" || fail "domains table missing after activation"
echo "$tables" | grep -q "domainmon_alerts" || fail "alerts table missing after activation"
pass "tables created"

# 2. Cron: daily check is scheduled.
cli wp cron event list --fields=hook | grep -q "domain_monitor_daily_check" \
    || fail "daily cron event not scheduled"
pass "daily cron scheduled"

# 3. Localhost guard: a dev host must never be auto-inserted.
if cli wp db query "SELECT domain FROM wp_domainmon_domains WHERE source='auto'" \
    | grep -qE "^(localhost|127\.0\.0\.1)$"; then
    fail "localhost was auto-inserted despite the MonitorableHost guard"
fi
pass "localhost guard holds"

# 4. Constant override: defines the primary domain on a dev site.
cli wp config set DOMAIN_MONITOR_PRIMARY_DOMAIN "$SMOKE_DOMAIN" >/dev/null
cli wp plugin deactivate domain-monitor >/dev/null
cli wp plugin activate domain-monitor >/dev/null
cli wp db query "SELECT domain FROM wp_domainmon_domains WHERE source='auto'" \
    | grep -q "^${SMOKE_DOMAIN}$" || fail "constant override did not insert ${SMOKE_DOMAIN}"
pass "DOMAIN_MONITOR_PRIMARY_DOMAIN override works"

# 5. Live check: cron run produces a full snapshot over the real network.
cli wp cron event run domain_monitor_daily_check >/dev/null || fail "cron event run errored"
row=$(cli wp db query "SELECT snapshot FROM wp_domainmon_domains WHERE domain='${SMOKE_DOMAIN}'\G")
echo "$row" | grep -q '"rdap"' || fail "snapshot missing rdap section"
echo "$row" | grep -q '"dns"'  || fail "snapshot missing dns section"
echo "$row" | grep -q '"ssl"'  || fail "snapshot missing ssl section"
checked=$(cli wp db query "SELECT last_checked_at FROM wp_domainmon_domains WHERE domain='${SMOKE_DOMAIN}'" \
    | grep -cE "^[0-9]{4}-") || true
[ "$checked" -ge 1 ] || fail "last_checked_at not set after cron run"
pass "live check populates snapshot (rdap, dns, ssl)"

# 6. No PHP fatals or uncaught exceptions in the debug log.
if cli bash -c "test -f wp-content/debug.log" >/dev/null 2>&1; then
    if cli bash -c "grep -iE 'fatal|uncaught' wp-content/debug.log" >/dev/null 2>&1; then
        cli bash -c "tail -20 wp-content/debug.log" >&2 || true
        fail "PHP fatals found in debug.log"
    fi
fi
pass "debug.log clean"

echo "=== smoke test passed ==="
