<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmailWorkerIntegrationTest extends TestCase
{
    public function test_real_separate_worker_fetches_and_archives_a_database_queued_scan(): void
    {
        $report = $this->runScenario('/emails', 'work');
        $this->assertSame(['status' => 'completed', 'scans' => 1, 'contacts' => 3, 'sources' => 4, 'jobs' => 0], $report['worker']);
    }

    public function test_real_request_timeout_reports_fail_without_done_and_stores_a_safe_reason(): void
    {
        $report = $this->runScenario('/emails/timeout', 'work-timeout')['worker'];
        $this->assertSame('failed', $report['status']);
        $this->assertSame(0, $report['contacts']);
        $this->assertSame(1, $report['failed_jobs']);
        $this->assertSame(['failed'], $report['events']);
        $this->assertStringContainsString('FAIL', $report['worker_output']);
        $this->assertStringNotContainsString('DONE', $report['worker_output']);
        $this->assertStringContainsString('website request timed out', $report['failure_message']);
        $this->assertSame(1, $report['diagnostics']['table_counts']['email_scans']);
        $this->assertSame(0, $report['diagnostics']['table_counts']['email_contacts']);
        $activity = $report['diagnostics']['recent_activity'];
        $this->assertSame(['queued', 'worker_started', 'fetching', 'failed'], array_column($activity, 'event'));
        $this->assertSame('request_timeout', end($activity)['error']['reason']);
        $this->assertSame(28, end($activity)['error']['curl_code']);
        $this->assertSame('0', end($activity)['runtime']['php_max_execution_time']);
    }

    public function test_real_crawl_budget_exhaustion_preserves_partial_emails_as_success(): void
    {
        $report = $this->runScenario('/emails/budget', 'work-budget')['worker'];
        $this->assertSame('completed', $report['status']);
        $this->assertSame(1, $report['contacts']);
        $this->assertSame(0, $report['failed_jobs']);
        $this->assertContains('time', $report['result']['limits']);
        $this->assertSame('early@example.com', $report['result']['emails'][0]['email']);
        $this->assertStringContainsString('DONE', $report['worker_output']);
    }

    public function test_real_worker_can_finish_after_the_30_second_web_request_limit(): void
    {
        $report = $this->runScenario('/emails', 'work-over-30');
        $this->assertGreaterThanOrEqual(30, $report['worker_seconds']);
        $this->assertSame('completed', $report['worker']['status']);
        $this->assertSame(3, $report['worker']['contacts']);
        $this->assertSame(0, $report['worker']['failed_jobs']);
        $this->assertStringContainsString('DONE', $report['worker']['worker_output']);
    }

    private function runScenario(string $path, string $mode): array
    {
        $database = base_path('work/email-worker-'.bin2hex(random_bytes(6)).'.sqlite');
        if (! is_dir(dirname($database))) {
            mkdir(dirname($database), 0755, true);
        }
        touch($database);
        $server = new Process([config('scrapling.python'), base_path('tests/Fixtures/server.py')]);
        $server->start();
        try {
            $this->assertTrue($server->waitUntil(fn ($type, $output) => str_contains($output, 'http://')));
            $url = trim($server->getOutput()).$path;
            $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'QUEUE_CONNECTION' => 'sync'];
            $submit = new Process([PHP_BINARY, '-d', 'max_execution_time=30', base_path('tests/Fixtures/email_queue.php'), 'submit', $url], base_path(), $environment);
            $submit->mustRun();
            $this->assertSame(['status' => 'queued', 'scans' => 1, 'contacts' => 0, 'sources' => 0, 'jobs' => 1], json_decode($submit->getOutput(), true, 512, JSON_THROW_ON_ERROR));

            $worker = new Process([PHP_BINARY, '-d', 'max_execution_time=0', base_path('tests/Fixtures/email_queue.php'), $mode], base_path(), $environment);
            $started = hrtime(true);
            $worker->mustRun();

            return ['worker' => json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR), 'worker_seconds' => (hrtime(true) - $started) / 1_000_000_000];
        } finally {
            $server->stop();
            foreach ([$database, $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach (glob($database.'-*.log') ?: [] as $file) {
                unlink($file);
            }
        }
    }
}
