# Domain Monitor tests

These tests are intentionally red first. They define the wished-for public API before production classes exist.

Preferred local run once Docker/wp-env is available:

```bash
npm install
npm run wp-env:start
npm run wp-env:composer
npm run test:unit
```

Fallback if PHP and Composer are installed directly:

```bash
composer install
composer test:unit
```
