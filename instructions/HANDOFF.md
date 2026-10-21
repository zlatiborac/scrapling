# Current handoff

## Current state

- Native Laravel 12/Filament 4 + Python Scrapling + SQLite app; use only `main`. The user confirms the scraper works on their Mac with Herd. Existing URL tester, protocol selector, plugins, public URL checks, and crawl limits remain unchanged.
- E-mail scraper now automatically archives valid discovered addresses, source pages, and named scans, including partial and empty results. Both scan names and individual contact names are editable, as requested by the user.
- `app/Services/EmailArchive.php` stores normalized contacts, unique source pages, scans, and scan membership in one transaction. E-mails are trimmed/lowercased, with a SQLite NOCASE unique index preventing case-only duplicates. Repeated scans reuse contacts, merge sources, update last-found times, and preserve edited names. Dots and plus tags are not collapsed.
- New administrator-only **Saved e-mails** (`/admin/saved-emails`) and **Saved e-mail scans** (`/admin/email-scans`) resources provide search, scan filtering, source details, and name-editing modals. Contact names default to the e-mail domain; optional scan names default to the starting hostname. Every run has a separate scan record.
- Stored URLs omit credentials, query strings, and fragments. Detailed crawl logs/raw fetched content remain ephemeral; no resume or retroactive recovery of earlier scans. A storage failure preserves the displayed fetch result, reports that saving failed, and never repeats the crawl.
- One additive migration creates the archive tables. No new dependencies, scheduler, or queue workers. New resources are explicitly registered in `AdminPanelProvider`; model policies restrict viewing/renaming to administrators.

## Validation

- 66 Laravel tests passed, covering duplicate/index enforcement, source merging/redaction, transaction rollback, name preservation/edit validation, resource access/revocation, search/filtering, and real PHP → Python → Scrapling fixture fetching plus archive storage.
- All 17 Python tests, npm production build, PHP formatting, and Git whitespace checks passed. The archive migration applied, rolled back, and reapplied successfully on a temporary SQLite database.
- Browser visual and macOS-specific checks were not performed. No external fetch was needed for this storage change; the user has confirmed the previous scraper works locally. The cloud environment previously blocked `zlatibor.org.rs` through DNS/proxy restrictions.

## Follow-up

- On the Mac: `git pull origin main`, `php artisan migrate`, then `npm run build`.
- Rerun earlier scans to populate the archive. Use Saved e-mails and Saved e-mail scans to review contacts and rename either kind of record.
