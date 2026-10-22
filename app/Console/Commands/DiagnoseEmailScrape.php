<?php

namespace App\Console\Commands;

use App\Exceptions\EmailFetchFailed;
use App\Jobs\ProcessEmailScan;
use App\Services\EmailArchive;
use App\Services\EmailScanResult;
use App\Services\EmailScraper;
use Illuminate\Console\Command;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class DiagnoseEmailScrape extends Command
{
    protected $signature = 'scrape:diagnose {url : Public page URL} {--website : Follow linked pages} {--json : Print a shareable JSON report}';

    protected $description = 'Test the e-mail fetch without saving contacts and report safe runtime diagnostics';

    public function handle(EmailScraper $scraper): int
    {
        $report = [
            'target' => EmailArchive::safeUrl($this->argument('url')),
            'mode' => $this->option('website') ? 'website' : 'page',
            'php_version' => PHP_VERSION,
            'php_max_execution_time' => ini_get('max_execution_time'),
            'python' => config('scrapling.python'),
            'request_timeout_seconds' => config('scrapling.email_request_timeout'),
            'crawl_budget_seconds' => config('scrapling.email_crawl_timeout'),
            'process_timeout_seconds' => config('scrapling.email_process_timeout'),
            'job_timeout_seconds' => (new ProcessEmailScan(0))->timeout,
            'queue_retry_after_seconds' => config('queue.connections.scraping.retry_after'),
        ];
        $started = hrtime(true);
        try {
            $python = new Process([config('scrapling.python'), '--version'], base_path());
            $python->setTimeout(5)->run();
            if (! $python->isSuccessful()) {
                throw new EmailFetchFailed('python_unavailable');
            }
            $report['python_version'] = trim($python->getOutput());
            $packages = new Process([config('scrapling.python'), '-c', 'import json, importlib.metadata as m; print(json.dumps({p: m.version(p) for p in ("scrapling", "curl-cffi")}))'], base_path());
            $packages->setTimeout(5)->run();
            if (! $packages->isSuccessful()) {
                throw new EmailFetchFailed('python_error');
            }
            $report['python_packages'] = json_decode($packages->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $result = $scraper->scrape($this->argument('url'), (bool) $this->option('website'));
            $report += ['status' => 'succeeded', 'fetched_pages' => $result['fetched'], 'unique_emails' => count($result['emails']), 'limits' => $result['limits']];
            $report['page_errors'] = array_values(array_unique(array_map(
                static fn (array $page): string => $page['error'],
                array_filter(EmailScanResult::sanitize($result)['pages'], static fn (array $page): bool => $page['status'] === 'failed'),
            )));
        } catch (Throwable $exception) {
            if ($exception instanceof ProcessStartFailedException || $exception instanceof ProcessTimedOutException) {
                $exception = new EmailFetchFailed('python_unavailable');
            }
            $report = array_replace($report, ['status' => 'failed', 'reason' => $exception instanceof EmailFetchFailed ? $exception->reason : 'runtime_error',
                'message' => EmailScanResult::failureMessage($exception)]);
        }
        $report['elapsed_seconds'] = round((hrtime(true) - $started) / 1_000_000_000, 3);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($report as $key => $value) {
                $this->line($key.': '.(is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value));
            }
        }

        return $report['status'] === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
