# External Request Manager Pro

A WordPress plugin for monitoring and controlling external requests made through the WordPress HTTP API.

Plugin version: **2.5.3**. Database schema version: **1.2.0**.

## Features

- Aggregate request attempts by hostname and HTTP method, with counts, timestamps, approximate request sizes, and source attribution.
- Block or allow an entire host across HTTP methods.
- Limit accepted calls per host within a configurable interval. Exceeding the quota temporarily refuses calls; it does not permanently block the host.
- Enforce host rules on automatic redirects.
- Inspect response codes, timings, and optional stored response excerpts.
- Keep a bounded history of unique URLs, with common query credentials redacted.
- Filter, search, sort, and paginate the dashboard; choose its optional columns.
- Delete records with an audit trail containing their original blocking state and the responsible user.
- Install separate tables for each site during network activation and initialize newly created sites.

The plugin covers the WordPress HTTP API. Direct cURL calls, sockets, and independent HTTP clients are outside its scope.

## Installation and upgrades

1. Place the plugin in `wp-content/plugins/external-request-manager-pro/`.
2. Activate it through WordPress.
3. Open **External Requests** and configure settings.
4. On an existing installation, open **Settings → Database Updater** and run the updater when the installed schema version differs from the required version.

The updater changes tables in place and preserves existing records and settings. Schema 1.2.0 adds request correlation tokens and ensures the tables use InnoDB. A legacy MyISAM conversion can lock a large table; schedule the upgrade appropriately and take a database backup.

Requirements:

- WordPress 5.0 or later.
- PHP 7.2 or later.
- MySQL 5.7 or later, or MariaDB 10.2 or later, with InnoDB and advisory lock support.

The verification environment and compatibility limits are recorded in [the review report](docs/PLUGIN_REVIEW.md).

## Dashboard and host rules

Each dashboard row represents one **host/method aggregate**. Summary cards count these rows; the request count on a row counts attempted HTTP API calls. Blocked and rate-limited attempts are included. Responses supplied by an earlier `pre_http_request` handler are respected and are not counted as outgoing requests.

Blocking, unblocking, and changing a rate limit on a row affect every active method for its host. A new method inherits that host's existing policy. Uppercase hostnames and a single trailing DNS dot are treated consistently, including rules stored by older versions.

Use the row controls or review dialog to inspect a record and change its host policy. Rate limits accept a calls-per-interval value. Removing a limit clears the cached quota state. Redirects within the same host share the accepted call; a redirect to another host checks that target's policy.

## Deletion and retention

Deletion permanently removes the selected request rows and creates audit entries. Audit entries are metadata, not recoverable copies of the full request record. The legacy soft-delete API remains for compatibility; dashboard deletion uses hard deletion.

- **Clear All Except Blocked** removes unblocked rows, including their rate-limit rules.
- **Clear All & Unblock** removes all request rows and their rules, including legacy soft-deleted rows.
- Automatic retention removes expired unblocked rows without rate-limit rules. It preserves policy rows and expires old deletion-audit entries.
- A retention period of `0` keeps records indefinitely.

Audit insertion and deletion run in one transaction after schema 1.2.0 is installed. A failed audit insert leaves the request records intact.

## Settings and response privacy

- **Items per page:** 5–200.
- **Display columns:** optional columns are configurable; host and action controls remain available.
- **Retention:** 0–3650 days, with optional daily cleanup.
- **Notifications:** optional notices for newly detected aggregates.
- **Track response:** enable or disable response code, timing, and excerpt capture.
- **Max response body length:** a byte limit from 0 to 1 MiB. The default is **0**, so new installations do not store response bodies.
- **URL history:** optionally retain up to 100 unique URLs per aggregate.

Existing response-body settings are preserved during upgrades. Stored bodies can contain sensitive information. A **Download Stored Response** action exports the stored excerpt as plain text; it cannot recover a body that was truncated or never stored.

New URL records remove user/password credentials, fragments, and common secret query parameters such as tokens, API keys, passwords, and signatures. Older URL records are redacted when displayed but are not rewritten in the database. Redaction does not identify arbitrary secrets in paths, bodies, or every possible parameter name. Disabling body storage does not erase previously stored bodies immediately; subsequent requests replace their response fields, and explicit deletion removes records.

