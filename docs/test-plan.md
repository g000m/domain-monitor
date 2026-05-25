# Domain Monitor — TDD Test Plan

> Status: v1 test design baseline.
> Principle: no production behavior without a failing test first.

## 1. Testing goals

The test suite should make these guarantees before v1 ships:

- Domain normalization is deterministic and safe.
- Schema creation uses the correct table prefix in single-site and network modes.
- Settings live in options/network options, not custom settings tables.
- RDAP lookup handles success, missing data, unknown TLDs, rate limits, and failures clearly.
- DNS lookup uses native DNS first and Google DNS-over-HTTPS fallback when needed.
- HTTP checks follow redirects, store final URL and redirect count, and avoid redundant HTTPS checks when HTTP redirects successfully.
- Snapshot diffing produces named diffs.
- Last-known-good data is preserved across partial failures.
- Status calculation handles green/amber/red and 3-day amber persistence after recovery.
- Alerts include `is_active` and `resolved_at` lifecycle behavior.
- Multisite network activation does not create per-site duplicate tables or cron jobs.
- Admin Ajax actions are capability-checked, nonce-checked, sanitized, and return structured JSON.

## 2. Test stack

Recommended stack:

- PHPUnit for PHP unit/integration tests.
- WordPress core test suite for integration tests involving options, multisite, hooks, `$wpdb`, cron, and Ajax.
- Brain Monkey or similar only if needed for fast unit tests of WordPress functions.
- Mock HTTP/DNS through injectable interfaces rather than global monkeypatching whenever possible.

Suggested Composer dev dependencies:

```json
{
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "yoast/phpunit-polyfills": "^2.0",
    "brain/monkey": "^2.6",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1"
  }
}
```

PHPUnit 9.6 is compatible with PHP 7.4. If the eventual CI matrix includes newer PHP versions, keep test syntax PHP 7.4-compatible.

## 3. Test organization

```text
tests/
├── Unit/
│   ├── DomainNameTest.php
│   ├── SnapshotDifferTest.php
│   ├── StatusCalculatorTest.php
│   ├── RdapCheckerTest.php
│   ├── DnsCheckerTest.php
│   ├── HttpCheckerTest.php
│   └── WwwPolicyTest.php
├── Integration/
│   ├── SchemaTest.php
│   ├── SettingsTest.php
│   ├── CronTest.php
│   ├── AlertRepositoryTest.php
│   ├── DomainRepositoryTest.php
│   ├── AdminAjaxTest.php
│   └── MultisiteTest.php
└── fixtures/
    ├── rdap-example-success.json
    ├── rdap-unknown-tld.json
    ├── dns-google-a-response.json
    └── snapshots/
```

## 4. TDD implementation sequence

Each task below should follow RED → GREEN → REFACTOR:

1. Write the failing test.
2. Run the focused test and confirm it fails for the expected reason.
3. Write the smallest implementation that passes.
4. Run the focused test and full relevant suite.
5. Refactor only after green.

## 5. Unit tests

### 5.1 Domain normalization

File: `tests/Unit/DomainNameTest.php`

Test cases:

- `example.com` normalizes to `example.com`.
- `HTTPS://Example.com/path?x=1` normalizes to `example.com`.
- `www.example.com` remains `www.example.com` when it is the actual host.
- Trailing dot is removed for comparison.
- Invalid hostnames are rejected.
- Domains longer than 253 chars are rejected.
- Empty input is rejected.
- Unicode/IDN behavior is explicit. If IDN support is deferred, return a clear validation error.

Example first test:

```php
public function test_it_normalizes_url_input_to_lowercase_host(): void {
    $domain = DomainName::fromUserInput('HTTPS://Example.COM/some/path');

    $this->assertSame('example.com', $domain->toString());
}
```

### 5.2 Snapshot diffing

File: `tests/Unit/SnapshotDifferTest.php`

Test cases:

- Detects A record replacement with named diff: old IP → new IP.
- Detects MX added/removed.
- Detects nameserver added/removed.
- Detects registrar changed.
- Detects expiration date moved.
- Detects final URL changed and includes old/new URL.
- No diff for same values in different order.
- No diff when both snapshots are semantically equal after normalization.
- Skips TXT because TXT is out of scope for v1.

Example:

