<?php

namespace Tests\Feature;

use App\Exceptions\EmailFetchFailed;
use App\Exceptions\EmailScanFailed;
use App\Filament\Pages\EmailScraper;
use App\Jobs\ProcessEmailScan;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailScanDiagnostics;
use App\Services\EmailScraper as ScraperService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\RunsEmailScans;
use Tests\TestCase;

class EmailDashboardDiagnosticsTest extends TestCase
{
    use RefreshDatabase, RunsEmailScans;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_failed_fetch_has_a_persistent_dashboard_log_and_database_identity_after_reload(): void
    {
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andThrow(new EmailFetchFailed('request_timeout', details: [
            'exception_type' => 'Timeout', 'curl_code' => 28, 'message' => 'https://example.com/?token=private-token',
        ]));
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com/?token=private-token', 'mode' => 'website'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'failed')->assertSee('Scan activity')->assertSee('cURL 28');
        $scan = EmailScan::sole();
        $report = app(EmailScanDiagnostics::class)->report($scan->id);
        $this->assertSame(['queued', 'worker_started', 'fetching', 'failed'], array_column($report['recent_activity'], 'event'));
        $this->assertSame(':memory:', $report['web_runtime']['database']['database']);
        $this->assertSame(1, $report['table_counts']['email_scans']);
        $this->assertSame(0, $report['table_counts']['email_contacts']);
        $this->assertSame('request_timeout', $report['recent_activity'][3]['error']['reason']);
        $this->assertSame(28, $report['recent_activity'][3]['error']['curl_code']);
        $this->assertStringNotContainsString('private-token', json_encode($report));
        Livewire::test(EmailScraper::class)->assertSee('Recent scans')->assertSee('Scan failed.')->assertSee('#'.$scan->id)->assertDontSee('private-token');
        Livewire::withQueryParams(['scan' => $scan->id])->test(EmailScraper::class)->assertSet('scanStatus', 'failed')->assertSee('cURL 28');
    }

    public function test_queue_insertion_rollback_leaves_an_inspectable_file_log_when_tables_are_empty(): void
    {
        config(['queue.connections.scraping.table' => 'missing_jobs_table']);
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com/?token=private-token'])->call('scrape')
            ->assertSee('Could not save the scan and queue entry.')->assertSee('database_error')->assertDontSee('private-token');
        $this->assertDatabaseCount('email_scans', 0);
        $this->assertDatabaseCount('jobs', 0);
        $entry = app(EmailScanDiagnostics::class)->report()['recent_activity'][0];
        $this->assertSame('enqueue_failed', $entry['event']);
        $this->assertSame('database_error', $entry['error']['reason']);
        Livewire::test(EmailScraper::class)->assertSee('Could not save the scan and queue entry.')->assertSee('No saved scan records');
    }

    public function test_unreadable_worker_request_reports_key_mismatch_without_logging_the_request(): void
    {
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com/?token=private-token'])->call('scrape');
        DB::table('email_scans')->update(['request' => 'invalid-ciphertext']);
        $this->finishEmailScan($component)->assertSet('scanStatus', 'failed')->assertSee('same APP_KEY')->assertDontSee('invalid-ciphertext');
        $activity = app(EmailScanDiagnostics::class)->recentActivity();
        $this->assertSame('request_decryption', end($activity)['error']['reason']);
        $this->assertStringNotContainsString('private-token', json_encode($activity));
    }

    public function test_worker_database_failure_is_logged_even_when_scan_claim_is_impossible(): void
    {
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => base_path('work/nonexistent-diagnostic-db.sqlite')], 'database.default' => 'broken']);
        try {
            $this->runEmailScanJob(123);
            $activity = app(EmailScanDiagnostics::class)->recentActivity();
            $this->assertNotEmpty($activity);
            $this->assertSame('claim_failed', end($activity)['event']);
            $this->assertSame(123, end($activity)['scan_id']);
            $this->assertSame('database_error', end($activity)['error']['reason']);
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_a_missing_scan_is_logged_instead_of_silently_reporting_success(): void
    {
        try {
            app()->call([new ProcessEmailScan(123), 'handle']);
            $this->fail('A missing scan must fail the job.');
        } catch (EmailScanFailed $exception) {
            $this->assertStringContainsString('No saved scan exists', $exception->getMessage());
        }
        $this->assertContains('scan_missing', array_column(app(EmailScanDiagnostics::class)->recentActivity(), 'event'));
    }

    public function test_diagnostics_cannot_be_refreshed_or_read_after_admin_access_is_revoked(): void
    {
        $component = Livewire::test(EmailScraper::class);
        auth()->user()->forceFill(['is_admin' => false])->save();
        $component->call('refreshDiagnostics')->assertForbidden();
    }

    public function test_diagnostic_reader_is_bounded_and_ignores_broken_lines(): void
    {
        $path = pathinfo(config('scrapling.email_diagnostic_log'));
        $file = $path['dirname'].'/'.$path['filename'].'-'.now()->format('Y-m-d').'.log';
        file_put_contents($file, str_repeat('invalid line'."\n", 15000));
        $diagnostics = app(EmailScanDiagnostics::class);
        $diagnostics->record('queued', 789);
        $this->assertSame([789], array_column($diagnostics->recentActivity(), 'scan_id'));
    }
}