## Architecture

| Path | Responsibility |
| --- | --- |
| `external-request-manager.php` | Bootstrap, lifecycle hooks, translations, plugin links |
| `includes/class-database.php` | Schema, listing, host policies, audited deletion, retention |
| `includes/class-request-logger.php` | HTTP interception, response correlation, quotas, redirects, source attribution |
| `includes/class-admin-pages.php` | Admin pages, assets, localized UI configuration |
| `includes/class-settings.php` | Settings registration, validation, and fields |
| `includes/class-ajax.php` | Authorized AJAX endpoints and input validation |
| `includes/helpers.php` | Byte formatting, URL redaction, timezone-aware display |
| `templates/` | Dashboard, settings, and deletion audit views |
| `assets/` | Shipped JavaScript and CSS |
| `tests/` | Syntax, DOM, WordPress integration, multisite, and worker tests |

The actual table prefix follows each site's WordPress configuration.

`{prefix}external_requests` stores the host/method key, latest example URL, bounded URL history, response fields, `last_request_token`, source fields, counts, first/last timestamps, blocking state, legacy soft-delete state, and rate-limit values. Notes and custom-action columns remain for compatibility.

`{prefix}external_requests_deleted` stores the host, example URL, original blocking state, deletion timestamp, and actor ID. Retention and cron deletions use actor ID `0` when no user is logged in.

## API and hooks

```php
// Paginated host/method aggregates. Sorting columns are allowlisted.
ERM_Database::get_requests(
    array( 'filter' => 'all', 'search' => '', 'per_page' => 25, 'paged' => 1 )
);
ERM_Database::get_request_detail( $id );
ERM_Database::count_by_status();

// Policies apply to all active rows for the selected host.
ERM_Database::update_request_blocked( $id, true );
ERM_Database::update_rate_limit( $id, 60, 3 );

// Return affected row counts, or false on failure.
ERM_Database::delete_request( $id, true );
ERM_Database::bulk_action( $ids, 'delete' );
ERM_Database::clear_all_logs( false );
ERM_Database::cleanup_old_logs( 30 );
```

Supported filters:

- `erm_pro_is_blocked( $is_blocked, $host, $url )`
- `erm_pro_before_log( $log_data, $host, $url, $args )`

Supported actions:

- `erm_pro_after_log( $request_id, $host, $url )`
- `erm_pro_after_clear( $mode )`, with `all` or `except_blocked`
- `erm_pro_cleanup( $deleted_count )`

The before-log filter can adjust the example URL, request size, and source fields or return a non-array to skip logging. Host/method identity, quota state, correlation tokens, and URL-history bounds remain controlled by the logger.

## Development and verification

```powershell
rtk proxy composer install
rtk proxy npm ci
rtk proxy composer lint
rtk proxy npm run build
rtk proxy npm test
```

JavaScript is shipped directly; the build command checks its syntax. Development dependencies are not needed to run the plugin. WordPress integration tests require a disposable database and a loopback HTTP fixture. See [test setup and coverage](tests/README.md), [the detailed review](docs/PLUGIN_REVIEW.md), and [the PR draft](docs/PR_DESCRIPTION.md).

## Troubleshooting

If requests are absent, verify activation, installed schema version, the tables, and that the caller uses the WordPress HTTP API. Check database errors and advisory-lock support. Local site hosts, localhost, IPv4 loopback `127.0.0.1`, and IPv6 loopback `::1` are excluded.

For failed admin actions, confirm the user has `manage_options`, reload an expired nonce, and inspect the returned error. Database failures are reported instead of claiming a successful change.

For storage growth, reduce URL/body limits and configure retention. Retention intentionally preserves blocking and rate-limit policies.

## License and author

Licensed under GPL-2.0-or-later. See [LICENSE](LICENSE).

Author: Yusuf Bahrami — [wcoq.com](https://wcoq.com/).

Report issues through [this repository's issue tracker](https://github.com/ildrm/external-request-manager-pro/issues). See [CHANGELOG.md](CHANGELOG.md) for release history.
