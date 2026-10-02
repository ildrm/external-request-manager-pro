# External Request Manager Pro: detailed review

Review date: 2026-10-02. Baseline: `adda329` on `main`. Fix branch: `fix/comprehensive-plugin-review`.

The review covered every shipped PHP, JavaScript, CSS, and template file, plugin lifecycle hooks, database schemas and mutations, admin authorization, HTTP interception, privacy defaults, documentation, and development configuration. All confirmed defects listed below have corresponding fixes. This is a source review with targeted regression testing, not a guarantee that every possible deployment or interaction is defect-free.

## Evaluation roles

These eight roles cover the disciplines needed to evaluate this plugin. They were applied as review perspectives within one review; no separate people or independent security certification are implied.

| Role | Required responsibilities | Work performed |
| --- | --- | --- |
| WordPress/PHP compatibility engineer | Hook contracts, activation, upgrades, translations, minimum runtime, multisite | Checked HTTP hooks against core, removed destructive lifecycle behavior, verified site/network lifecycle, checked PHP 7.2 syntax |
| HTTP and policy engineer | Request/response matching, errors, redirects, host identity, quota semantics | Replaced global response matching, enforced all-method host policy and redirect rules, tested real HTTP and nested calls |
| Security and privacy reviewer | Capabilities, nonces, hostile input, SQL/output safety, credential and body exposure | Audited each endpoint, allowlisted dynamic SQL, rejected malformed inputs, tested DOM injection, added redaction and bounded opt-in body storage |
| Database and data-integrity engineer | Non-destructive migration, unique keys, transactions, audit consistency, retention | Verified schema results, converted legacy engines, implemented audited transactional deletion and atomic counters, injected write failures |
| Performance and concurrency engineer | Worker races, bounded storage, query shape, locking, pagination | Added atomic upserts and host locks, tested independent PHP workers, excluded large fields from list queries, bounded URL history and pagination |
| Frontend/UX engineer | Admin action flows, loading/failure recovery, rendering, persisted state | Repaired deletion flow, numeric details, quota controls, download labels, error recovery, count updates, and modal behavior |
| Accessibility and localization reviewer | Keyboard access, labels, dialogs, translated strings/numbers, timezone display | Added dialog naming, focus containment/restoration, input labels, reduced-motion styles, localized JS, and timezone-aware timestamps |
| QA and release maintainer | Regression coverage, reproducibility, packaging, deployment notes, license metadata | Added test suites and lint config, locked npm dependencies, corrected development scripts and documentation, prepared a PR draft |

## Findings and fixes

Severity reflects the effect in an applicable deployment. Critical/high findings concern disappearing records, failed enforcement, audit/data loss, or sensitive data. Medium findings concern incorrect behavior, incomplete failure handling, or compatibility. Low findings concern presentation and maintenance.

### Lifecycle, schema, and data integrity

