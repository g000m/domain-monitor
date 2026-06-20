# Domain Monitor — Settings / Domains UX Handoff Plan

> Branch: `ux/settings-domains-handoff`  
> Screenshot captured: `docs/ux/current-settings-page-2026-06-12.png`  
> Current source inspected: `src/Admin/SettingsPage.php`, `src/Admin/DashboardWidget.php`, `src/Plugin.php`, `docs/v1-spec.md`, `docs/architecture.md`

## Goal

Prepare Domain Monitor for a designer handoff by separating the current all-in-one admin screen into two clear surfaces:

1. **Domains / Tests** — operational monitoring: domain list, status, test results, check actions, alerts.
2. **Settings** — actual configuration: notifications, check behavior, retention/uninstall preferences, future integrations.

The current page is useful for proving functionality, but it now mixes monitoring data, actions, alert state, adding domains, and notification settings in one vertical settings page. That makes the product feel like a developer/debug screen rather than a calm WordPress admin experience.

## Current state from screenshot

Captured from local `wp-env` through a temporary Cloudflare tunnel after pulling latest `origin/main`.

Current URL path:

```text
/wp-admin/options-general.php?page=domain-monitor
```

Current page title:

```text
Domain Monitor
```

Current sections, in order:

1. WordPress core update notice.
2. Domain Monitor warning notice: “one or more domains need attention.”
3. `Monitored domains`
   - Renders each domain as a stacked `<section>` block.
   - Each domain shows source, DNS status/message, RDAP status, expiration, transfer lock, last checked, and a `Run check` button.
4. `Add another domain`
   - Inline text input + `Add domain` button.
5. `Notifications`
   - Checkbox list for status change, NS change, A change, MX change, transfer lock removal.
   - Notification email input.
   - Email DNS health check checkbox with description.
   - `Save notification settings` button.
6. `Open alerts`
   - Currently says `No open alerts.` even while `example.com` is unknown/warn and the admin notice says domains need attention.

Current sample domains shown:

- `cloudflare.com` — manual, DNS OK, RDAP OK, expires `2033-02-17`, last checked.
- `example.com` — manual, DNS/RDAP unknown, expiration unknown, no last checked.
- `trycloudflare.com` — auto-detected, DNS OK, RDAP OK, expires `2032-07-07`, last checked.

## Main UX problem

The current screen answers too many different questions at once:

- “What domains are being watched?”
- “Is anything broken?”
- “What happened in the last check?”
- “How do I run a check?”
- “How do I add domains?”
- “Where do notifications go?”
- “Which types of alerts should email me?”
- “Are there open alerts?”

That is why the next design pass should split **domain operations** from **plugin configuration**.

## Proposed information architecture

Keep the plugin under a familiar WordPress admin location, but add two sub-pages. Preferred location for v1: **Tools → Domain Monitor**, because the product is more of an operational tool than a generic WordPress setting.

If we keep it under Settings for now, the sub-page structure still applies.

### Page 1: Domain Monitor → Domains

Purpose: monitor and act.

Primary user question:

> “Are my domains safe, and what needs my attention?”

This page owns:

- Overall status summary.
- Domain list/table/cards.
- Per-domain check status.
- Manual `Run check` actions.
- Add domain flow.
- Open/recent alerts.
- Links to domain detail views.

Suggested page title:

```text
Domain Monitor
```

Suggested nav/tab label:

```text
Domains
```

Suggested subtitle:

```text
Watch the domains this site depends on. Domain expiration is checked automatically when available.
```

### Page 2: Domain Monitor → Settings

Purpose: configure behavior.

Primary user question:

> “How should Domain Monitor behave and notify me?”

This page owns:

- Notification email.
- Notification event preferences.
- Email DNS health check toggle.
- Future threshold/cadence settings.
- Future alert retention / preserve-data setting.
- Future external monitoring/API connection.

Suggested page title:

```text
Domain Monitor Settings
```

Suggested nav/tab label:

```text
Settings
```

Suggested subtitle:

```text
Configure notifications and monitoring preferences. Domain status and checks live on the Domains page.
```

## Designer brief: desired product feel

The product thesis is still:

> Every WordPress site has a domain. Keep it safe.

The interface should feel:

- Calm, plain, trustworthy.
- WordPress-native.
- More like Site Health than a security scareware plugin.
- “Green light until something matters.”
- Focused on expiration as the most universally understood metric.

Avoid:

- Dense debug-output blocks as the default view.
- Alarmist red/security copy for unknown states.
- Upsell-style cards in the primary dashboard.
- Making the user understand RDAP/DNS jargon before they get value.

## Domains page UX plan

### 1. Top status summary