```php
public function test_it_reports_named_a_record_replacement(): void {
    $old = ['dns' => ['apex' => ['a' => ['203.0.113.10']]]];
    $new = ['dns' => ['apex' => ['a' => ['198.51.100.5']]]];

    $diffs = (new SnapshotDiffer())->diff($old, $new);

    $this->assertSame('dns_change', $diffs[0]->type());
    $this->assertStringContainsString('203.0.113.10', $diffs[0]->message());
    $this->assertStringContainsString('198.51.100.5', $diffs[0]->message());
}
```

### 5.3 Last-known-good preservation

File: `tests/Unit/CheckEngineTest.php`

Test cases:

- If RDAP fails but DNS/HTTP succeed, previous RDAP data remains available as last-known-good.
- If DNS fails after a successful previous check, previous DNS records are not overwritten by empty arrays.
- A partial metric failure does not abort the full domain check.
- Error details are stored in `snapshot.errors`.

### 5.4 Status calculation

File: `tests/Unit/StatusCalculatorTest.php`

Test cases:

- All checks OK and no active/recent alerts → green.
- Expiration inside threshold → amber.
- Expired domain → red.
- DNS does not resolve → red.
- RDAP unknown/failing TLD → amber with clear message.
- Active warn alert → amber.
- Active fail alert → red.
- Resolved alert inside default 3-day persistence window → amber.
- Resolved alert older than 3 days → green.
- Custom amber persistence setting changes the window.

### 5.5 Alert lifecycle

File: `tests/Unit/AlertLifecycleTest.php`

Test cases:

- New detected problem creates active alert with `is_active = 1` and `resolved_at = null`.
- Existing identical active problem does not create duplicate alerts.
- Resolved problem sets `is_active = 0` and `resolved_at`.
- Reappearing problem creates or reactivates according to chosen repository policy.
- Pruning removes old resolved alerts but preserves current active alerts.

### 5.6 RDAP checker

File: `tests/Unit/RdapCheckerTest.php`

Use a fake HTTP client.

Test cases:

- Requests `https://www.rdap.net/domain/example.com`.
- Parses expiration date from RDAP events.
- Parses registrar best-effort.
- Handles unknown/failing TLD with clear amber message.
- Handles timeout as degraded, not fatal.
- Handles 429/rate limit by indicating throttled/fallback cadence.
- Handles malformed JSON.
- Does not throw uncaught exceptions for lookup failures.

### 5.7 DNS checker

File: `tests/Unit/DnsCheckerTest.php`

Use injectable native resolver and fallback resolver.

Test cases:

- Queries apex A/AAAA.
- Queries apex MX.
- Queries CNAME where possible.
- Queries `www` A/AAAA/CNAME where applicable.
- Does not query TXT in v1.
- Uses native DNS result when native succeeds.
- Falls back to `https://dns.google/resolve?name=example.com&type=A` when native fails.
- Normalizes/sorts/deduplicates records.
- Converts DNS failure to structured metric error.

### 5.8 www policy

File: `tests/Unit/WwwPolicyTest.php`

Test cases:

- Apex-only site with www redirecting to apex → OK.
- Apex-only site with www unreachable → amber or warn per implementation copy, not fatal unless it breaks expected safety.
- Site running on www → www treated as primary.
- www clearly hosts a different subdomain/application → ignored.
- Ambiguous www mismatch → amber with friendly explanation.

### 5.9 HTTP checker

File: `tests/Unit/HttpCheckerTest.php`

Use a fake HTTP client.

Test cases:

- Starts at `http://example.com`.
- Follows redirects and stores final URL.
- Stores redirect count.
- If HTTP redirects to HTTPS and loads, does not perform a separate HTTPS check.
- Handles redirect loops as failure.
- Handles timeout as failure with clear message.
- Sends an identifiable plugin user agent.
- Does not use admin browser user agent in v1 unless future fallback is explicitly added.

## 6. Integration tests

### 6.1 Schema creation

File: `tests/Integration/SchemaTest.php`

Test cases:

- Activation creates `domainmon_domains` table.
- Activation creates `domainmon_alerts` table.
- `domainmon_domains` includes `is_active`.
- `domainmon_alerts` includes `is_active` and `resolved_at`.
- Settings are stored in option/network option, not custom settings table.
- Re-running activation is idempotent.
- Schema version option is updated.

### 6.2 Single-site activation

