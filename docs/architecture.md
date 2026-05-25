# Domain Monitor — Architecture Document

> Status: architectural baseline for v1 implementation.
> Product name: **Domain Monitor**
> Plugin slug: `domain-monitor`
> Tone: friendly admin copy.
> Target runtime: PHP 7.4+, WordPress 6.6+ where possible.

## 1. Executive summary

Domain Monitor is a self-contained WordPress plugin that watches the site's primary domain and user-added domains for domain-continuity risks: registration/expiration status, DNS record drift, web reachability, redirects, and domain-related status changes.

The v1 product goal is **quiet confidence**: a green-light admin experience when everything is fine, clear amber/red warnings when something changes or degrades, and no dependency on a paid backend or API key.

v1 intentionally avoids email monitoring, webhooks, TXT/SPF/DMARC checks, external account setup, and a full multisite per-site UX. The architecture leaves room for those later without carrying their implementation cost now.

## 2. Hard decisions captured

- **Plugin name:** Domain Monitor.
- **Slug / package:** `domain-monitor`.
- **Copy tone:** friendly, clear, non-alarmist.
- **PHP:** 7.4 minimum.
- **WordPress:** 6.6+ target; support older only when easy and low-cost.
- **Distribution:** GitHub repository, not yet created.
- **Namespace/autoloading:** namespaced PSR-4.
- **Build style:** TDD-first.
- **Data storage:** custom tables for domain state and alerts; WordPress options for settings.
- **Multisite v1:** network-admin only when network-activated; monitor the network primary domain only by default. If per-site activated, behave like a single-site install.
- **UI transport:** `admin-ajax.php` for v1 async actions.
- **Email:** skipped in v1.
- **Webhooks:** skipped in v1.
- **TXT checks:** skipped in v1.
- **Site Health integration:** consider later.
- **Unknown/failing TLD behavior:** show a clear lookup-trouble message. Future version should collect failing TLDs.

## 3. Proposed repository layout

```text
domain-monitor/
├── domain-monitor.php
├── composer.json
├── phpunit.xml.dist
├── uninstall.php
├── readme.txt
├── src/
│   ├── Plugin.php
│   ├── Activation/
│   │   ├── Activator.php
│   │   └── Schema.php
│   ├── Admin/
│   │   ├── AjaxController.php
│   │   ├── Assets.php
│   │   ├── DashboardWidget.php
│   │   ├── NetworkAdminPage.php
│   │   └── SettingsPage.php
│   ├── Checks/
│   │   ├── CheckEngine.php
│   │   ├── CheckResult.php
│   │   ├── DnsChecker.php
│   │   ├── HttpChecker.php
│   │   ├── RdapChecker.php
│   │   └── WwwPolicy.php
│   ├── Diff/
│   │   ├── SnapshotDiffer.php
│   │   └── NamedDiff.php
│   ├── Domain/
│   │   ├── DomainName.php
│   │   ├── DomainRepository.php
│   │   ├── AlertRepository.php
│   │   └── StatusCalculator.php
│   ├── Multisite/
│   │   └── Context.php
│   ├── Scheduler/
│   │   └── Cron.php
│   └── Support/
│       ├── Clock.php
│       ├── Json.php
│       └── WpHttpClient.php
├── assets/
│   ├── admin.css
│   └── admin.js
└── tests/
    ├── Unit/
    ├── Integration/
    └── wp-tests-bootstrap.php
```

The layout favors small services that are easy to unit test without booting WordPress, with thin WordPress adapter classes around hooks, Ajax, options, cron, `$wpdb`, and `wp_remote_get()`.

## 4. Bootstrap and hook architecture

### `domain-monitor.php`

Responsibilities:

- Define plugin constants:
  - `DOMAIN_MONITOR_VERSION`
  - `DOMAIN_MONITOR_FILE`
  - `DOMAIN_MONITOR_DIR`
  - `DOMAIN_MONITOR_BASENAME`
