# Laravel + Scrapling with Laravel Herd

Laravel 12 with [Scrapling](https://github.com/D4Vinci/Scrapling) 0.5 as a local Python subprocess. Laravel Herd serves the application on macOS; SQLite stores application data. No Docker, API keys, Redis, or separate database server are required.

## Set up on your Mac mini

1. Install/open [Laravel Herd](https://herd.laravel.com). In Herd, install PHP 8.4 and enable its PHP and Composer CLI tools. Open a new terminal and confirm `php -v` and `composer --version` work.
2. Install Python 3.12 with [Homebrew](https://brew.sh):

   ```sh
   brew install python@3.12
   python3.12 --version
   ```

3. In your repository checkout, select its PHP version and link it to Herd:

   ```sh
   cd /path/to/scrapling
   herd isolate 8.4
   herd link scrapling
   sh scripts/setup.sh
   php artisan test
   ```

   Alternatively, add the repository directory through Herd's Sites interface. Herd serves Laravel's `public` directory. If the project is already in a parked directory, linking it again is unnecessary.

4. Open `http://scrapling.test`. The example `.env` sets `APP_URL` to that address. If you use another site name, update `APP_URL` accordingly. For HTTPS, run `herd secure scrapling` and change `APP_URL` to `https://scrapling.test`.

Herd runs the webserver, so you do not need `php artisan serve`. The initial welcome page needs no frontend build. For frontend development, install Node.js/npm, run `npm install`, then `composer run dev` to start Vite, the queue worker, and the log viewer alongside Herd.

The setup script can be run from any working directory. It creates the ignored `.venv`, installs hash-locked Python dependencies and Composer dependencies, copies `.env.example` only if `.env` is missing, generates a missing application key, creates SQLite if needed, and runs migrations. It preserves an existing `.env`, application key, and SQLite database. Use `PYTHON_BIN=/absolute/path/to/python3.12 sh scripts/setup.sh` if Python is not on PATH. Re-run setup after pulling dependency changes.

## Test Scrapling

From the project directory:

```sh
php artisan scrape https://example.com
```

The command prints JSON with the resolved URL, HTTP status, title, and H1 headings. It exits nonzero on HTTP errors or subprocess failures. Fetching has a 30-second timeout; the subprocess has a 45-second timeout.

Laravel uses the absolute project-relative `.venv/bin/python` path by default, so Herd does not need Homebrew's Python on its webserver PATH. You can override the executable with `SCRAPLING_PYTHON=/absolute/path/to/python` in `.env`; run `php artisan config:clear` after changing configuration. Never commit `.env` or `.venv`. Create the virtual environment on the machine where it will run; do not copy one from Linux or another Mac. This matters for Apple Silicon versus Intel Python packages.

## Checks and scope

```sh
php artisan test
```

Tests exercise the full Laravel → Python → Scrapling → local HTTP fixture round trip, URL validation, and HTTP error handling. They need no external network access. GitHub Actions installs PHP and Python directly and runs these tests without Docker.

This integration uses Scrapling's HTTP fetcher. Browser rendering and browser-based anti-bot features are not installed. Scraping is currently exposed through the Artisan CLI; the Herd website shows Laravel's welcome page. Do not expose arbitrary URL input through a public endpoint without destination restrictions. Respect target access rules and rate limits.

PHP dependencies are locked in `composer.lock`; this lock requires PHP 8.4+. Python 3.12 dependencies and hashes are locked in `requirements.txt`, with the direct dependency in `requirements.in`. To deliberately refresh the Python lock, use `uv pip compile requirements.in -o requirements.txt --generate-hashes`.
