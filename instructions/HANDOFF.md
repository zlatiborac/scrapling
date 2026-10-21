# Current handoff

## Current state

- Native Laravel 12/Filament 4 + Python Scrapling + SQLite app; work only on `main`. The user confirms their Mac/Herd setup works. Existing protocol selector, URL tester, plugins, and history are preserved.
- New **E-mail scraper** sidebar page at `/admin/email-scraper`: single page or linked website mode, protocol selection, bounded page/depth settings, unique e-mails with source URLs, and a crawl log showing first referrer, depth, final URL, and outcome.
- `app/Services/EmailScraper.php` launches `scripts/email_scrape.py`. The crawler follows same-host links breadth first, validates public destinations at every fetch/redirect via the existing URL policy, deduplicates URLs, and continues after child-page failures. Initial redirects may establish a canonical hostname; later redirects cannot leave that site.
- Defaults: 20 pages, depth 3; maximum 50 pages/depth 5, 30-second crawl budget, 45-second process timeout, 500 discovered URLs, and a short inter-page delay. The UI reports limits, pending pages, and skipped depth-limited links.
- Extraction covers text and mailto recipients, with case-insensitive deduplication. No JavaScript, sitemaps, robots.txt parsing, obfuscated-address decoding, background jobs, persistence, or resume. Results/logs live on the current screen and are not stored in Scrape history. No dependency or schema changes.

## Validation

- 51 Laravel tests passed (308 assertions), including administrator access/revocation, form and limit validation, escaping, rate limiting, and real PHP → Python → Scrapling fixture fetches for single-page and website scans.
- All 17 Python tests passed, covering extraction, source/referrer tracking, duplicate/redirect loops, public URL checks, cross-site redirects, partial results, limits, and failures. npm production build, PHP formatting, and Git whitespace checks passed.
- Live test target supplied by the user: `https://zlatibor.org.rs`. The cloud crawler failed at public-host DNS resolution; a separate proxy connectivity check returned CONNECT 403. No live e-mail results were verified. Browser visual checks were unavailable, and macOS-specific verification was not performed here.

## Follow-up

- On the Mac: `git pull origin main`, then `npm run build`. No migrations, dependency installs, or queue workers are needed for this change.
- Open E-mail scraper and try `zlatibor.org.rs`, first as Single page, then Website. A limited scan may require using a section or contact page as a new starting URL.