- Require Composer autoloader if present.
- Register activation/deactivation hooks at top level.
- Instantiate `DomainMonitor\Plugin` on `plugins_loaded`.

No heavy work should happen at file load time.

### `Plugin`

Responsibilities:

- Register shared services.
- Register admin hooks only in admin contexts.
- Register cron/check hooks.
- Route single-site vs multisite behavior through `Multisite\Context`.

Suggested hook map:

- `plugins_loaded`: load textdomain and boot services.
- `admin_menu`: register single-site settings/management page.
- `network_admin_menu`: register network-admin page for network mode.
- `wp_dashboard_setup`: register dashboard widget in single-site mode.
- `wp_network_dashboard_setup`: register network dashboard widget in network-admin mode if useful.
- `wp_ajax_domain_monitor_check_now`: run one authorized check.
- `domain_monitor_cron_check`: run due-domain check batch.

## 5. Data model

Use custom tables for operational state and alert history. Use options/network options for settings.

### Table: `domainmon_domains`

Use `$wpdb->prefix` in single-site/per-site mode and `$wpdb->base_prefix` in network mode.

Columns:

- `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
- `domain` VARCHAR(253) NOT NULL
- `domain_hash` CHAR(64) NOT NULL, unique lookup key for normalized domain
- `is_self` TINYINT(1) NOT NULL DEFAULT 0
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `owner_site_id` BIGINT UNSIGNED NOT NULL DEFAULT 0
- `rdap_tier` TINYINT UNSIGNED NOT NULL DEFAULT 0
- `status` TINYINT UNSIGNED NOT NULL DEFAULT 1
  - `0` ok
  - `1` warn/amber
  - `2` fail/red
- `status_reason` VARCHAR(191) NOT NULL DEFAULT ''
- `snapshot` MEDIUMTEXT NULL
- `last_known_good_snapshot` MEDIUMTEXT NULL
- `last_checked_at` DATETIME NULL
- `next_due_at` DATETIME NULL
- `created_at` DATETIME NOT NULL
- `updated_at` DATETIME NOT NULL

Indexes:

- `UNIQUE KEY domain_hash (domain_hash)`
- `KEY next_due_at (next_due_at)`
- `KEY status (status)`
- `KEY is_active (is_active)`
- `KEY owner_site_id (owner_site_id)`

### Table: `domainmon_alerts`

Columns:

- `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
- `domain_id` BIGINT UNSIGNED NOT NULL
- `type` VARCHAR(32) NOT NULL
- `severity` TINYINT UNSIGNED NOT NULL
- `message` VARCHAR(255) NOT NULL
- `details` MEDIUMTEXT NULL — JSON payload for structured diff details.
- `is_read` TINYINT(1) NOT NULL DEFAULT 0
- `is_active` TINYINT(1) NOT NULL DEFAULT 1
- `resolved_at` DATETIME NULL
- `created_at` DATETIME NOT NULL

Indexes:

- `KEY domain_id (domain_id)`
- `KEY active_severity (is_active, severity)`
- `KEY created_at (created_at)`
- `KEY resolved_at (resolved_at)`

### Settings option

Single-site option: `domain_monitor_settings`.

Multisite network option: `domain_monitor_settings`.

Store as a single associative array, not autoloaded when possible:

```php
[
    'expiry_threshold_days' => [30, 14, 7, 1],
    'amber_persistence_days' => 3,
    'check_cadence_hours' => 24,
    'rdap_fallback_cadence_days' => 7,
    'alert_retention_days' => 90,
    'alert_retention_per_domain' => 100,
    'preserve_data_on_uninstall' => true,
]
```

## 6. Snapshot schema

Snapshots are current-state JSON documents. They must be stable, normalized, and sorted so diffing is deterministic.