Add a compact summary card above the domain list.

Example healthy state:

```text
● All monitored domains look healthy
3 domains monitored · Last check 2 hours ago
```

Example attention state:

```text
● 1 domain needs attention
example.com has not been checked yet.
```

Suggested summary fields:

- Worst current status: `Healthy`, `Needs attention`, `Critical`, `Not checked yet`.
- Count of monitored domains.
- Count of open alerts.
- Latest check timestamp.
- Primary CTA:
  - `Run checks` or `Run checks now` if safe.

### 2. Domain list should become scannable

Replace stacked text sections with a WordPress-native list table or card grid.

Recommended v1: **list table**, because it scales to many domains and feels native.

Columns:

- Domain
- Status
- Expires
- DNS
- SSL
- Email DNS
- Last checked
- Actions

Possible row shape:

```text
cloudflare.com        Healthy   Expires Feb 17, 2033   DNS OK   SSL OK   Email DNS off   May 26, 2026   [Run check] [Details]
example.com           Unknown   Unknown                —        —        —               Never          [Run check] [Details]
trycloudflare.com     Healthy   Expires Jul 7, 2032    DNS OK   SSL OK   Email DNS off   May 26, 2026   [Run check] [Details]
```

Primary row affordance:

- Clicking the domain opens details, either:
  - inline expandable row for v1, or
  - future `/domain-monitor-domain&id=...` detail page.

### 3. Domain detail should use metric cards

For expanded/detail state, show metric groups with plain labels.

Groups:

- Registration
  - Expiration date
  - Days remaining
  - Registrar
  - Transfer lock
- DNS
  - A/AAAA
  - NS
  - MX
- Web / SSL
  - SSL status and expiration
  - Final URL / HTTP status, when available
- Email DNS, if enabled
  - SPF
  - DMARC
  - DKIM selectors
  - MX
- Alerts
  - open alert messages
  - recently resolved alerts

Designer note: use the technical terms, but lead with friendly interpretations. Example: “Transfer lock: Unknown” needs help text explaining why unknown is not necessarily bad.

### 4. Add domain should be an action, not a whole section

Move `Add another domain` into a more compact affordance on the Domains page.

Options:

- Primary button near the page title: `Add domain`.
- Opens a small inline form or modal.
- Empty state uses the form directly.

Recommended copy:

```text
Add a domain
Monitor another domain your site depends on, such as a redirect, campaign domain, or mail domain.
```

Input label:

```text
Domain name
```

Placeholder:

```text
example.com
```

Validation copy:

```text
Enter a public domain like example.com. Do not include http:// or a path.
```

### 5. Alerts belong with domains, not settings

Move `Open alerts` to the Domains page.

Suggested placement:

- If alerts exist: show under the summary before the domain table, or inline in affected rows.
- If no alerts exist: do not spend much vertical space. A small “No open alerts” line is enough.

Resolve/acknowledge actions should stay near the alert.

Suggested future labels:

- `Acknowledge`
- `Mark resolved`
- `Ignore this change`
- `Set current value as baseline`

Current code uses `Resolve`; a designer should decide whether that implies too much if the underlying condition still exists.

## Settings page UX plan

Settings should be much shorter than the current combined page.

### 1. Notifications panel

Current settings belong here:

- Notification email.
- Notify on status change.
- Notify on nameserver change.
- Notify on A record change.
- Notify on MX record change.
- Notify on transfer lock removal.

Suggested structure:

```text
Notifications
Send alerts to: [ email input ]
[ ] Use site admin email if blank

Notify me when:
[x] A domain status changes
[x] Nameservers change
[x] A records change
[x] MX records change
[x] Transfer lock is removed
```

Designer note: the current settings are checkbox-heavy. Grouping them under “Notify me when” will read better than a full WordPress `form-table` row for every checkbox.

### 2. Monitoring preferences panel

Not all of these exist in code yet, but the settings page should make space for them:

- Expiration warning thresholds: `30, 14, 7, 1 days`.
- Check cadence: daily.
- Amber/recovery persistence: 3 days.
- Alert retention: 90 days / last N alerts.
- Preserve data on uninstall.

For now, if these are not implemented, do not fake controls. Use a small “Coming later” note only in designer wireframes, not production UI.

### 3. Email DNS health check panel

Current toggle:

```text
Enable email DNS health check (SPF/DMARC/DKIM/MX)
```

This setting probably belongs either:

- globally in Settings, if it applies to all domains; or
- per-domain in Domain Details, if only some domains handle mail.

Current copy says “Enable only for domains that handle mail,” which implies **per-domain** would eventually be more correct. For now, keep the global toggle but design with a future per-domain override in mind.

Recommended copy:

