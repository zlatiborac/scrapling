# Current handoff

## Current state

- Native Laravel 12/Filament 4 + Python Scrapling + SQLite; use only `main`. The user uses macOS Herd and reported PHP's 30-second execution limit during e-mail scraping.
- E-mail scans now queue immediately instead of fetching/saving inside the web request. `EmailScanQueue` creates the scan and inserts its job atomically in SQLite; `ProcessEmailScan` claims it once, fetches, and archives into that same record. The dedicated `scraping` connection stays asynchronous even if the global queue is `sync`.
- Filament polls queued/running status, disables duplicate submission, supports cancelling queued work, and reopens results through `?scan=ID` / Saved e-mail scans → Status / results. Normal polls are read-only. The scan identifier is locked against Livewire request tampering; administrator access is rechecked in the UI and worker.
- Bounds: 30-second crawl budget, 45-second Python subprocess, 75-second job, 120-second database reservation. One job attempt; failures do not automatically crawl again. A failure callback and stale-running recovery on polling handle interrupted workers. Saving remains transactional and preserves edited scan/contact names and duplicate matching.
- Original request URLs are temporarily encrypted and hidden from model serialization, then cleared on completion/failure/cancellation. Persisted results/logs redact URLs and raw errors; page HTML is not stored. Older saved records remain completed and retain contacts/names, with no historical crawl log.
- One additive migration adds queue/result state to `email_scans`; existing jobs tables are reused. No new dependencies, Redis, Docker, or scheduler. `composer run dev` now starts the dedicated worker.

## Validation

- 78 Laravel tests passed (548 assertions), including asynchronous dispatch despite a sync default, duplicate delivery, encrypted input cleanup, restored/redacted results, cancellation, queue failure rollback, stale workers, access checks, and a separate PHP process consuming a real SQLite job through Python/Scrapling to the local HTTP fixture. The polling refinement was also checked with the queue suite.
- All 17 Python tests, npm production build, Composer validation, PHP formatting, and Git whitespace checks passed. Native SQLite upgrade/rollback/reapply preserved existing contacts, names, and membership.
- Browser visual, macOS/Herd-specific, and external live fetch checks were not performed. The cloud previously blocked zlatibor.org.rs through DNS/proxy restrictions.

## Follow-up on the Mac

- Pull `main`, run `php artisan migrate`, `php artisan config:clear`, and `npm run build`.
- Leave `php -d max_execution_time=0 artisan queue:work scraping --tries=1 --timeout=75` running in a separate terminal alongside Herd (or use `composer run dev`). Stop/restart the worker after code updates. If it is stopped, scans remain queued; the dashboard explains how to start it.