| ID / severity | Trigger and original defect | Fix and evidence |
| --- | --- | --- |
| D01 Critical | Reactivating could rename the populated request table to a backup name and create an empty replacement. Existing records and host rules disappeared from the active table. | `ERM_Database::install_site()/upgrade()` now modify the existing table in place. Integration checks preserve IDs, counts, blocked state, and quotas across reactivation. |
| D02 High | `CREATE TABLE IF NOT EXISTS` was passed to `dbDelta`, whose table-name parser could interpret `IF` as the table. Required column changes could be missed. | Canonical `CREATE TABLE` schemas are passed to `dbDelta`; a missing-column regression verifies in-place repair. |
| D03 High | Installation/version updates could indicate success without the required schema being present. Subsequent writes could fail silently. | Inspect installed columns and the unique host/method index; verify/convert the storage engine; advance the version only after checks succeed. A fault-injected upgrade leaves the previous version unchanged. |
| D04 High | Audit insertion and log deletion were separate operations, so a failed audit could still remove the source record. | Lock exact rows, insert their audit records, and delete only those rows in one transaction. An audit-insert failure preserves the record and rule. |
| D05 High | A failed delete after a successful audit could leave a misleading deletion entry. Legacy MyISAM tables could not roll back either operation. | Require/convert InnoDB during upgrade; roll back failed deletes and audit entries. Tests cover conversion and failure rollback. |
| D06 High | Automatic retention could remove rows that carry blocking or rate-limit policy, silently removing enforcement. | Retention deletes only expired unblocked rows with no rate limit. Policy rows survive; explicit deletion still removes selected rows and their rules. |
| D07 Medium | Clear-all omitted legacy soft-deleted rows, leaving records and rules available for later restoration. | Clear operations include legacy rows. A test confirms that cleared legacy rows cannot be restored. |
| D08 Medium | Cleanup and deletion mixed timestamp conventions; the audit table could grow indefinitely. | Use site-local database timestamps consistently and expire audit records under the configured retention period. Tested with Asia/Tehran site time. |
| D09 Medium | Bulk operation results could count submitted IDs, including duplicates and nonexistent records, instead of changed rows. | Deduplicate validated IDs, return actual affected-row counts, and make deletion target the audited IDs. AJAX regression checks one actual deletion for duplicate submissions. |
| D10 Medium | Network activation did not initialize every site; new sites could lack tables, and scheduled jobs could remain after network deactivation. | Install per-site schemas/settings, initialize new sites, and remove cleanup jobs on deactivation while restoring blog context. Four multisite checks pass. |
| D11 Medium | Reactivation could replace user configuration. | Default options use `add_option`, preserving configured values. Lifecycle coverage verifies records and policies remain intact. |

### HTTP integration, enforcement, and concurrency

| ID / severity | Trigger and original defect | Fix and evidence |
| --- | --- | --- |
| H01 High | The response callback used the wrong `http_api_debug` argument positions and shared global request state. Responses could be dropped or assigned to another request. | Register the documented five-argument action and correlate using a UUID attached to parsed HTTP arguments. Real POST response code/body/timing are verified. |
| H02 High | Nested or overlapping calls for one aggregate could allow an older response to overwrite the latest request's response fields. | Persist `last_request_token` and condition response writes on both row ID and token. Nested HTTP coverage preserves the newer response. |
| H03 High | Blocking could affect one method while another method or a newly created row for the same host remained allowed. | Read host policy across active method rows; block/unblock all methods and inherit policy on new methods. GET/POST and normalized-host tests cover enforcement. |
| H04 High | Automatic redirects could reach a blocked or quota-exhausted target without passing through the initial request filter again. | Guard the Requests redirect hook before transport and throw its compatible exception. Tests cover blocked targets, exhausted targets, and accepted same-host redirects. |
| H05 High | Quotas did not consistently honor calls per interval and could become permanent blocking state. | A fixed-window transient tracks accepted calls; quota refusal expires normally and does not set `is_blocked`. Tests cover allowance, exhaustion, expiry, and removal. |
| H06 High | Simultaneous PHP workers could overwrite quota state and accept too many calls. | Serialize quota read/modify/write using a per-site/host database advisory lock; invalidate in-process transient caches before reads. Four workers making 40 attempts accept exactly the configured three calls. |
| H07 Medium | Read-then-write aggregate counters could lose increments or fail on concurrent inserts. | Use an atomic unique-key upsert. Four workers making 100 log calls produce exactly 100 increments. |
| H08 Medium | URL-history read/modify/write could lose concurrent additions or exceed a newly reduced limit. | Serialize logging per site/host and enforce dense, unique, bounded history on each write. Coverage includes reducing the configured history limit. |
| H09 Medium | The request filter could overwrite a response/error supplied by an earlier HTTP handler. | Return an existing non-false preemption unchanged. Tests confirm no outgoing aggregate is created for these responses/errors. |
| H10 Medium | Short-circuited blocking/rate failures do not emit the normal HTTP debug action, leaving stale response details. Errors without a status could also retain an old success code/body. | Capture refusal responses explicitly; clear response fields on each new attempt; handle `WP_Error` with no status. Tests verify stale-data removal. |
| H11 Medium | Host spelling and localhost detection were inconsistent, including uppercase, trailing DNS dots, and IPv6 loopback. | Normalize host keys; exclude site/home and documented local hosts; read/update both canonical and legacy single-dot host variants. Added legacy-rule regression covers quotas and all methods. |
| H12 Medium | Source attribution assumed fixed Unix installation paths and inspected unnecessary stack arguments. | Use configured plugin/MU-plugin/theme/content directories, normalize paths, and inspect a bounded stack without arguments. Custom installations and Windows paths are handled by source logic. |
| H13 Medium | Request-size calculations mishandled supported header/body representations. | Accept string/array bodies and string/array/Traversable headers with scalar values. Sizes remain estimates of the supplied arguments, not wire-level measurements. |
| H14 Low | Per-request transient/global bookkeeping and growing notification lists could leave unnecessary state. | Use request-local pending IDs, discard matched pending entries, and cap notifications at ten entries with expiry. |

