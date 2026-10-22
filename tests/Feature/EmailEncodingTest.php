<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailScraper;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailArchive;
use App\Services\EmailScanDiagnostics;
use App\Services\EmailScraper as ScraperService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\JsonEncodingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\RunsEmailScans;
use Tests\TestCase;

class EmailEncodingTest extends TestCase
{
    use RefreshDatabase, RunsEmailScans;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function crawlResult(string $source): array
    {
        return [
            'url' => 'https://example.com/', 'mode' => 'website',
            'emails' => array_map(fn ($number): array => ['email' => 'contact'.$number.'@example.com', 'sources' => [$source]], range(1, 38)),
            'pages' => [['url' => $source, 'final_url' => $source, 'discovered_from' => 'https://example.com/',
                'status' => 'fetched', 'http_status' => 200, 'depth' => 1, 'emails' => ['contact1@example.com'], 'error' => null]],
            'fetched' => 40, 'attempted' => 40, 'pending' => 1, 'limits' => ['time'], 'complete' => false,
        ];
    }

    public function test_malformed_url_bytes_cannot_discard_a_successful_crawl_and_its_contacts(): void
    {
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andReturn($this->crawlResult("https://example.com/\xD1broken?token=private-token#fragment"));
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com', 'mode' => 'website'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'completed')->assertSee('38 new contact(s)')->assertDontSee('private-token');
        $scan = EmailScan::sole();
        $this->assertSame('https://example.com/%D1broken', $scan->result['pages'][0]['url']);
        $this->assertDatabaseCount('email_contacts', 38);
        $this->assertDatabaseCount('email_contact_sources', 38);
        $this->assertDatabaseCount('email_contact_email_scan', 38);
        $this->assertNull($scan->request);
    }

    public function test_unicode_urls_are_stored_losslessly_as_ascii_uris_without_double_encoding(): void
    {
        $path = 'čćžšđ/Туризам';
        $expected = 'https://example.com/'.str_replace('%2F', '/', rawurlencode($path));
        $this->assertSame($expected, EmailArchive::safeUrl('https://user:password@example.com/'.$path.'?token=private-token#fragment'));
        $this->assertSame($expected, EmailArchive::safeUrl($expected));
    }

    public function test_invalid_text_in_crawl_details_does_not_prevent_archiving_valid_addresses(): void
    {
        $result = $this->crawlResult('https://example.com/contact');
        $result['pages'][0]['emails'][] = "bad\xFF@example.com";
        $result['emails'][] = ['email' => "bad\xFF@example.com", 'sources' => ['https://example.com/contact']];
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andReturn($result);
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com', 'mode' => 'website'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'completed')->assertSee('38 new contact(s)');
        $scan = EmailScan::sole();
        $this->assertSame(1, $scan->skipped_count);
        $this->assertSame("bad\u{FFFD}@example.com", $scan->result['pages'][0]['emails'][1]);
        $this->assertDatabaseCount('email_contacts', 38);
    }

    public function test_request_json_remains_strict_even_when_crawl_text_is_tolerated(): void
    {
        $this->expectException(JsonEncodingException::class);
        (new EmailScan)->setAttribute('request', ['url' => "https://example.com/\xFF"]);
    }

    public function test_crawl_log_encoding_failure_is_reported_as_a_saving_error_with_safe_details(): void
    {
        $this->mock(ScraperService::class)->shouldReceive('scrape')->once()->andReturn($this->crawlResult('https://example.com/contact'));
        EmailScan::updating(function (EmailScan $scan): void {
            if ($scan->isDirty('result')) {
                throw JsonEncodingException::forAttribute($scan, 'result', 'Malformed UTF-8 characters, possibly incorrectly encoded');
            }
        });
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('scanStatus', 'failed')->assertSee('results could not be saved')->assertDontSee('Could not fetch this page');
        $scan = EmailScan::sole();
        $this->assertNull($scan->result);
        $this->assertNull($scan->request);
        $activity = app(EmailScanDiagnostics::class)->recentActivity();
        $this->assertSame('json_encoding_error', end($activity)['error']['reason']);
        $this->assertSame('invalid_utf8', end($activity)['error']['encoding_error']);
    }
}