File: `tests/Integration/ActivationTest.php`

Test cases:

- Self domain is inserted on activation.
- Self domain cannot be removed, only disabled.
- Cron event is scheduled once.
- Deactivation clears cron event.
- Uninstall removes data when preserve-data setting is false.
- Uninstall preserves data when preserve-data setting is true.

### 6.3 Multisite behavior

File: `tests/Integration/MultisiteTest.php`

Run only when WordPress multisite test bootstrap is available.

Test cases:

- Network activation uses `$wpdb->base_prefix` tables.
- Network activation stores settings as network option.
- Network activation schedules one network-level cron event.
- Network activation monitors only the network primary domain by default.
- It does not create a row/job for every subsite.
- Network admin capability is required for network management actions.
- Per-site activation behaves like single-site install.
- Per-site activation does not duplicate a network-level scheduled check if network mode is active.

### 6.4 Cron/check engine

File: `tests/Integration/CronTest.php`

Test cases:

- Due active domains are checked.
- Inactive domains are skipped.
- `next_due_at` is updated after a check.
- One domain failure does not prevent another domain from being checked.
- RDAP degraded domains use weekly RDAP cadence while DNS/HTTP remain daily.

### 6.5 Admin Ajax

File: `tests/Integration/AdminAjaxTest.php`

Test cases for each Ajax action:

- Missing nonce fails.
- Invalid nonce fails.
- Insufficient capability fails.
- Valid request succeeds.
- Domain input is sanitized/normalized.
- Check-now respects RDAP/check eligibility rules.
- Responses include friendly messages and machine-readable status.

## 7. End-to-end manual acceptance tests

Run these in a local WordPress install after automated tests pass.

### 7.1 Single-site happy path

1. Install and activate plugin.
2. Confirm dashboard widget appears.
3. Confirm self domain appears automatically.
4. Click “Check now.”
5. Confirm result shows green or clear degraded message depending local network.
6. Confirm final URL and redirect count display.
7. Confirm no email settings or email claims appear in v1 UI.

### 7.2 Domain add/remove/toggle

1. Add `example.com`.
2. Confirm it is normalized and saved once.
3. Add duplicate casing variant `EXAMPLE.com`.
4. Confirm duplicate is rejected or maps to same record.
5. Disable domain.
6. Confirm cron/check engine skips it.
7. Re-enable domain.
8. Confirm it becomes due/checkable.

### 7.3 RDAP trouble message

1. Add a domain with a TLD likely to fail in the local environment or mock the response.
2. Run check.
3. Confirm UI says registration lookup had trouble for that TLD.
4. Confirm DNS/HTTP sections still display if available.
5. Confirm status is amber, not green.

### 7.4 DNS fallback

1. Force native DNS resolver failure in a local test harness.
2. Run check.
3. Confirm fallback request is made to Google DNS-over-HTTPS.
4. Confirm result notes resolver/fallback internally.

### 7.5 HTTP redirects

1. Check a domain that redirects from HTTP to HTTPS.
2. Confirm final URL is HTTPS.
3. Confirm redirect count is shown.
4. Confirm no redundant second HTTPS check is shown/logged.

### 7.6 Multisite network mode

1. Create a multisite network with at least three subsites.
2. Network-activate plugin.
3. Confirm only network admin UI is visible for v1.
4. Confirm only one cron event exists.
5. Confirm only network primary domain is monitored by default.
6. Confirm no per-subsite tables are created.

## 8. CI matrix

Minimum useful CI matrix:

- PHP 7.4 + WordPress 6.6
- PHP 8.1 + latest WordPress
- PHP 8.2 + latest WordPress

Optional if maintenance cost is low:

- PHP 8.3 + latest WordPress
- WordPress 6.5 for older-support smoke test only

CI commands should eventually include:

```bash
composer validate --strict
composer install --prefer-dist --no-interaction
composer lint
composer test
composer test:multisite
```

## 9. Definition of done for v1 implementation

- All unit tests pass.
- All single-site integration tests pass.
- Multisite safety tests pass.
- Manual acceptance tests pass in a clean local WordPress install.
- No production code was added without first seeing a failing test.
- No v1 UI mentions email checks, TXT checks, webhooks, or Pro-only behavior as active features.
- Plugin activates/deactivates without fatal errors on PHP 7.4 and WordPress 6.6+.
