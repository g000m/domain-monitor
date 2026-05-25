# Domain Monitor — v1 (MVP) Specification

## 1. Purpose & thesis

WordPress is blind to the domain it runs on. It will happily serve a site whose
domain expires in nine days and say nothing. **Domain Monitor** closes that gap:
activate the plugin, and WordPress immediately begins watching the domain's
expiration, nameservers, DNS records, and resolution — surfacing a single green
light in the admin dashboard, with friendly warnings in the admin UI when
anything changes or a deadline approaches.

The product wedge is *zero-config immediate benefit*: install, activate, done.
The site's own domain is monitored automatically with no setup. Everything else
is progressive disclosure.

The plugin is free and self-contained. It requires no account, no API key, and
no external backend. Features that genuinely require remote infrastructure
(high-volume monitoring, guaranteed RDAP access, remote-vantage checks) are
explicitly deferred to a future Pro tier and are out of scope here, though the
data model leaves room for them.

**Decision update:** architectural and test details are captured in
`domain-monitor-architecture.md` and `domain-monitor-test-plan.md`. Those files
reflect the current v1 decisions: PHP 7.4+, WordPress 6.6+, PSR-4 namespacing,
custom tables for operational state, settings in options, no email/TXT/webhooks
in v1, and network-admin-only multisite behavior when network-activated.

---

## 2. Scope

### In scope (v1)

- Monitor the WP site's own domain automatically on activation.
- Monitor any number of additional user-added domains.
- Per-domain metrics: expiration, registrar, nameservers, DNS records
  (A/AAAA/MX/CNAME; TXT deferred), resolved IP, final resolved URL.
- Change detection on all metrics, with **named diffs** (old value → new value).
- Expiration threshold alerts at 30 / 14 / 7 / 1 days.
- Daily automated checks via WP-Cron (weekly for RDAP-fallback domains — see §5).
- "Check now" on demand, where the server can reach RDAP directly.
- Admin dashboard widget: single green light, expanding to a per-domain metric
  cluster on interaction or any non-success state.
- Admin UI alerts on any state change or threshold crossing. Email is deferred.
- Bounded, self-pruning alert history.
- **Multisite safety** — the plugin must not break or cause harm on a multisite
  network. See §11.

### Out of scope (v1 — reserved for later)

- Pro/hosted backend of any kind.
- Custom cURL checks with expected responses.
- Probing for specific/hidden DNS records beyond the standard set.
- Ping / remote-vantage ("from X") checks.
- Outbound webhooks.
- User-defined custom attributes.
- Email delivery/alerting.
- TXT/SPF/DMARC/DKIM checks.
- Subdomain-specific check definitions.
- Per-metric historical trend storage.
- **Full multisite product experience** (network-admin ownership UI, per-instance
  self-domain monitoring surfaced contextually) — the *design* is captured in §11
  as intent, but v1 implements safety only, not the full experience.

The check engine is domain-agnostic by design, so multi-domain ships in v1 at
near-zero marginal cost. The deferred features above are natural v1.x / Pro
expansions and must not be designed against, but the schema should not preclude
them.

---

## 3. Monitored metrics

Each monitored domain tracks the following. All values are stored in a single
per-domain JSON snapshot (see §6) and overwritten in place on each check.

| Metric | Source | Change-detected | Threshold alerts |
|---|---|---|---|
| Expiration date | RDAP | Yes (date moved) | Yes — 30/14/7/1 days |
| Registrar | RDAP | Yes | — |
| Nameservers | DNS (NS) | Yes — named diff | — |
| A / AAAA records | DNS | Yes — named diff | — |
| MX records | DNS | Yes — named diff | — |
| TXT records | DNS | Deferred | — |
| CNAME records | DNS | Yes — named diff | — |
| Resolved IP | DNS (A of apex/host) | Yes | — |
| Final resolved URL | HTTP, following redirects from `http://<domain>` | Yes | — |

**Named diffs:** when a multi-valued record set changes, the alert names the
specific delta (e.g. `A record changed: 203.0.113.10 → 198.51.100.5`;
`MX record added: 10 mail.example.com`; `Nameserver removed: ns3.example.com`).
This requires the snapshot to store each record set as a structured, sorted list so
field-by-field diffing is cheap.

---

## 4. Domain status model

Each domain resolves to one of three precomputed states, stored denormalized on
the domain row so the widget renders without computation:

- **OK (green)** — all metrics resolved successfully, nothing changed,
  expiration beyond the nearest threshold.
- **Warn (amber)** — expiration within a threshold window, a benign change
  detected, or RDAP unreachable (data degraded but DNS/HTTP healthy).
- **Fail (red)** — domain not resolving, expired, or a metric in an error state.

The overall widget light is the worst status across all monitored domains
visible to the current viewer (see §11 for multisite visibility scoping).

---

## 5. RDAP access tiering

Expiration and registrar data come from RDAP, using
`https://www.rdap.net/domain/{domain}` as the v1 endpoint. The plugin's ability
to fetch RDAP depends on the host environment, so the plugin **detects and
degrades automatically — it never asks the user to configure this.**

