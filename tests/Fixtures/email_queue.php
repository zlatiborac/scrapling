<?php

// Isolated CLI integration fixture. Never loaded by the application.
use App\Models\EmailContact;
use App\Models\EmailContactSource;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailScanQueue;
use App\Services\EmailScraper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing')) {
    throw new RuntimeException('This fixture is only for testing.');
}

$mode = $argv[1];
$events = [];
if ($mode === 'submit') {
    Artisan::call('migrate', ['--force' => true]);
    $user = User::factory()->create(['is_admin' => true]);
    app(EmailScanQueue::class)->submit(['url' => $argv[2], 'mode' => 'website', 'max_pages' => 20, 'max_depth' => 3], $user);
} else {
    Event::listen(JobFailed::class, function () use (&$events): void {
        $events[] = 'failed';
    });
    Event::listen(JobProcessed::class, function () use (&$events): void {
        $events[] = 'processed';
    });
    if (in_array($mode, ['work-timeout', 'work-budget'], true)) {
        config(['scrapling.email_request_timeout' => $mode === 'work-timeout' ? 0.15 : 0.6]);
        if ($mode === 'work-budget') {
            config(['scrapling.email_crawl_timeout' => 0.5]);
        }
    }
    if ($mode === 'work-over-30') {
        // Deliberate overhead before fetching proves the worker survives the web PHP limit.
        app()->instance('fixture.worker_delay', 31);
    }
    // Permit only the local fixture here; production jobs always use public-only fetching.
    app()->bind(EmailScraper::class, fn () => new class extends EmailScraper
    {
        public function scrape(string $url, bool $website = false, int $maxPages = 20, int $maxDepth = 3, bool $publicOnly = true): array
        {
            if (parse_url($url, PHP_URL_HOST) !== '127.0.0.1') {
                throw new RuntimeException('Only the local HTTP fixture is permitted.');
            }
            if (app()->bound('fixture.worker_delay')) {
                sleep(app('fixture.worker_delay'));
            }

            return parent::scrape($url, $website, $maxPages, $maxDepth, publicOnly: false);
        }
    });
    Artisan::call('queue:work', ['connection' => 'scraping', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 75]);
}

$report = [
    'status' => EmailScan::sole()->status, 'scans' => EmailScan::count(),
    'contacts' => EmailContact::count(), 'sources' => EmailContactSource::count(), 'jobs' => DB::table('jobs')->count(),
];
if (! in_array($mode, ['submit', 'work'], true)) {
    $report += ['worker_output' => Artisan::output(), 'events' => $events,
        'failed_jobs' => DB::table('failed_jobs')->count(), 'failure_message' => EmailScan::sole()->failure_message,
        'result' => EmailScan::sole()->result];
}
echo json_encode($report, JSON_THROW_ON_ERROR);
