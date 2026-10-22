# Scrapling admin with Laravel Herd

Laravel 12 and Filament 4 provide a browser dashboard for [Scrapling](https://github.com/D4Vinci/Scrapling) 0.5. Test page metadata or find e-mail addresses on a single page or linked website pages. Laravel Herd serves the app on macOS; Python performs the fetch, and SQLite stores application data.

## Set up on your Mac mini

1. Install/open [Laravel Herd](https://herd.laravel.com). Install PHP 8.4 in Herd and enable its PHP and Composer CLI tools. Open a new terminal and confirm `php -v` and `composer --version` work. PHP needs intl, mbstring, DOM/XML, cURL, ZIP, and PDO SQLite extensions; Herd's PHP normally includes them.
2. Install Python 3.12 and Node.js using [Homebrew](https://brew.sh). Node.js 24 LTS is recommended (`.node-version` pins CI to 24):

   ```sh
   brew install python@3.12 node
   ```

3. From your checkout:

   ```sh
   git pull origin main
   herd isolate 8.4
   herd link scrapling
   sh scripts/setup.sh
   ```

   If the project is already in a Herd parked directory, you can skip linking. You can also add it through Herd's Sites interface.

4. Open `http://scrapling.test/admin`. Sign in with username **vladmar** and the temporary local password **tiborzla**. Open **Test scraper**, select **https://** (the default) or **http://**, enter a public domain or page address, and click **Test URL**. You can also paste a complete HTTP or HTTPS URL; its protocol takes precedence over the selector. The profile menu lets you change the password; rerunning setup will preserve your change.

The homepage redirects to the admin panel. A local administrator is created only when `APP_ENV=local` (or in automated tests), and only if that username does not already exist. The account uses `vladmar@localhost.test` as its initial profile email; login uses the username. Update the email in your profile if needed. The development password is intentionally shared for this local setup; choose a new one before making the site accessible to others. No registration or password-reset email flow is enabled.

`sh scripts/setup.sh` installs locked PHP/Python/npm dependencies, publishes Filament's assets, builds the admin theme, creates a missing `.env` and application key, runs migrations, and creates the local administrator. It preserves an existing `.env`, key, database, and administrator password. Run it again after pulling updates. It can be invoked from any directory. Use `PYTHON_BIN=/absolute/path/to/python3.12 sh scripts/setup.sh` if Python is not on PATH.

Herd runs the webserver. No Docker, `php artisan serve`, API keys, or external database are required. Node.js/npm are needed during setup to build the plugin theme; you do not need to keep a frontend development server running. Set `APP_URL` in `.env` if your Herd site name differs from `scrapling.test`. To enable HTTPS, run `herd secure scrapling` and set `APP_URL=https://scrapling.test`.

## Python and fetching

Laravel launches the absolute project-local `.venv/bin/python` path, so Herd does not need Homebrew's Python on its webserver PATH. Override this with `SCRAPLING_PYTHON` in `.env` if necessary, then run `php artisan config:clear`. Create the virtual environment on your Mac; do not copy it from Linux or another CPU architecture.

The tester fetches immediately and shows a loading state or a readable error. Access requires an administrator session. Dashboard requests check for public destinations at each redirect and reject URLs with credentials, local/private addresses, and non-HTTP schemes. Testing is limited to ten attempts per minute per account. The fetch timeout is 30 seconds and the entire Python process is limited to 45 seconds.

This version extracts page metadata using Scrapling's HTTP fetcher. It does not execute JavaScript or install browser-based anti-bot features. Full results are displayed for the current session. A summary of each dashboard attempt is saved in Scrape history; attempts are not scheduled scraping jobs. Respect the target site's access rules and rate limits.

The optional CLI remains available for development, including local fixtures:

```sh
php artisan scrape https://example.com
```

## E-mail scraper

Open **E-mail scraper** in the Filament sidebar (`http://scrapling.test/admin/email-scraper`). Enter a public domain such as `zlatibor.org.rs`, or paste a complete page URL. HTTPS is the default; a full URL overrides the protocol selector. Choose **Single page** for one page or **Website (linked pages)** to follow links from the starting page. Optionally enter a **Scan name** (otherwise the starting hostname is used), then click **Find e-mails**.

E-mail scans run as background jobs. Submission returns immediately, and the page polls for queued, running, completed, or failed status. You can leave the page, refresh, or reopen **Status / results** from Saved e-mail scans. Repeated submission from an active screen reuses its scan. A queued scan can be cancelled while waiting for a worker.

Results show unique addresses from page text and `mailto:` recipients, with every source page on which an address was found. The **Crawl log** records each discovered page's first referring page, link depth, final URL, fetch outcome, and e-mail count. The starting page has depth 0. Relative links are resolved, fragments are removed, and visited URLs are tracked to avoid repeated fetching and navigation loops. A failed linked page does not discard other results.

Website mode follows links breadth first on the starting page's final hostname, including after an initial redirect to a canonical hostname such as `www`. Links and later redirects to other hostnames or ports are excluded; all fetched destinations still undergo the public-URL checks. Common downloadable assets are skipped. Default limits are **20 pages** and **3 link levels**, adjustable to 1–50 pages and depth 0–5. There is a **30-second crawl budget**, a 45-second subprocess timeout, a 500-discovered-page cap, and a short delay between pages. Limits and unvisited queued pages are shown explicitly, so a limited scan does not claim to have covered the whole website.

This basic crawler discovers pages through HTML links; it does not use sitemaps, parse `robots.txt`, execute JavaScript, or decode addresses hidden in images or scripts. It may therefore miss pages and addresses. Respect the site's access rules. If a scan reaches a limit, try a specific section or contact page as the next starting URL. Scans are limited to five attempts per minute per administrator.

Completed fetches, including scans stopped by a crawl limit, automatically save valid e-mails, source pages, and a named scan to SQLite. The result shows how many contacts are new or already saved. Each normalized address is stored once across all scans; matching ignores case and surrounding whitespace and is also enforced by a case-insensitive database unique index. Distinct addresses containing dots or `+tags` stay distinct. Repeated discoveries merge source pages, update the last-found time, and preserve manually edited contact names. Each run gets its own scan record, even when its contacts were already saved or no addresses were found.

Use **Saved e-mails** (`/admin/saved-emails`) to search contacts, filter by saved scan, view source pages and first/last-found times, or **Rename** a contact. Initial contact names use the e-mail domain as a neutral label; change them to a person or organization name as needed. Use **Saved e-mail scans** (`/admin/email-scans`) to rename scans, filter by status, open **Status / results**, or **View e-mails** for one scan. Both screens require an administrator. Names can repeat; e-mail addresses remain fixed in the editor to preserve duplicate matching.

Stored scan/source URLs and crawl results omit credentials, query strings, and fragments, so source pages distinguished only by a query string are grouped. A redacted crawl log is retained for reopening results; page HTML and raw exception messages are not stored in scan records. The original request URL is temporarily encrypted with the application's existing key so the worker can fetch it, and is cleared on completion, failure, or cancellation. Earlier saved scans keep their contacts and names but have no stored crawl log. Scans made before the archive feature must be rerun to save their addresses. The archive is separate from Scrape history.

After pulling this update on your Mac, apply the queue-state migration and refresh configuration and theme styles:

```sh
git pull origin main
php artisan migrate
php artisan config:clear
npm run build
```

Start the worker in a separate terminal from the project folder and leave it running while using the e-mail scraper:

```sh
php -d max_execution_time=0 artisan queue:work scraping --tries=1 --timeout=75
```

Herd continues serving the website. Closing the worker terminal leaves new scans queued; start the command again to process them. Stop and restart the worker after pulling code updates. `composer run dev` also starts this worker alongside the frontend tools; use one of these approaches. No Redis, extra packages, or scheduler are needed. The repeatable setup script runs migrations while preserving existing data, application keys, and administrator passwords.

The dedicated `scraping` connection uses the existing SQLite `jobs` table even when `QUEUE_CONNECTION=sync` is configured elsewhere. The web request only saves the scan and enqueues its identifier. The Python process remains limited to 45 seconds, the job to 75 seconds, and the database reservation is 120 seconds. A scan is atomically claimed once; jobs are not automatically retried, so interrupted jobs cannot silently repeat a crawl. Normal failures are shown on the saved scan; worker timeouts use the failure callback, and polling recovers a running record older than 120 seconds if the worker was killed. Start a new scan after a failure. A storage failure retains the redacted fetched result and rolls back contact changes.

### Diagnosing failed scans

A failed fetch must produce **FAIL** in the worker and a failed saved scan. It is also registered in `php artisan queue:failed`; start a new scan rather than replaying the old job. Reaching the crawl budget after fetching useful pages is a successful partial scan with the time limit reported in its results. A timeout before any page is fetched is a failure.

To test fetching directly on your Mac, without saving contacts or adding a queued scan, run:

```sh
php -d max_execution_time=0 artisan scrape:diagnose https://zlatibor.org.rs --website --json
```

The report includes PHP/Python/package versions, configured timeout layers, elapsed time, and a safe error category such as `dns_error`, `tls_error`, `connection_error`, `request_timeout`, `process_timeout`, or `http_error`. URL query strings, credentials, fragments, raw exceptions, and e-mail addresses are omitted. Share this report to diagnose a local failure. A `succeeded` report with a failed queued scan points to the worker or saving stage rather than the direct fetch; stop/restart the worker after pulling code updates. `php artisan config:clear` refreshes configuration changes.

The worker regression suite uses a local HTTP fixture to test a real request timeout, partial results on budget exhaustion, and a worker run lasting over 30 seconds:

```sh
php artisan test --filter=EmailWorkerIntegrationTest
```

The deliberate long-running test takes approximately 31 seconds. Production fetch, crawl, subprocess, and queue limits remain bounded; TLS verification and public URL checks stay enabled.

## Installed Filament plugins

| Plugin | What it provides |
| --- | --- |
| [Activity Log](https://github.com/rmsramos/activitylog) (3.x, backed by Spatie Activitylog 4.x) | **Monitoring → Scrape history**, with URL search, outcome/date filters, and read-only attempt details. |
| [Environment Indicator](https://github.com/pxlrbt/filament-environment-indicator) (3.x) | Current environment badge and colored top border, visible to administrators. |

These versions support Filament 4. The history integration records the signed-in administrator, requested page address, success/failure, duration, and successful response metadata. Stored URLs omit query strings, credentials, and fragments. Raw exception details and full scraped content are not saved. History pages require administrator access and cannot edit, delete, or restore records. CLI fetches are not recorded. History is retained in SQLite until deliberately cleaned; no cleanup scheduler is configured.

The custom admin theme scans the plugins' view files so their styling is included in the build. Run the setup script after pulling this update to install packages, build assets, and create the activity-log tables. There is no need to run plugin install commands manually.

## Development checks

```sh
php artisan test
.venv/bin/python -m unittest discover -s tests/Python
```

Tests cover username authentication, administrator restrictions, password changes, safe repeat seeding, form validation/results, URL destination policy, e-mail extraction, bounded link discovery, redirects, partial results, archive persistence/rollback, duplicate matching, editable names, saved-contact search/filtering, queue dispatch/recovery, encrypted request cleanup, redacted results, and a separate PHP worker consuming a real SQLite job through Python/Scrapling to a local HTTP fixture. GitHub Actions installs native PHP and Python and runs the checks without Docker.

For frontend development, run `composer run dev` alongside Herd. For a static theme rebuild, run `npm run build`; `sh scripts/setup.sh` also builds it automatically.

PHP dependencies are locked in `composer.lock` (PHP 8.4+ required), npm dependencies in `package-lock.json`, and Python 3.12 dependencies and hashes in `requirements.txt`. Keep `.env`, `.venv`, generated assets, and `vendor` out of Git.

## Shared project instructions

See [instructions/README.md](instructions/README.md) for the agreed workflow and [instructions/HANDOFF.md](instructions/HANDOFF.md) for the latest handoff. Future agents should start with the root [AGENTS.md](AGENTS.md).

## Design assistance for future work

The repository includes a Filament-specific `scrapling-ui-design` agent skill and a pinned, MIT-licensed copy of [Taste Skill](https://github.com/leonxlnx/taste-skill). See [instructions/design/README.md](instructions/design/README.md) for usage. No additional application installation is required.
