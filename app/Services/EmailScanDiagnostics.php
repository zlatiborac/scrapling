<?php

namespace App\Services;

use App\Exceptions\EmailFetchFailed;
use App\Jobs\ProcessEmailScan;
use App\Models\EmailScan;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use PDOException;
use Throwable;

class EmailScanDiagnostics
{
    // Bump when the diagnostic protocol changes; a running worker retains its loaded version.
    public const VERSION = 2;

    private const EVENTS = [
        'queued' => 'Scan saved and added to the queue.',
        'enqueue_failed' => 'Could not save the scan and queue entry.',
        'worker_started' => 'Worker claimed the saved scan.',
        'claim_failed' => 'Worker could not read or claim the saved scan.',
        'scan_missing' => 'Worker found no saved scan for this job. Check the database and project directory in the report.',
        'fetching' => 'Starting the Python e-mail scraper.',
        'fetched' => 'Python returned results; saving the crawl log.',
        'archiving' => 'Crawl log saved; saving e-mails and source pages.',
        'completed' => 'E-mails saved; scan completed.',
        'failed' => 'Scan failed.',
        'cancelled' => 'Queued scan cancelled.',
        'interrupted' => 'No worker completion was received within 120 seconds.',
    ];

    public function runtime(): array
    {
        try {
            $connection = DB::connection();
            $database = ['connection' => $connection->getName(), 'driver' => $connection->getDriverName(), 'database' => $connection->getDatabaseName()];
            if ($database['driver'] === 'sqlite' && $database['database'] !== ':memory:') {
                $database['database'] = realpath($database['database']) ?: (realpath(base_path($database['database'])) ?: $database['database']);
            }
        } catch (Throwable) {
            $database = ['connection' => config('database.default'), 'unavailable' => true];
        }

        return [
            'diagnostics_version' => self::VERSION,
            'environment' => app()->environment(),
            'project_directory' => base_path(),
            'database' => $database,
            'php_version' => PHP_VERSION, 'php_binary' => PHP_BINARY, 'php_sapi' => PHP_SAPI,
            'php_max_execution_time' => ini_get('max_execution_time'),
            'process_id' => getmypid(),
            'request_started_at' => gmdate('c', (int) ($_SERVER['REQUEST_TIME'] ?? time())),
            'configuration_cached' => app()->configurationIsCached(),
            'python' => config('scrapling.python'),
            'python_executable' => is_executable(config('scrapling.python')),
            'request_timeout_seconds' => config('scrapling.email_request_timeout'),
            'crawl_budget_seconds' => config('scrapling.email_crawl_timeout'),
            'process_timeout_seconds' => config('scrapling.email_process_timeout'),
            'job_timeout_seconds' => (new ProcessEmailScan(0))->timeout,
            'queue_connection' => 'scraping', 'queue' => 'email-scans',
            'queue_retry_after_seconds' => config('queue.connections.scraping.retry_after'),
        ];
    }

    public function error(Throwable $exception): array
    {
        $error = ['exception_type' => $exception::class, 'message' => EmailScanResult::failureMessage($exception)];
        if ($exception instanceof EmailFetchFailed) {
            $error += ['reason' => $exception->reason, 'http_status' => $exception->httpStatus] + $exception->details;
        } elseif ($exception instanceof JsonEncodingException) {
            $error += ['reason' => 'json_encoding_error', 'encoding_error' => match (true) {
                str_contains($exception->getMessage(), 'Malformed UTF-8') => 'invalid_utf8',
                str_contains($exception->getMessage(), 'Recursion') => 'recursion',
                str_contains($exception->getMessage(), 'Inf and NaN') => 'non_finite_number',
                str_contains($exception->getMessage(), 'Maximum stack depth') => 'depth',
                str_contains($exception->getMessage(), 'Type is not supported') => 'unsupported_type',
                default => 'unknown',
            }];
        } elseif ($exception instanceof QueryException || $exception instanceof PDOException || $exception instanceof SQLiteDatabaseDoesNotExistException) {
            $error += ['reason' => 'database_error', 'sqlstate' => (string) $exception->getCode()];
            $error['message'] = 'The database operation failed. Check migrations, the database file, and write permissions.';
        } elseif ($exception instanceof DecryptException) {
            $error += ['reason' => 'request_decryption'];
            $error['message'] = 'The worker could not decrypt the saved request. The web app and worker must use the same APP_KEY.';
        } elseif ($exception instanceof TimeoutExceededException || $exception instanceof MaxAttemptsExceededException) {
            $error += ['reason' => 'worker_timeout'];
        } else {
            $error += ['reason' => 'runtime_error'];
        }

        return $error;
    }