### Security, privacy, validation, and performance

| ID / severity | Trigger and original defect | Fix and evidence |
| --- | --- | --- |
| S01 High | URLs containing user/password credentials, token query parameters, or fragments were stored and presented without redaction. | Redact common URL secrets before new writes and before rendering legacy details/audit URLs; strip line breaks and fragments. Credential tests verify retained safe parameters. |
| S02 High | Response bodies could be retained without a conservative default; body bounds did not reliably enforce bytes/valid UTF-8. | Default body limit is zero, preserve existing settings, cap at 1 MiB, and count truncation markers within the byte limit. Multibyte/tiny-limit tests verify valid bounded excerpts. |
| S03 Medium | Empty-value checks treated the response string `"0"` as missing. | Preserve explicit zero strings through response capture, JSON details, and DOM display. The response-hook boundary and DOM tests cover this value. |
| S04 Medium | Some public data-layer sort/search options and admin/AJAX values were insufficiently bounded or assumed scalar input. | Allowlist identifiers/directions, validate scalar values, unslash WordPress input, reject malformed IDs/actions, limit bulk submissions, and bound numeric settings. Hostile sorting and array-argument tests run without warnings. |
| S05 Medium | Missing records and failed writes could still produce successful AJAX responses. | Centralize nonce/capability checks, require valid targets, and return JSON errors with appropriate status for failures. Subscriber, malformed-ID, missing-target, and injected database-failure checks pass. |
| S06 Medium | Listing selected large bodies and URL histories, increasing memory and transfer work for every dashboard page. | Select only list fields; fetch large detail fields through the authorized detail endpoint. |
| S07 Medium | Corrupt/zero pagination settings could divide by zero; unbounded page-link rendering scaled with every page. | Clamp page size to 5–200, clamp requested page to available results, and use bounded WordPress pagination for live and deleted records. |
| S08 Low | Status totals were calculated using repeated queries; unstable sorting could shuffle rows between pages. | Use one grouped count query and add ID as a deterministic ordering tie-breaker. |
| S09 Low | Development scripts referenced an unused build system and lacked reproducible DOM coverage or a configured security lint workflow. | Ship syntax checks, Composer lint configuration, npm lockfile, jQuery/jsdom DOM tests, and a dependency audit. SPDX license labels now use `GPL-2.0-or-later`. |

SQL values use prepared queries. Interpolated table identifiers use trusted WordPress prefixes and plugin constants; predicates and sort identifiers are generated internally or allowlisted. Specific PHPCS exceptions explain these boundaries and the centralized AJAX nonce check. This does not assert that third-party code using the plugin's extension hooks is safe.

### Admin interface, accessibility, and localization