```text
Email DNS health
Check SPF, DMARC, DKIM selectors, and MX records for domains that send or receive email.
```

Potential controls:

```text
[ ] Run email DNS checks for monitored domains
```

Future per-domain state:

```text
Email DNS: On / Off / Inherited
```

### 4. Advanced / data panel

Future settings:

- Preserve data on uninstall.
- Delete plugin data now.
- Export monitoring data.

This should be visually separated from routine notification settings.

## Navigation recommendation

Use WordPress admin page tabs immediately under the `h1`.

Example:

```text
Domain Monitor
[ Domains ] [ Settings ]
```

Implementation can use WordPress-style nav tabs:

```html
<nav class="nav-tab-wrapper" aria-label="Domain Monitor sections">
  <a class="nav-tab nav-tab-active" href="...page=domain-monitor">Domains</a>
  <a class="nav-tab" href="...page=domain-monitor-settings">Settings</a>
</nav>
```

Alternative: register separate submenu pages. Since this is currently under Settings via `add_options_page()`, the quickest path is probably:

- Keep one parent entry: `Domain Monitor`.
- Route by query arg: `page=domain-monitor&view=domains|settings`, or
- Register two options pages if the WordPress sidebar can present them cleanly.

For designer handoff, the IA should be two pages/tabs even if implementation initially uses a query arg.

## Suggested implementation architecture

Current code has one render-only class:

```text
src/Admin/SettingsPage.php
```

Recommended split:

```text
src/Admin/DomainsPage.php
src/Admin/SettingsPage.php
src/Admin/AdminNav.php
```

Where:

- `DomainsPage` renders summary, domain list, add domain, alerts.
- `SettingsPage` renders notification/configuration forms only.
- `AdminNav` renders shared title/nav tabs and active state.

Current handlers in `Plugin.php` can mostly remain:

- `domain_monitor_add_domain` → Domains page redirect.
- `domain_monitor_manual_check` → referer redirect; usually Domains page.
- `domain_monitor_resolve_alert` → Domains page redirect.
- `domain_monitor_save_settings` → Settings page redirect.

Redirect helpers should split:

```text
redirectToDomains(...)
redirectToSettings(...)
```

Current `redirectToSettings()` sends everything back to `options-general.php?page=domain-monitor`. That should change so actions land where they belong.

## Designer deliverables requested

Ask the designer for:

1. Two-page IA/wireframes:
   - Domains / Tests
   - Settings
2. Dashboard widget refresh direction, so it matches the same visual language.
3. Domain row/table states:
   - healthy
   - warning
   - critical
   - unknown/not checked
   - recently recovered
4. Domain detail expanded state.
5. Alert presentation and acknowledgement language.
6. Empty states:
   - no public domain detected
   - no domains added
   - first check has not run
   - no alerts
7. Settings form grouping and copy.
8. Visual treatment for expiration as the headline metric.

## Acceptance criteria for the UX refactor

- Domains/test status and settings no longer appear on the same page.
- User can immediately answer: “Are my domains OK?” from the Domains page.
- User can immediately answer: “Where do notifications go?” from the Settings page.
- Adding a domain remains easy and visible.
- Running a check is per-domain and near that domain.
- Open alerts are near the affected domain/status, not buried below notification settings.
- Expiration date/days remaining is one of the most prominent pieces of information.
- The UI works without JavaScript for v1; JS can progressively enhance later.
- The page remains WordPress-native and accessible.

## Immediate developer tasks after design approval

1. Add failing unit tests for a `DomainsPage` render class.
2. Move domain row rendering from `SettingsPage` to `DomainsPage`.
3. Move open alerts rendering from `SettingsPage` to `DomainsPage`.
4. Keep `SettingsPage` focused on notification/config settings.
5. Add shared nav tabs.
6. Update `Plugin.php` admin menu registration and redirects.
7. Run unit tests.
8. Browser-verify both pages in `wp-env`.
9. Capture updated screenshots.

## Notes / mismatches discovered

- `docs/v1-spec.md` says email alerting and TXT/SPF/DMARC/DKIM are deferred, but current code already includes notification settings and email DNS checks. The UX plan should treat current code as the source of truth for handoff while recognizing the docs are behind.
- `docs/architecture.md` says admin AJAX for v1, but current code uses `admin-post.php` form submissions. That is fine for no-JS accessibility; update docs later if this remains the direction.
- Current page says `Open alerts: No open alerts` while the top notice says domains need attention. This can be valid if the warning comes from unknown/not-checked status rather than an alert, but the design should explain the distinction.
- `Transfer lock: unknown` appears prominently. Consider moving unknown technical details into expanded details so the default view stays calm.
