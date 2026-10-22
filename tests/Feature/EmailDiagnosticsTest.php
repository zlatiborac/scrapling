<?php

namespace Tests\Feature;

use App\Exceptions\EmailFetchFailed;
use App\Services\EmailScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('failureReasons')]
    public function test_diagnostics_identify_failures_without_leaking_url_tokens(string $reason): void
    {
        $url = 'https://example.com/?token=private-token#private-fragment';
        $this->mock(EmailScraper::class)->shouldReceive('scrape')->once()->with($url, true)->andThrow(new EmailFetchFailed($reason));
        $this->assertSame(1, Artisan::call('scrape:diagnose', ['url' => $url, '--website' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $report['status']);
        $this->assertSame($reason, $report['reason']);
        $this->assertSame('https://example.com/', $report['target']);
        $this->assertSame('website', $report['mode']);
        $this->assertSame(30, $report['crawl_budget_seconds']);
        $this->assertSame(45, $report['process_timeout_seconds']);
        $this->assertArrayHasKey('scrapling', $report['python_packages']);
        $this->assertArrayHasKey('elapsed_seconds', $report);
        $this->assertStringNotContainsString('private-token', Artisan::output());
        $this->assertStringNotContainsString('private-fragment', Artisan::output());
        $this->assertDatabaseCount('email_scans', 0);
        $this->assertDatabaseCount('email_contacts', 0);
    }

    public static function failureReasons(): array
    {
        return array_map(static fn ($reason): array => [$reason], ['dns_error', 'connection_error', 'tls_error', 'request_timeout', 'process_timeout']);
    }

    public function test_diagnostics_success_is_read_only_and_reports_partial_results(): void
    {
        $this->mock(EmailScraper::class)->shouldReceive('scrape')->once()->andReturn([
            'url' => 'https://example.com/', 'mode' => 'website', 'emails' => [], 'pages' => [],
            'fetched' => 2, 'pending' => 3, 'limits' => ['time'], 'complete' => false,
        ]);
        $this->assertSame(0, Artisan::call('scrape:diagnose', ['url' => 'https://example.com', '--website' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('succeeded', $report['status']);
        $this->assertSame(2, $report['fetched_pages']);
        $this->assertSame(['time'], $report['limits']);
        $this->assertDatabaseCount('email_scans', 0);
        $this->assertDatabaseCount('email_contacts', 0);
    }

    public function test_diagnostics_report_a_missing_python_executable(): void
    {
        config(['scrapling.python' => base_path('work/missing-python')]);
        $this->assertSame(1, Artisan::call('scrape:diagnose', ['url' => 'https://example.com', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('python_unavailable', $report['reason']);
    }

    public function test_diagnostics_cannot_report_success_after_result_processing_fails(): void
    {
        $this->mock(EmailScraper::class)->shouldReceive('scrape')->once()->andReturn([
            'url' => 'https://example.com/', 'mode' => 'website', 'emails' => [], 'pages' => [null],
            'fetched' => 1, 'limits' => [],
        ]);
        $this->assertSame(1, Artisan::call('scrape:diagnose', ['url' => 'https://example.com', '--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('failed', $report['status']);
        $this->assertSame('runtime_error', $report['reason']);
    }
}
