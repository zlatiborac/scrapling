<?php

// Isolated CLI integration fixture. Never loaded by the application.
use App\Models\EmailContact;
use App\Models\EmailContactSource;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailScanQueue;
use App\Services\EmailScraper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('This fixture is only for testing.');
}

if ($argv[1] === 'submit') {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['is_admin' => true]);
    app(EmailScanQueue::class)->submit(['url' => $argv[2], 'mode' => 'website', 'max_pages' => 20, 'max_depth' => 3], $user);
} else {
    // Permit only the local fixture here; production jobs always use public-only fetching.
    app()->bind(EmailScraper::class, fn () => new class extends EmailScraper
    {
        public function scrape(string $url, bool $website = false, int $maxPages = 20, int $maxDepth = 3, bool $publicOnly = true): array
        {
            if (parse_url($url, PHP_URL_HOST) !== '127.0.0.1') {
                throw new RuntimeException('Only the local HTTP fixture is permitted.');
            }

            return parent::scrape($url, $website, $maxPages, $maxDepth, publicOnly: false);
        }
    });
    Artisan::call('queue:work', ['connection' => 'scraping', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 75]);
}

echo json_encode([
    'status' => EmailScan::sole()->status, 'scans' => EmailScan::count(),
    'contacts' => EmailContact::count(), 'sources' => EmailContactSource::count(), 'jobs' => DB::table('jobs')->count(),
], JSON_THROW_ON_ERROR);
