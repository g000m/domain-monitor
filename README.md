# Domain Monitor

**Know the moment your domain is at risk, before your visitors do.**

Domain Monitor is a self-contained WordPress plugin that watches your site's domain for the quiet failures that take sites offline: an expired registration, DNS records that drifted, a lapsed TLS certificate, broken email DNS, or a www/apex redirect that stopped working. When everything is healthy you get a calm green light. When something changes, you get a clear, non-alarmist warning while there's still time to act.

No API keys. No paid backend. No third-party account. It runs entirely inside your WordPress install and talks only to the public RDAP, DNS, and HTTP services it needs to check your domains.

## What it watches

- **Registration and expiry** (RDAP): registrar, expiration date, and early warnings as the renewal date approaches.
- **DNS records**: apex and www A/AAAA, MX, and CNAME, checked with PHP's native resolver and a DNS-over-HTTPS fallback when native lookups aren't available.
- **TLS certificate**: validity and expiration of the live HTTPS certificate.
- **Email DNS**: MX and targeted TXT records, so a broken mail setup doesn't slip by unnoticed.
- **www / apex policy**: confirms your canonical redirect still behaves the way it should.
- **Drift**: every check is snapshotted, so Domain Monitor can tell you *what changed*, not just that something looks off.

## Quick start

1. Grab the latest `domain-monitor-x.y.z.zip` from the [Releases](../../releases) page.
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, and activate.
3. Done. On activation it detects your site's domain, creates its tables, and schedules a daily check. Open your **Dashboard** to see the status widget.

On a local or staging site (where the host is `localhost`), tell it which domain to watch in `wp-config.php`:

```php
define('DOMAIN_MONITOR_PRIMARY_DOMAIN', 'example.com');
```

## Reading the status

- **Green**: every check passes and nothing is expiring soon.
- **Amber**: something benign changed, a lookup had trouble, an expiry window opened, or an issue recently recovered.
- **Red**: the domain expired, stopped resolving, or the site is unreachable.

Amber lingers for a few days after a problem clears, so a flapping issue doesn't bounce you straight back to green and out of mind.

## Beyond the dashboard

- **REST API**: read-only status under `domain-monitor/v1` (`GET /status`, `GET /status/{domain}`). Access is gated by the `domain_monitor_rest_permission` filter if you want to wire up token-based auth.
- **WP-CLI**: `wp domain-monitor ...` to run checks and read status from a terminal or your own cron.
- **Multisite-aware**: a network-activated install monitors the network's primary domain, and uninstall cleans up every site.

## For developers

Requirements: **PHP 7.4+**, **WordPress 6.6+**.

Local development uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (Docker):

```bash
npm install
npm run wp-env:start
npm run wp-env:composer
npm run test:unit
```

Or run the suite directly if you have PHP and Composer:

```bash
composer install
composer test
```

The plugin is built test-first. Business logic lives in small, WordPress-free classes that take their collaborators by injection; the WordPress specifics (hooks, `$wpdb`, `wp_remote_get()`, cron, options) sit in thin adapters around them. Storage is defined by interfaces with two implementations: an in-memory one for fast tests and a `$wpdb` one for production. That's why the unit tests run without booting WordPress.

### Building a release

```bash
bin/build.sh                  # WordPress.org-clean zip (no self-updater)
bin/build.sh --with-updater   # GitHub "dogfood" zip (bundles the self-updater)
```

The result lands in `build/domain-monitor-<version>.zip`. The script exports from your latest commit (not the working tree), builds a production autoloader, strips every dev file, and refuses to run on a dirty tree or when the plugin header version and the `DOMAIN_MONITOR_VERSION` constant disagree.

Pushing a `vX.Y.Z` tag triggers CI to build the dogfood zip and attach it to a GitHub release. That release is what self-updating installs pull from.

### Two distribution channels, and why

- **Default build** is the artifact bound for the WordPress.org repository: GPL-clean, PHP 7.4, no bundled updater (the repo serves updates itself).
- **`--with-updater` build** is for dogfooding straight from GitHub before the WordPress.org listing exists. It bundles [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) so installs update from GitHub releases. The plugin only switches the updater on when that library is actually present, so the exact same code is safe in both builds.

## License

GPL-2.0-or-later.
