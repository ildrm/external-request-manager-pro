# Regression tests

Use a disposable WordPress database. Integration, concurrency, and multisite tests create, delete, and alter plugin tables. PHP tests require a table prefix starting with `ermtest_` and an explicit argument equal to `DB_NAME`.

## Local checks

```sh
rtk proxy composer install
rtk proxy composer lint
rtk proxy npm ci
rtk proxy npm test
rtk proxy npm run build
rtk proxy npm audit --audit-level=low
```

`build` verifies the shipped JavaScript. The plugin uses its source assets directly and has no bundling entry point.

## WordPress integration

Create a disposable WordPress installation with:
- Database name `erm_review` (or substitute its exact name below).
- Table prefix `ermtest_`.
- Site URL `http://erm-review.test`.
- Administrator login `review-admin`.
- A minimal, valid fixture theme.
- WP-CLI, PHP with mysqli, and MariaDB/MySQL available.

The review used PHP 8.2.12, WordPress core reporting version 7.1, and an isolated MariaDB 10.11 container. The suite runs against actual WordPress hooks and database operations.

Run the HTTP fixture in another terminal, binding exclusively to loopback:

```sh
rtk proxy php -S 127.0.0.2:33078 tests/fixtures/http.php
```

Then run:

```sh
rtk proxy php path/to/wp-cli.phar eval-file tests/integration.php erm_review --path=path/to/test-wordpress --url=http://erm-review.test
rtk proxy php path/to/wp-cli.phar eval-file tests/concurrency.php erm_review --path=path/to/test-wordpress --url=http://erm-review.test
```

Integration tests verify installation, missing-column upgrades, failure reporting, engine conversion, host policies, preemption, local requests, quotas, real responses and errors, nested callbacks, body limits, URL redaction, histories, audit transactions, retention, pagination, authorization, JSON shapes, template rendering, and redirects.

Concurrency tests launch four independent PHP processes. They verify all 100 log increments and exactly three accepted calls across 40 quota attempts.

## Multisite

Convert only the disposable test installation to multisite, ensure the generated constants are in its test `wp-config.php`, then run:

```sh
rtk proxy php path/to/wp-cli.phar eval-file tests/multisite.php erm_review --path=path/to/test-wordpress --url=http://erm-review.test
```

The suite uses unique site paths so it can be rerun, restores network activation state even on failure, creates sites, and verifies network activation, initialization of new sites, policy isolation, scheduled-job cleanup, and restored blog context. Test sites remain in the disposable database.

## Minimum PHP syntax

```sh
rtk proxy docker run --rm --mount type=bind,source=/absolute/repository/path,target=/app,readonly -w /app php:7.2-cli php tests/lint.php
```

This checks syntax on PHP 7.2, not the complete WordPress 5.0/PHP 7.2 runtime.

DOM tests use jsdom and jQuery. They cover numeric details, text escaping, deletion, rate fields, failure recovery, translated counts, focus restoration, keyboard trapping, and selection state. They do not verify browser layout or screen-reader announcements.

Configured WordPress lint preserves established class filenames and excludes optional expression/HTML-alignment preferences. Inline exceptions explain identifier SQL for WordPress 5.0, nonce checks delegated to the authorization helper, and intentional bounded stack inspection for source attribution.