- **Tier 0 — Direct.** Server can reach registry RDAP endpoints over HTTPS.
  Daily checks; "Check now" button enabled.
- **Tier 1 — Fallback.** Direct RDAP fails (host blocked, rate-limited, or
  outbound HTTP restricted). Plugin falls back to a public HTTPS RDAP redirector.
  **Weekly checks only; no on-demand button** — to avoid hammering a free shared
  endpoint and risking an IP ban. DNS/HTTP metrics still run daily; only the
  RDAP-derived metrics throttle to weekly.
- **Tier 2 — Pro.** Reserved. Not implemented in v1; `rdap_tier` value reserved
  in schema.

**Capability detection:** on activation, the plugin probes direct RDAP once and
caches the result as a tier flag. It re-probes periodically (e.g. on a slow
cadence or after repeated failures) so a domain can be promoted from Tier 1 back
to Tier 0 if host conditions change. RDAP-unreachable on both tiers produces a
degraded (amber) expiration state with messaging that points toward the future
Pro option — never a fake green light.

**Rate limiting is host-IP-scoped, not domain-scoped.** All RDAP requests from a
single WordPress install (and, on multisite, from the entire network — see §11)
originate from the same host IP. The tier flag and any throttle/backoff counters
are therefore properties of the *host*, not the individual domain. Detecting
Tier 1 for one domain is a strong signal the whole host is throttled; the plugin
treats RDAP capability as a shared, host-level resource.

Note: public RDAP redirectors ultimately hit the same authoritative registry
backends as direct queries; the fallback's value is endpoint stability and
shifting part of the request origin, not a distinct data source. The weekly
throttle is the real protection.

---

## 6. Data model

Two custom tables, prefixed `{$wpdb->prefix}domainmon_`, designed for minimum
disk: **current state only, no metric history.** Change detection diffs the
incoming check against the stored snapshot at check time; only alerts accumulate,
and they self-prune.

On multisite, these tables are **network-global** (created once, prefixed with
the base prefix), not per-site — see §11.

### `domainmon_domains` — one row per monitored domain

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AUTO_INCREMENT | |
| `domain` | VARCHAR(253) | Max valid FQDN length; unique |
| `is_self` | TINYINT(1) | A WP site's own domain |
| `is_active` | TINYINT(1) | Whether checks are enabled for this domain |
| `owner_site_id` | BIGINT UNSIGNED | Single-site: 0. Multisite: the blog ID that owns/sees this domain, or 0 for network-level. Reserved for §11 visibility scoping. |
| `rdap_tier` | TINYINT | 0 direct, 1 fallback, 2 pro (reserved) |
| `status` | TINYINT | 0 OK / 1 warn / 2 fail — precomputed for widget |
| `snapshot` | MEDIUMTEXT | JSON: NS, DNS record sets, IP, resolved URL, expiry, registrar — overwritten in place |
| `last_known_good_snapshot` | MEDIUMTEXT | Preserves prior good values across partial lookup failures |
| `last_checked` | DATETIME | |
| `next_due` | DATETIME | Cadence control; indexed |
| `created` | DATETIME | |

The `snapshot` JSON holds all metric values for the domain. Record sets are
stored as sorted structured lists to support named diffing.

### `domainmon_alerts` — durable change/warning log

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED, PK, AUTO_INCREMENT | |
| `domain_id` | BIGINT UNSIGNED | FK → domains.id; indexed |
| `type` | VARCHAR(32) | `expiry` \| `ns_change` \| `dns_change` \| `ip_change` \| `url_change` \| `rdap_unreachable` |
| `severity` | TINYINT | Mirrors status severity |
| `message` | VARCHAR(255) | Human-readable named diff |
| `is_read` | TINYINT(1) | For widget unread state |
| `is_active` | TINYINT(1) | Active/unresolved alert flag |
| `resolved_at` | DATETIME | When an alert was resolved, for recovery/amber persistence |
| `created` | DATETIME | Indexed |

Alerts are the only growing data and are auto-pruned (retain ~90 days or last N
per domain — final value a config constant). Settings live in options, not a
table.

### Settings storage

A single (non-autoloaded) option holding: expiration threshold days (default
30/14/7/1), global check cadence, amber persistence window (default 3 days),
alert retention policy, and the RDAP re-probe interval. On multisite
this is a **network option** (see §11).

---

## 7. Scheduling & checks

- **Daily WP-Cron event** runs the check engine for all domains whose `next_due`
  has passed.
- DNS and HTTP metrics run on the daily cadence for every domain regardless of
  tier.
- RDAP metrics run daily for Tier 0 domains, weekly for Tier 1.
- **"Check now"** triggers an immediate full check for a single domain; enabled
  only for Tier 0 domains (RDAP-capable), disabled with explanatory tooltip
  otherwise.
- Each check: fetch current values → diff against stored snapshot → write new
  snapshot, recompute `status`, set `next_due` → emit admin UI alerts for any
  deltas or threshold crossings.
- Checks must be resilient: a failure fetching one metric degrades that metric
  only, never aborts the whole check or corrupts the snapshot.
- On multisite, the cron event and check engine run **once at the network level**,
  not once per instance — see §11.

