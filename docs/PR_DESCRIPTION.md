Prevent data loss and repair HTTP tracking, host policies, and admin actions

Reactivating the plugin could rename its populated request table, while schema upgrades could silently miss columns. HTTP responses were matched through a shared global with the wrong WordPress hook arguments, and blocked hosts could be reached through automatic redirects. Admin operations also reported failed database writes as successful.

This change updates tables in place, verifies installation before advancing schema version 1.2.0, and uses per-request IDs to correlate responses. Host rules apply across HTTP methods. Rate limits honor calls per interval, serialize worker updates, expire normally, and apply to redirect targets. Request counts use atomic upserts.

Deletion records the original blocking state and removes only audited rows within an InnoDB transaction. Retention keeps blocking and rate-limit rules, expires audit records, and uses site-local timestamps consistently. AJAX handlers validate input and report database failures.

Dashboard changes repair numeric detail rendering, failed-request recovery, localized counts, pagination, stored-response downloads, dialog keyboard behavior, associated settings labels, and asset cache refresh. Response-body storage defaults to disabled; common URL credentials are redacted before logging. Network activation and new-site initialization install per-site tables.

Validation:
- 33 WordPress/MariaDB integration scenarios passed.
- 4 multisite lifecycle checks passed.
- 2 tests with four independent PHP workers passed.
- 11 dashboard DOM tests passed.
- PHP 7.2 syntax checks, configured WordPress lint, and JavaScript checks passed.
- npm audit reported zero vulnerabilities.

Deployment:
- Run the manual Database Updater to apply schema 1.2.0. Legacy MyISAM tables are converted to InnoDB.
- Blocking/unblocking a row affects all methods for its host.
- Explicit deletion removes selected rows and their rules; policy rows survive automatic retention.
- Stored-response downloads contain the configured excerpt.
- Historical URL logs are not rewritten. Legacy URLs are redacted when displayed.
- Browser layout and a full WordPress 5.0/PHP 7.2 runtime were not tested.

See `docs/PLUGIN_REVIEW.md` for the role matrix, findings, checks, and remaining limits.