```json
{
  "schema_version": 1,
  "checked_at": "2026-05-25 00:00:00",
  "domain": "example.com",
  "rdap": {
    "status": "ok",
    "tier": 0,
    "registrar": "Example Registrar",
    "expires_at": "2027-01-01T00:00:00Z",
    "raw_endpoint": "https://www.rdap.net/domain/example.com",
    "message": ""
  },
  "dns": {
    "status": "ok",
    "resolver": "native",
    "apex": {
      "a": ["93.184.216.34"],
      "aaaa": [],
      "mx": [{"priority": 10, "host": "mail.example.com"}],
      "cname": []
    },
    "www": {
      "a": ["93.184.216.34"],
      "aaaa": [],
      "cname": [{"target": "example.com"}]
    }
  },
  "http": {
    "status": "ok",
    "final_url": "https://example.com/",
    "redirect_count": 1,
    "http_status": 200,
    "checked_scheme": "http"
  },
  "www_policy": {
    "status": "ok",
    "mode": "www_redirects_to_apex",
    "message": "www redirects to the primary site"
  },
  "errors": []
}
```

### Last-known-good rule

- Preserve the previous `snapshot` before a check starts.
- If a metric fails, keep the last known good value for that metric in `last_known_good_snapshot` and record the current failure in the new snapshot's `errors` list.
- Do not replace good historical values with empty/null failure values.
- The UI should clearly distinguish:
  - current observed value;
  - last-known-good value;
  - lookup failure message.

## 7. RDAP architecture

### Primary endpoint

Use RDAP directly through:

```text
https://www.rdap.net/domain/{domain}
```

Example:

```text
https://www.rdap.net/domain/example.com
```

### RDAP behavior

- Use WordPress HTTP API via an injectable HTTP client wrapper.
- Timeout should be short and admin-friendly; default 10 seconds.
- Follow redirects when WordPress HTTP API allows.
- Parse expiration from RDAP `events` where `eventAction` indicates expiration/expiry/registration expiration.
- Parse registrar from `registrar`, `entities`, or best-effort available RDAP fields.
- If the TLD/endpoint cannot be looked up, return warn/amber with clear copy:
  - “We had trouble looking up registration details for this TLD. DNS and web checks can still run.”
- Future: record failing TLDs for product diagnostics.

### RDAP cadence

- Normal cadence: daily.
- If RDAP is degraded or rate-limited: throttle RDAP to weekly while DNS/HTTP remain daily.
- Store tier/backoff state at host/network level, not per-domain in multisite network mode.

## 8. DNS architecture

### v1 records

Check:

- Apex A and AAAA.
- `www` A/AAAA/CNAME where applicable.
- Apex MX.
- CNAME where possible.

Skip TXT in v1.

### Native resolver first

Use PHP/native DNS functions first:

- `dns_get_record()` when available.
- Type-specific lookups where needed.

### Fallback resolver

If native DNS lookup fails or is unavailable, use Google DNS-over-HTTPS:

```text
https://dns.google/resolve?name={domain}&type=A
```

Repeat per record type.

### Normalization

- Lowercase hostnames.
- Trim trailing dot for comparison/display unless preserving is useful.
- Sort all record sets.
- Deduplicate values.
- Normalize MX as `{priority, host}`.
- Normalize CNAME as `{target}`.

## 9. www policy

v1 monitors the site's actual host and applies a safety check to the alternate apex/www host.

Rules:

- If the site runs on `www.example.com`, then `www` is primary; apex should redirect or resolve safely according to the observed canonical flow.
- If the site runs on apex `example.com`, `www.example.com` should redirect to the apex for safety.
- Ignore `www` if it is clearly hosting a different site/subdomain/application.
- Do not fail solely because `www` is absent when evidence suggests it is intentionally unrelated; use amber only when ambiguous and potentially risky.

This policy should be encapsulated in `Checks\WwwPolicy` so it can evolve later.

## 10. HTTP architecture

### Request strategy

