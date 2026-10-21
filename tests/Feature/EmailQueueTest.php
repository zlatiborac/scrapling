<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailScraper;
use App\Jobs\ProcessEmailScan;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailScraper as ScraperService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RunsEmailScans;
use Tests\TestCase;

class EmailQueueTest extends TestCase
{
    use RefreshDatabase, RunsEmailScans;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    private function scanResult(): array
    {
        return [
            'url' => 'https://example.com/?token=secret#fragment', 'mode' => 'website',
            'emails' => [['email' => 'info@example.com', 'sources' => ['https://example.com/contact?token=secret#part']]],
            'pages' => [['url' => 'https://example.com/?token=secret', 'final_url' => 'https://example.com/?token=secret',
                'discovered_from' => null, 'status' => 'fetched', 'http_status' => 200, 'depth' => 0, 'emails' => ['info@example.com'], 'error' => null]],
            'fetched' => 1, 'attempted' => 1, 'pending' => 0, 'limits' => [], 'complete' => true,
        ];
    }

    public function test_submission_never_fetches_even_when_default_queue_is_sync(): void
    {
        config(['queue.default' => 'sync']);
        $this->mock(ScraperService::class)->shouldNotReceive('scrape');
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com/?token=secret', 'mode' => 'website'])
            ->call('scrape')->assertSet('scanStatus', 'queued')->assertSet('result', null)->assertSee('Scan queued');
        $scan = EmailScan::sole();
        $this->assertSame('https://example.com/?token=secret', $scan->request['url']);
        $this->assertStringNotContainsString('secret', $scan->getRawOriginal('request'));
        $this->assertArrayNotHasKey('request', $scan->toArray());
        $this->assertSame('https://example.com/', $scan->url);
        $job = DB::table('jobs')->sole();
        $this->assertSame('email-scans', $job->queue);
        $this->assertStringNotContainsString('secret', $job->payload);
        $this->assertStringNotContainsString('example.com', $job->payload);
        $this->assertDatabaseCount('email_contacts', 0);
        $this->assertLessThan(config('queue.connections.scraping.retry_after'), (new ProcessEmailScan($scan->id))->timeout);
    }

    public function test_repeated_submit_and_page_refresh_reuse_the_active_scan(): void
    {
        $this->mock(ScraperService::class)->shouldNotReceive('scrape');
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $id = $component->get('scanId');
        $component->call('scrape')->assertSet('scanId', $id)->assertSet('scanStatus', 'queued');
        Livewire::test(EmailScraper::class)->assertSet('scanId', $id)->assertSee('Scan queued');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('email_scans', 1);
    }

    public function test_worker_runs_once_keeps_renamed_scan_and_restores_redacted_results(): void
    {
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andReturn($this->scanResult());
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com', 'mode' => 'website'])->call('scrape');
        $scan = EmailScan::sole();
        $scan->update(['name' => 'Renamed while waiting']);
        $this->finishEmailScan($component)->assertSet('scanStatus', 'completed')->assertSee('info@example.com')->assertSee('Renamed while waiting');
        app()->call([new ProcessEmailScan($scan->id), 'handle']);
        $scan->refresh();
        $this->assertNull($scan->request);
        $this->assertNotNull($scan->started_at);
        $this->assertNotNull($scan->finished_at);
        $this->assertStringNotContainsString('secret', json_encode($scan->result));
        $this->assertSame('https://example.com/contact', $scan->result['emails'][0]['sources'][0]);
        $this->assertDatabaseCount('email_scans', 1);
        $this->assertDatabaseCount('email_contacts', 1);
        $this->assertDatabaseCount('email_contact_email_scan', 1);
        Livewire::withQueryParams(['scan' => $scan->id])->test(EmailScraper::class)
            ->assertSet('scanStatus', 'completed')->assertSee('info@example.com')->assertSee('Crawl log')->assertDontSee('secret');
    }

    public function test_fetch_failure_is_terminal_clears_request_and_hides_raw_errors(): void
    {
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andThrow(new RuntimeException('https://example.com/?private=secret'));
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'failed')->assertSee('Could not fetch this page')->assertDontSee('secret');
        $scan = EmailScan::sole();
        $this->assertNull($scan->request);
        $this->assertStringNotContainsString('secret', $scan->failure_message);
        app()->call([new ProcessEmailScan($scan->id), 'handle']);
        $this->assertDatabaseCount('email_contacts', 0);
    }

    public function test_failed_enqueue_rolls_back_the_scan_and_shows_a_readable_error(): void
    {
        config(['queue.connections.scraping.table' => 'missing_jobs_table']);
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape')
            ->assertSee('The scan could not be queued')->assertSet('result', null);
        $this->assertDatabaseCount('email_scans', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_queued_scan_can_be_cancelled_without_fetching(): void
    {
        $this->mock(ScraperService::class)->shouldNotReceive('scrape');
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $component->call('cancelQueuedScan')->assertSet('scanStatus', 'failed')->assertSee('The queued scan was cancelled');
        $scan = EmailScan::sole();
        $this->assertNull($scan->request);
        app()->call([new ProcessEmailScan($scan->id), 'handle']);
        $this->assertDatabaseCount('email_contacts', 0);
    }

    public function test_stale_running_scan_is_failed_without_repeating_the_crawl(): void
    {
        $this->mock(ScraperService::class)->shouldNotReceive('scrape');
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $scan = EmailScan::sole();
        $scan->update(['status' => 'running', 'started_at' => now()->subSeconds(121)]);
        $component->call('refreshScan')->assertSet('scanStatus', 'failed')->assertSee('interrupted or exceeded its time limit');
        $this->assertNull($scan->fresh()->request);
        app()->call([new ProcessEmailScan($scan->id), 'handle']);
    }

    public function test_worker_failure_callback_marks_run_failed_and_is_idempotent(): void
    {
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $scan = EmailScan::sole();
        $scan->update(['status' => 'running', 'started_at' => now()]);
        $job = new ProcessEmailScan($scan->id);
        $job->failed(new RuntimeException('worker timed out: private details'));
        $job->failed(new RuntimeException('another failure'));
        $scan->refresh();
        $this->assertSame('failed', $scan->status);
        $this->assertNull($scan->request);
        $this->assertStringNotContainsString('private details', $scan->failure_message);
        $this->assertNotNull($scan->finished_at);
    }

    public function test_revoked_administrator_cannot_run_or_poll_a_queued_scan(): void
    {
        $this->mock(ScraperService::class)->shouldNotReceive('scrape');
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $this->admin->forceFill(['is_admin' => false])->save();
        app()->call([new ProcessEmailScan(EmailScan::sole()->id), 'handle']);
        $this->assertSame('failed', EmailScan::sole()->status);
        $this->assertNull(EmailScan::sole()->request);
        $component->call('refreshScan')->assertForbidden();
    }

    public function test_livewire_scan_identifier_cannot_be_changed_in_a_request(): void
    {
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('scanId', 123456);
    }

    public function test_worker_preserves_public_url_checks(): void
    {
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'http://127.0.0.1'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'failed')->assertSee('local and private addresses are blocked');
        $this->assertDatabaseCount('email_contacts', 0);
    }
}
