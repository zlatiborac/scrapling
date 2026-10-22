# Current handoff

## Current state

- Native Laravel 12/Filament 4 + Python Scrapling + SQLite; use only `main`. E-mail scans use the dedicated `scraping` database queue, persisted status/results, encrypted temporary request URLs, editable scan/contact names, and duplicate matching.
- The user's Mac diagnostic report identified the latest failure: Python fetched **40 pages / 38 unique e-mails**, then `Illuminate\Database\Eloquent\JsonEncodingException` occurred while saving the redacted result, before contact archiving. Worker max_execution_time is 0; web and worker use the same SQLite file. The report has 5 scans and 2 failed jobs, with 0 contacts. This establishes a local serialization failure, not a fetch timeout or evidence of firewall rejection. The report omitted the underlying JSON error text, so the exact original malformed value is unavailable.
- Reproduced the same exception with malformed UTF-8 in a crawl URL. `EmailArchive::safeUrl` now percent-escapes non-ASCII/control bytes before parse_url, preserving existing escapes and preventing locale-dependent multibyte handling. The `EmailScan` result-only JSON cast substitutes malformed UTF-8 in optional crawl text. Encrypted request data stays strict; invalid addresses are still skipped, and valid contacts/names/deduplication remain intact.
- `ProcessEmailScan` remembers when fetching has succeeded, so failures before the result checkpoint are described as saving errors. Activity now separates saving the crawl log from archiving contacts. Diagnostics version 2 recognizes `json_encoding_error` plus safe encoding categories without logging raw exceptions or source values.
- Existing administrator-only Scan activity / Diagnostic report / Recent scans remain file-backed and available after reload/transaction rollback. Logs rotate under storage/logs for 14 days; UI reads bounded tails from today/yesterday. CLI scrape:diagnose remains available. Bounds remain request 10s, crawl 30s, subprocess 45s, job 75s, reservation 120s.

## Validation

- 106 Laravel tests passed (753 assertions), all 19 Python tests passed, and PHP formatting/Git whitespace checks passed.
- New regressions failed before the fix and now pass: malformed URL bytes no longer discard 38 valid contacts; Serbian Latin/Cyrillic paths round-trip through ASCII URIs without double encoding; invalid optional crawl text does not block contacts; encrypted request JSON remains strict; checkpoint serialization errors show saving failures and safe diagnostic details.
- A separate real PHP worker/Python HTTP fixture follows Serbian URL paths and saves 2 contacts / 3 sources plus the redacted crawl log, with no failed jobs. Existing real timeout, partial-budget, and over-30-second regressions still pass.
- No frontend sources, dependencies, or migrations changed. No new browser visual check was needed for this backend fix. Cloud network policy blocks zlatibor.org.rs; the fix still needs a live rerun on the user's Mac, and the exact original bad value was not included in the report.

## Follow-up on the Mac

- Stop the existing worker, pull main, and restart `php -d max_execution_time=0 artisan queue:work scraping --tries=1 --timeout=75` from this project. No migration, package install, or asset rebuild is needed for this update.
- Start a fresh Website scan of https://www.zlatibor.org.rs/. Old failed attempts without a stored result must be rerun; their fetched e-mails cannot be recovered from the diagnostic counts. If it fails, the updated Diagnostic report should identify the saving stage and encoding category (version 2).