- Start with `http://{domain}`.
- Follow redirects.
- If HTTP redirects to HTTPS and the final page loads, do not separately re-check HTTPS.
- Store final URL and redirect count.
- Use a normal WordPress/plugin user agent first.
- Later, if blocked by hosts, consider using the admin browser user agent.

### Captured values

- `final_url`
- `redirect_count`
- `http_status`
- `scheme_started`
- `timed_out` / error message if applicable

## 11. Status model

Status is precomputed per domain.

- **OK / green:** all required checks pass; no unresolved active alerts; expiration outside thresholds.
- **Warn / amber:** degraded-but-not-failed state, RDAP lookup trouble, benign DNS/HTTP changes, expiration inside warning windows, or recently resolved issue still in amber persistence window.
- **Fail / red:** expired domain, domain does not resolve, web endpoint unreachable after redirects, or critical DNS mismatch.

### Amber persistence

After a problem resolves, keep an amber state for **N days**, default **3**, then return to green if no new issue appears.

Implementation:

- Active alerts become inactive and receive `resolved_at`.
- `StatusCalculator` checks unresolved active alerts first.
- If none are active, it checks recently resolved alerts within `amber_persistence_days`.
- If recent resolved alerts exist, status remains warn/amber with copy like “Recently recovered.”

## 12. Alert lifecycle

Alerts are durable records with active/resolved lifecycle.

- New problem/change: create active alert.
- Same problem persists: do not create duplicates; update/leave active alert.
- Problem resolves: set `is_active = 0`, set `resolved_at`.
- Resolved but recent: contributes to amber persistence.
- Old resolved alerts: retained only per pruning policy.

No email is sent in v1.

## 13. Admin UI architecture

### Single-site mode

Surfaces:

- Dashboard widget with green/amber/red summary.
- Settings/management page under Tools or Settings.

Capabilities:

- Read widget: `manage_options` for detailed admin-only v1.
- Manage domains/settings/check now: `manage_options`.

### Network mode v1

- If network-activated: network admin only.
- Use network-global tables and network option.
- Monitor network primary domain only by default.
- Do not schedule per-site jobs.
- Do not create per-site tables.

### Per-site activation on multisite

If activated per site, act like a single-site install for that site while avoiding duplicate network-level assumptions.

## 14. AJAX API

Use `admin-ajax.php` for v1.

Actions:

- `domain_monitor_check_now`
- `domain_monitor_add_domain`
- `domain_monitor_remove_domain`
- `domain_monitor_toggle_domain`
- `domain_monitor_mark_alert_read`

All actions must:

- Check capability.
- Verify nonce.
- Sanitize all input with `wp_unslash()` and appropriate sanitizers.
- Return `wp_send_json_success()` / `wp_send_json_error()`.

## 15. Scheduling

- Register custom schedule if needed for daily cadence.
- On activation, schedule one event if none exists.
- On deactivation, clear scheduled hook.
- Check engine processes domains where `is_active = 1` and `next_due_at <= now`.
- Batch size should be filterable and conservative for shared hosting.
- Each domain check must be isolated so one failure cannot abort the batch.

## 16. Security and privacy

- Capability checks and nonces for all admin mutations.
- Escape output late with WordPress escaping functions.
- Sanitize domain input through strict domain normalization.
- Use `$wpdb->prepare()` for all variable SQL.
- Store only operational domain data; no secret API keys in v1.
- No third-party account or backend.
- External calls are limited to RDAP/DNS/HTTP checks needed for configured domains.

## 17. Deferred integration points

Do not implement in v1, but keep seams clean for:

- Site Health integration.
- Email alerting and email deliverability checks.
- Webhooks.
- TXT/SPF/DMARC/DKIM monitoring.
- Failing-TLD telemetry/reporting.
- Pro remote monitoring tier.
- Browser-user-agent fallback for HTTP checks.
- Full multisite per-site domain ownership UI.
