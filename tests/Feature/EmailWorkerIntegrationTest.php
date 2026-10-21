<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmailWorkerIntegrationTest extends TestCase
{
    public function test_real_separate_worker_fetches_and_archives_a_database_queued_scan(): void
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
            $url = trim($server->getOutput()).'/emails';
            $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'QUEUE_CONNECTION' => 'sync'];
            $submit = new Process([PHP_BINARY, '-d', 'max_execution_time=30', base_path('tests/Fixtures/email_queue.php'), 'submit', $url], base_path(), $environment);
            $submit->mustRun();
            $this->assertSame(['status' => 'queued', 'scans' => 1, 'contacts' => 0, 'sources' => 0, 'jobs' => 1], json_decode($submit->getOutput(), true, 512, JSON_THROW_ON_ERROR));

            $worker = new Process([PHP_BINARY, '-d', 'max_execution_time=0', base_path('tests/Fixtures/email_queue.php'), 'work'], base_path(), $environment);
            $worker->mustRun();
            $this->assertSame(['status' => 'completed', 'scans' => 1, 'contacts' => 3, 'sources' => 4, 'jobs' => 0], json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $server->stop();
            foreach ([$database, $database.'-wal', $database.'-shm'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