| ID / severity | Trigger and original defect | Fix and evidence |
| --- | --- | --- |
| U01 High | The browser unblocked/cleared rate state before deletion. A subsequent delete failure could still change policy, and deletion audit lost the original blocking state. | Issue one audited delete operation. DOM coverage checks a single request; database coverage confirms the original blocked flag is recorded. |
| U02 Medium | Detail rendering called string operations on numeric/null values, crashing the review dialog. | Render through safe text conversion and jQuery `.text()`. Tests include numbers, null values, zero strings, and malicious HTML. |
| U03 Medium | Failed AJAX requests could leave the entire page disabled or permanently stuck in a loading state. | Scope disabled controls to the active operation, restore their prior state in `.always()`, and handle JSON/HTTP failures. DOM tests cover retry and nonce failures. |
| U04 Medium | Rate controls did not consistently expose/persist calls per interval or remove saved state correctly. | Add validated interval/call inputs, issue one update, remove the limit with interval zero, and reload persisted state. |
| U05 Medium | Count labels depended on English text parsing and could truncate localized thousands-separated numbers. | Use stable `data-count` targets, localized strings, and formatted-number placeholders. DOM coverage confirms count updates. |
| U06 Medium | Clicking inside the dialog could trigger backdrop closing; focus and page overflow were not restored consistently. | Close only on the backdrop itself or explicit close controls; preserve/restore focus and overflow, support Escape, and contain Tab navigation. DOM tests cover these flows. |
| U07 Medium | Dialogs and several controls lacked accessible names/keyboard handling. | Add dialog roles, modal/hidden states, headings, input/button labels, focus targets, visible focus, and reduced-motion styles. DOM keyboard checks pass; real assistive-technology testing remains outstanding. |
| U08 Medium | Audit rendering depended unconditionally on `mb_strlen`, which is not required by the declared PHP minimum. | Use the WordPress excerpt helper. All three admin templates render without plugin warnings in the test installation. |
| U09 Low | Asset loading inferred hook names from menu text and could fail under translation. | Store the hook suffixes returned by `add_menu_page`/`add_submenu_page` and load assets against those values. |
| U10 Low | UI strings, elapsed times, and duplicate early text-domain loading were inconsistent with localization and site timezone. | Localize JavaScript strings, use WordPress human-time formatting with the site zone, and load translations once on `init`. |
| U11 Low | Downloads advertised a full response even when only a configured excerpt existed; data-URL/browser cleanup behavior was inconsistent. | Label the action Download Stored Response, export text/plain, and revoke the object URL after the browser starts downloading. |
| U12 Low | The installed-schema label read the wrong option; mandatory columns could disappear; input labels and external-link attributes were incomplete. | Read `erm_pro_db_version`, sanitize essential host/actions columns, associate numeric settings labels with their IDs, identify required columns, and add `noopener noreferrer` on external new-tab links. |
| U14 Medium | Keeping the release number unchanged would allow browsers to reuse the old JavaScript/CSS after this update. | Add each asset's file modification time to its WordPress version query so deployed asset changes refresh browser caches. |
| U13 Low | Documentation still described recoverable soft deletion and duplicated deleted-log features; advertised hooks were not consistently implemented. | Document hard deletion, host policy, retained excerpts, privacy, and migration behavior; implement the listed hooks and provide reproducible test instructions. |

## Verification

| Check | Result | What it establishes |
| --- | --- | --- |
| WordPress/MariaDB integration | 33 scenarios passed | Schema and upgrade preservation, host policies, actual HTTP responses, nesting, errors, privacy bounds, audit failures, retention, input validation, AJAX, template rendering, redirects |
| Multisite lifecycle | 4 checks passed | Existing-site installation, new-site installation, isolated policies, cron cleanup/context restoration |
| Independent PHP workers | 2 tests passed | Four-worker request aggregation and fixed-window quota serialization with WordPress's default object cache |
| Dashboard DOM | 11 tests passed | Numeric/zero handling, text safety, deletion flow, quota fields, failure recovery, counts, modal keyboard/backdrop behavior, selection state |
| PHP syntax | PHP 8.2 and PHP 7.2 checks passed | Source, templates, PHP tests, and the HTTP fixture parse under both runtimes |
| WordPress coding standard | Configured lint passed | Reviewed source passes WordPress-Extra with the documented exceptions |
| JavaScript build/check | Passed | Shipped JavaScript parses; no generated bundle is required |
| npm dependency audit | Zero vulnerabilities reported | Audit result for the locked development dependency tree at review time |

The test database is disposable `erm_review`, guarded by an explicit matching database-name argument and an `ermtest_` table prefix. No production WordPress database or configuration was used. Runtime integration used PHP 8.2.12, MariaDB 10.11, and the local WordPress core reporting version 7.1. PHP 7.2 was checked in a read-only container. Tests cover ordinary and multisite contexts.