    public function record(string $event, ?int $scanId = null, ?Throwable $exception = null, array $counts = []): void
    {
        if (! isset(self::EVENTS[$event])) {
            return;
        }
        $context = [
            'scan_id' => $scanId, 'event' => $event, 'runtime' => $this->runtime(),
            'counts' => array_intersect_key($counts, array_flip(['fetched_pages', 'unique_emails', 'elapsed_seconds'])),
        ];
        if ($exception) {
            $context['error'] = $this->error($exception);
        }
        try {
            // File-backed so failures remain inspectable even when a DB transaction rolls back.
            Log::build(['driver' => 'daily', 'path' => $this->path(), 'days' => 14,
                'formatter' => JsonFormatter::class, 'locking' => true, 'permission' => 0600])
                ->info(self::EVENTS[$event], $context);
        } catch (Throwable) {
            // Diagnostics must never turn a successful scan into a failed one.
            error_log('E-mail scraper diagnostics could not be written. Check storage/logs permissions.');
        }
    }

    public function report(?int $scanId = null): array
    {
        $report = ['generated_at' => gmdate('c'), 'web_runtime' => $this->runtime(), 'table_counts' => [],
            'log_directory_writable' => is_writable(dirname($this->path())), 'log_retention_days' => 14];
        foreach (['email_scans', 'email_contacts', 'email_contact_sources', 'jobs', 'failed_jobs'] as $table) {
            try {
                $report['table_counts'][$table] = DB::table($table)->count();
            } catch (Throwable $exception) {
                $report['table_counts'][$table] = $this->error($exception);
            }
        }
        if ($scanId && ($scan = EmailScan::find($scanId))) {
            $report['scan'] = $scan->only(['id', 'name', 'url', 'status', 'failure_message', 'created_at', 'started_at', 'finished_at']);
        }
        $report['recent_activity'] = $this->recentActivity();

        return $report;
    }

    public function recentActivity(): array
    {
        $entries = [];
        // Bounded reads, including yesterday so a failure around midnight remains visible.
        foreach ([now()->subDay()->format('Y-m-d'), now()->format('Y-m-d')] as $day) {
            $path = pathinfo($this->path());
            $file = $path['dirname'].'/'.$path['filename'].'-'.$day.'.'.($path['extension'] ?? 'log');
            if (! is_readable($file) || ! ($stream = @fopen($file, 'rb'))) {
                continue;
            }
            try {
                $size = fstat($stream)['size'];
                $offset = max(0, $size - 131072);
                fseek($stream, $offset);
                if ($offset > 0) {
                    fgets($stream); // Discard a partial first line.
                }
                while (($line = fgets($stream)) !== false) {
                    $entry = json_decode($line, true);
                    if (is_array($entry) && isset(self::EVENTS[$entry['context']['event'] ?? ''])) {
                        $entries[] = ['at' => $entry['datetime'], 'message' => $entry['message']] + $entry['context'];
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        return array_slice($entries, -50);
    }

    private function path(): string
    {
        return config('scrapling.email_diagnostic_log', storage_path('logs/email-scraper.log'));
    }
}
