# Project context

This folder holds the shared instructions for the user and future coding agents. The root `AGENTS.md` directs agents here automatically. In a new chat, point the agent at this repository and ask it to read `AGENTS.md`.

## Agreed preferences

- Repository: `zlatiborac/scrapling` on GitHub.
- Use **only `main`**. Do not create feature branches or Git worktrees.
- The user works locally on a Mac mini with **Laravel Herd**. Keep setup native; do not reintroduce Docker.
- The user operates the scraper through the **Filament admin UI**, rather than the CLI. CLI tools remain useful for development and tests.
- Collaborate through GitHub: the user pushes local work, tells the agent what to do, then pulls the agent's tested changes. Agents must fetch first; they do not automatically monitor GitHub.
- For requested repository changes in this ongoing workflow, commit and push completed work to `main`, then report the commit and any local follow-up commands.
- Keep handoffs short to reduce the context needed in later chats. Record durable decisions here, not full chat transcripts.

## Design guidance

[Design assistance](design/README.md) describes the local `scrapling-ui-design` skill and the pinned Taste Skill snapshot. Use the short Filament-specific skill for routine UI work and the full upstream toolbox for larger redesigns.

## Project map

- Laravel 12, Filament 4, PHP 8.4+, Python 3.12, Scrapling 0.5, SQLite.
- Node.js 24/npm build the admin theme; native Herd remains the webserver.
- Plugins: Activity Log 3.x (Spatie Activitylog 4.x) and Environment Indicator 3.x.
- `app/Filament/Pages/TestScraper.php`: URL tester and results UI.
- `app/Filament/Pages/EmailScraper.php`: single-page/website e-mail form and automatic archive saving; detailed crawl log remains on the current screen.
- `app/Filament/Resources/EmailContactResource.php` and `EmailScanResource.php`: searchable saved e-mails and named scans, with administrator-only name editing.
- `app/Services/EmailArchive.php` and `app/Models/Email*.php`: transactional SQLite archive, normalized unique contacts, redacted source pages, and scan membership. Repeated scans preserve edited names.
- `app/Filament/Auth/Login.php`: username login.
- `app/Services/ScrapeHistory.php`: redacted summary logging for dashboard attempts.
- `app/Filament/Resources/ScrapeHistoryResource.php`: administrator-only, read-only history.
- `app/Services/Scraper.php`: Laravel-to-Python subprocess integration.
- `app/Services/EmailScraper.php` and `scripts/email_scrape.py`: bounded e-mail crawler, sources, and link tracking; shares the public URL policy from `scripts/scrape.py`.
- `scripts/scrape.py`: HTTP fetching, extraction, and URL destination policy.
- `scripts/setup.sh`: repeatable native installation, migrations, and local admin creation.
- `README.md`: Herd setup, local login, usage, and test commands.

The local site is `http://scrapling.test/admin`. Local administrator setup must preserve an existing password; never assume the starter password is still valid.