The initial repeated integration run after resuming failed HTTP cases because the loopback fixture had stopped. Restarting that fixture and rerunning the suite is required for the recorded passing result. Fault-injected failures in the suite are intentional and are asserted as errors rather than successful writes.

The local PHP configuration emits a duplicate OpenSSL startup warning. The installed WordPress coding-standard package references two deprecated PHPCS sniffs. These are environment/tooling notices, not plugin runtime warnings. They do not invalidate the passing lint result.

See [tests/README.md](../tests/README.md) for commands and test safeguards.

## Deployment and behavior changes

1. Install the code and run **Settings → Database Updater** on each existing site whose installed schema is older than 1.2.0. The logger waits for the current schema before writing records; host policy enforcement can still read the older policy columns.
2. The updater adds `last_request_token`, checks the unique key, and converts legacy tables to InnoDB. Database DDL is not fully transactional: a failed upgrade may have applied some earlier schema changes, but the version is not advanced and the populated table is not renamed.
3. Blocking/unblocking and quota updates affect every active method row for the selected host. Counters remain per host/method, so cards show aggregate rows rather than unique hosts or total individual requests.
4. Automatic retention keeps policy-bearing rows. Explicit deletion and clearing remove the selected rows and their policy information. Deletion audit entries cannot reconstruct the original full record.
5. Existing body-storage settings are preserved. Default storage for newly unconfigured settings is disabled. Downloads expose only the stored excerpt.
6. New URL records are redacted; historical stored URL records are not rewritten. Legacy URLs are redacted when shown.

## Remaining limits and follow-up verification

- Full runtime compatibility with WordPress 5.0/PHP 7.2 was not exercised. The source includes the older multisite hook/Requests exception/timezone fallbacks and passes PHP 7.2 syntax checks, but syntax is not a complete runtime matrix.
- DOM tests do not verify visual layout in real browsers, every translated locale, screen readers, zoom, or keyboard behavior under all assistive technologies. Responsive/focus styles were inspected in source.
- A persistent Redis/Memcached object-cache deployment was not exercised. The independent-worker test used the default WordPress cache; shared persistent caches must honor transient writes correctly.
- MySQL 5.7 itself was not exercised; the runtime database was MariaDB 10.11. Logging and quotas require working advisory locks. A failed quota lock/state write refuses a rate-limited call, while logging-lock failure skips that log write.
- Requests made outside the WordPress HTTP API are not intercepted. Redirect target rules are enforced, but redirect hops are not represented as separate dashboard aggregates.
- Redaction is intentionally limited to common URL credentials. It cannot guarantee removal of sensitive values in arbitrary path segments, custom query names, retained response bodies, or pre-existing database backups.
- The bundled Requests parser treated an HTTP body of `"0"` as empty before the plugin hook. The regression test verifies preservation when WordPress delivers that value, without changing WordPress core.
- Source attribution is a best-effort stack heuristic and an aggregate retains its first available source. If several plugins contact one host/method, the row is not a complete per-caller audit.
- Size metrics estimate request arguments, not compressed wire traffic. The stored response represents the latest attempted aggregate call; an older response cannot replace a newer token.
- Very large multisite networks, schema conversions, and bulk retention/clear operations were not load-tested. Network lifecycle operations iterate sites; deletion transactions lock matching rows. Schedule large migrations appropriately.
- Runtime logging/response writes remain best-effort diagnostics. AJAX mutations surface write failures; the plugin does not make arbitrary caller HTTP failures depend on successfully storing monitoring data.

## Primary contract references

The callback repair follows WordPress's documented [http_api_debug action](https://developer.wordpress.org/reference/hooks/http_api_debug/). Respecting earlier preemptions follows the [pre_http_request filter](https://developer.wordpress.org/reference/hooks/pre_http_request/). The in-place schema fix was checked against the implementation of [dbDelta](https://developer.wordpress.org/reference/functions/dbdelta/), including its CREATE TABLE parser.

The [PR description draft](PR_DESCRIPTION.md) summarizes the final behavior and validation for submission.
