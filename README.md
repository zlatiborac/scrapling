# Scrapling admin with Laravel Herd

Laravel 12 and Filament 4 provide a browser dashboard for [Scrapling](https://github.com/D4Vinci/Scrapling) 0.5. Paste a URL, click **Test URL**, and inspect the page title, HTTP status, H1 headings, and raw JSON. Laravel Herd serves the app on macOS; Python performs the fetch, and SQLite stores application data.

## Set up on your Mac mini

1. Install/open [Laravel Herd](https://herd.laravel.com). Install PHP 8.4 in Herd and enable its PHP and Composer CLI tools. Open a new terminal and confirm `php -v` and `composer --version` work. PHP needs intl, mbstring, DOM/XML, cURL, ZIP, and PDO SQLite extensions; Herd's PHP normally includes them.
2. Install Python 3.12 using [Homebrew](https://brew.sh):

   ```sh
   brew install python@3.12
   ```

3. From your checkout:

   ```sh
   git pull origin main
   herd isolate 8.4
   herd link scrapling
   sh scripts/setup.sh
   ```

   If the project is already in a Herd parked directory, you can skip linking. You can also add it through Herd's Sites interface.

4. Open `http://scrapling.test/admin`. Sign in with username **vladmar** and the temporary local password **tiborzla**. Open **Test scraper**, paste a public HTTP or HTTPS URL, and click **Test URL**. The profile menu lets you change the password; rerunning setup will preserve your change.

The homepage redirects to the admin panel. A local administrator is created only when `APP_ENV=local` (or in automated tests), and only if that username does not already exist. The account uses `vladmar@localhost.test` as its initial profile email; login uses the username. Update the email in your profile if needed. The development password is intentionally shared for this local setup; choose a new one before making the site accessible to others. No registration or password-reset email flow is enabled.

`sh scripts/setup.sh` installs dependencies, publishes Filament's prebuilt assets, creates a missing `.env` and application key, runs migrations, and creates the local administrator. It preserves an existing `.env`, key, database, and administrator password. Run it again after pulling updates. It can be invoked from any directory. Use `PYTHON_BIN=/absolute/path/to/python3.12 sh scripts/setup.sh` if Python is not on PATH.

Herd runs the webserver. No Docker, `php artisan serve`, Node.js build, API keys, or external database are required for the admin dashboard. Set `APP_URL` in `.env` if your Herd site name differs from `scrapling.test`. To enable HTTPS, run `herd secure scrapling` and set `APP_URL=https://scrapling.test`.

## Python and fetching

Laravel launches the absolute project-local `.venv/bin/python` path, so Herd does not need Homebrew's Python on its webserver PATH. Override this with `SCRAPLING_PYTHON` in `.env` if necessary, then run `php artisan config:clear`. Create the virtual environment on your Mac; do not copy it from Linux or another CPU architecture.

The tester fetches immediately and shows a loading state or a readable error. Access requires an administrator session. Dashboard requests check for public destinations at each redirect and reject URLs with credentials, local/private addresses, and non-HTTP schemes. Testing is limited to ten attempts per minute per account. The fetch timeout is 30 seconds and the entire Python process is limited to 45 seconds.

This version extracts page metadata using Scrapling's HTTP fetcher. It does not execute JavaScript or install browser-based anti-bot features. Results are displayed for the current session and are not saved as scraping jobs. Respect the target site's access rules and rate limits.

The optional CLI remains available for development, including local fixtures:

```sh
php artisan scrape https://example.com
```

## Development checks

```sh
php artisan test
.venv/bin/python -m unittest discover -s tests/Python
```

Tests cover username authentication, administrator restrictions, password changes, safe repeat seeding, form validation/results, URL destination policy, and a real Laravel → Python → Scrapling → local HTTP fixture round trip. GitHub Actions installs native PHP and Python and runs the checks without Docker.

For optional frontend asset development, install Node.js/npm, run `npm install`, then `composer run dev` alongside Herd. Filament's standard dashboard needs no custom frontend build.

PHP dependencies are locked in `composer.lock` (PHP 8.4+ required); Python 3.12 dependencies and hashes are locked in `requirements.txt`. Keep `.env`, `.venv`, generated assets, and `vendor` out of Git.