---

## 8. Admin interface

**Primary surface: dashboard widget.**

- Default state: a single green light and a minimal "all clear" line. No clutter.
- Expands on deliberate click to reveal the **primary cluster** — one entry per
  monitored domain showing its status light and headline metric (e.g. days to
  expiry).
- Auto-expands when any domain is in a non-success (warn/fail) state, so problems
  surface without interaction.
- Each domain entry expands further to its sub-metrics (NS, DNS sets, IP,
  resolved URL, expiry, registrar) with current values and any recent named-diff
  alerts.
- "Check now" per domain where permitted (Tier 0).

**Secondary surface: settings/management screen** (under Settings or Tools).

- Add / remove monitored domains.
- The self domain is present by default and cannot be removed (only disabled).
- Configure thresholds, cadence, amber persistence, retention, and preserve-data behavior.
- View full alert history (paginated, prunable).

Visibility of domains on both surfaces is scoped by viewer context on multisite —
see §11.

---

## 9. Alerting

- Channel: admin UI only for v1. Email is deferred.
- Triggered on: any metric change (named diff), expiration threshold crossing,
  degraded lookup, or recovery.
- Every alert is written to `domainmon_alerts` and shown in the widget/history
  with unread and active/resolved state.
- Recently resolved alerts keep the domain amber for a configurable persistence
  window; default is 3 days.
- Outbound webhooks are explicitly deferred to v1.x.

---

## 10. Non-functional requirements

- **Free & self-contained:** no account, API key, or external backend required
  for any v1 feature.
- **Minimum disk:** current-state-only storage; bounded, self-pruning alerts.
- **Graceful degradation:** any single metric or RDAP failure degrades only that
  metric; never a fake-healthy state.
- **Low host impact:** checks staggered/batched by `next_due`; suitable for weak
  shared hosting.
- **Clean uninstall:** preserve data by default; allow explicit cleanup/drop of
  custom tables and options from settings. On multisite, cleanup applies to
  network-global tables and network options.
- Standard WordPress security and coding conventions throughout; all admin
  actions nonce-protected and capability-gated (`manage_options`, and
  `manage_network_options` for network-level actions).

---

## 11. Multisite

This section captures the **full intended multisite design** so v1 decisions
don't paint us into a corner — but v1 itself implements only the **safety**
subset marked below. The full experience is deferred to a later version.

### 11.1 Intended full design (future)

- **Self-domain detection is host-aware.** A site's "own domain" is the host of
  its `home_url()`, which may be an apex, `www`, or any other subdomain. The
  self domain is whatever hostname the instance actually serves on — not assumed
  to be `www`.
- **Domain-mapped (unique-domain) instances.** On a network where each instance
  has its own distinct registrable domain, each instance monitors its own domain
  exactly like a standalone site. This is the clean case.
- **Subdomain instances.** On a subdomain network
  (`a.example.com`, `b.example.com`), the registrable domain `example.com` is a
  network-level concern. Its expiration/registrar/NS belong to the **network
  admin**, who should see and own that monitoring. Individual subdomain instances
  are treated as additional **monitored hostnames** (DNS/HTTP/resolution checks),
  not as independent registrable-domain owners — they share the parent's
  registration.
- **Network admin can disable** per-instance subdomain monitoring across the
  network if it's noise.
- **Visibility scoping.** Instance admins see monitoring relevant to their
  instance; the network primary domain's registration detail is surfaced to the
  network admin. (`owner_site_id` in the schema exists to support this.)

### 11.2 v1 requirement — safety only

v1 must **not break and must not cause harm** on multisite. Concretely:

- **Network-global storage.** When `is_multisite()`, custom tables are created
  once using the base prefix (network-global), and settings are stored as a
  **network option** — never per-instance tables or options that would fragment
  state or multiply on every instance.
- **Single network-level cron + check engine.** The scheduled check runs **once
  for the network**, not once per instance. This is the critical harm-prevention
  requirement: a 200-instance subdomain network must never spawn 200 independent
  check runs.
- **Network-level RDAP rate limiting (non-negotiable).** RDAP capability
  detection, the tier flag, and all throttle/backoff state are stored at the
  **network level** and shared across all instances, because every instance's
  outbound request originates from the same host IP. Per-instance RDAP throttling
  is explicitly forbidden — it is the exact failure mode (one IP, N concurrent
  registry queries) that gets a host banned.
- **Sensible default monitoring.** v1 may simply monitor the network's primary
  domain (and optionally the mapped domains it can enumerate) without building
  the full visibility/ownership UI. It must not, by default, register every
  subdomain instance as a separately-RDAP-queried domain.
- **Activation behavior.** Network-activate and per-site-activate must both
  resolve to the same network-global state without error or duplicate
  scheduling.
- **No fatal errors / no duplicate work / no surprise outbound volume** under any
  multisite activation mode.

Everything in §11.1 beyond this safety subset is deferred.

---

This is the buildable v1. The two areas with the most hidden complexity — the
named-diff snapshot format and the Tier 0/1 (now host/network-level) RDAP
detection logic — are the natural next things to nail down before code, alongside
the class architecture.
