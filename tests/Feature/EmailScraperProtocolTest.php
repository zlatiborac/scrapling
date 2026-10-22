<?php

namespace Tests\Feature;

use App\Exceptions\EmailFetchFailed;
use App\Services\EmailScraper;
use Tests\TestCase;

class EmailScraperProtocolTest extends TestCase
{
    public function test_structured_errors_survive_stderr_logging_without_exposing_tokens(): void
    {
        $this->withInterpreter('log: https://example.com/?token=private-token'."\n".'{"error":{"code":"request_timeout"}}', true, function (): void {
            try {
                app(EmailScraper::class)->scrape('https://example.com');
                $this->fail('The fetch should fail.');
            } catch (EmailFetchFailed $exception) {
                $this->assertSame('request_timeout', $exception->reason);
                $this->assertStringContainsString('website request timed out', $exception->getMessage());
                $this->assertStringNotContainsString('private-token', $exception->getMessage());
            }
        });
    }

    public function test_invalid_python_output_is_distinct_from_a_network_failure(): void
    {
        foreach (['not-json', '{}'] as $output) {
            $this->withInterpreter($output, false, function (): void {
                try {
                    app(EmailScraper::class)->scrape('https://example.com');
                    $this->fail('Invalid output should fail.');
                } catch (EmailFetchFailed $exception) {
                    $this->assertSame('invalid_response', $exception->reason);
                }
            });
        }
    }

    public function test_python_process_timeout_has_its_own_reason(): void
    {
        config(['scrapling.email_process_timeout' => 0.05]);
        $this->withInterpreter('unused', false, function (): void {
            try {
                app(EmailScraper::class)->scrape('https://example.com');
                $this->fail('The process should time out.');
            } catch (EmailFetchFailed $exception) {
                $this->assertSame('process_timeout', $exception->reason);
            }
        }, delay: true);
    }

    private function withInterpreter(string $output, bool $error, callable $test, bool $delay = false): void
    {
        $path = base_path('work/interpreter-'.bin2hex(random_bytes(6)).'.sh');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, "#!/bin/sh\n".($delay ? "sleep 1\n" : '')."printf '%s\\n' ".escapeshellarg($output).($error ? ' >&2' : '')."\nexit ".($error ? '1' : '0')."\n");
        chmod($path, 0700);
        $original = config('scrapling.python');
        config(['scrapling.python' => $path]);
        try {
            $test();
        } finally {
            config(['scrapling.python' => $original]);
            unlink($path);
        }
    }
}
