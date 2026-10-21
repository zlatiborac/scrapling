<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailScraper;
use App\Models\User;
use App\Services\EmailArchive;
use App\Services\EmailScraper as EmailScraperService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmailScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function signIn(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        return $admin;
    }

    private function scanResult(array $overrides = []): array
    {
        return array_replace([
            'url' => 'https://example.com/', 'mode' => 'page',
            'emails' => [['email' => 'info@example.com', 'sources' => ['https://example.com/contact']]],
            'pages' => [['url' => 'https://example.com/', 'final_url' => 'https://example.com/', 'depth' => 0,
                'discovered_from' => null, 'status' => 'fetched', 'http_status' => 200, 'emails' => ['info@example.com'], 'error' => null]],
            'fetched' => 1, 'attempted' => 1, 'pending' => 0, 'limits' => [], 'complete' => true,
        ], $overrides);
    }

    public function test_page_requires_an_administrator_and_appears_in_navigation(): void
    {
        $this->get('/admin/email-scraper')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->get('/admin/email-scraper')->assertForbidden();
        $this->signIn();
        $this->get('/admin/email-scraper')->assertOk()->assertSee('E-mail scraper')->assertSee('Find e-mails');
        $this->get('/admin/test-scraper')->assertOk()->assertSee('E-mail scraper');
    }

    public function test_single_page_defaults_to_https_and_displays_emails_and_sources(): void
    {
        $this->signIn();
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()
            ->with('https://example.com', false, 20, 3)->andReturn($this->scanResult());

        Livewire::test(EmailScraper::class)
            ->assertFormSet(['scheme' => 'https', 'mode' => 'page'])
            ->fillForm(['url' => '  example.com  '])->call('scrape')
            ->assertHasNoFormErrors()->assertSet('error', null)
            ->assertSee('info@example.com')->assertSee('https://example.com/contact')->assertSee('Crawl log');
        $this->assertDatabaseCount('activity_log', 0);
        $this->assertDatabaseHas('email_contacts', ['email' => 'info@example.com']);
        $this->assertDatabaseCount('email_scans', 1);
    }

    public function test_full_url_overrides_protocol_and_website_limits_are_passed_to_service(): void
    {
        $this->signIn();
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()
            ->with('http://example.com/section', true, 12, 2)
            ->andReturn($this->scanResult(['mode' => 'website', 'limits' => ['pages'], 'pending' => 4]));

        Livewire::test(EmailScraper::class)
            ->fillForm(['url' => 'http://example.com/section', 'scheme' => 'https', 'mode' => 'website', 'max_pages' => 12, 'max_depth' => 2])
            ->call('scrape')->assertHasNoFormErrors()->assertSee('page count')->assertSee('4 queued page(s) not visited');
        $this->assertDatabaseCount('email_contacts', 1);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_never_starts_a_scan(array $input, string $field): void
    {
        $this->signIn();
        $this->mock(EmailScraperService::class)->shouldNotReceive('scrape');
        Livewire::test(EmailScraper::class)
            ->fillForm(array_replace(['url' => 'example.com', 'mode' => 'website'], $input))
            ->call('scrape')->assertHasFormErrors([$field]);
    }

    public static function invalidInputs(): array
    {
        return [
            'empty URL' => [['url' => ''], 'url'],
            'invalid URL' => [['url' => 'not-a-url'], 'url'],
            'unsupported scheme' => [['url' => 'file:///tmp/test'], 'url'],
            'invalid protocol' => [['scheme' => 'ftp'], 'scheme'],
            'invalid mode' => [['mode' => 'all'], 'mode'],
            'zero pages' => [['max_pages' => 0], 'max_pages'],
            'too many pages' => [['max_pages' => 51], 'max_pages'],
            'fractional pages' => [['max_pages' => 2.5], 'max_pages'],
            'negative depth' => [['max_depth' => -1], 'max_depth'],
            'too much depth' => [['max_depth' => 6], 'max_depth'],
        ];
    }

    public function test_failure_clears_previous_results_and_displays_an_escaped_error(): void
    {
        $this->signIn();
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()
            ->andThrow(new RuntimeException('<script>alert("failure")</script>'));
        Livewire::test(EmailScraper::class)->set('result', $this->scanResult())
            ->fillForm(['url' => 'example.com'])->call('scrape')
            ->assertSet('result', null)->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert("failure")</script>', escape: false);
    }

    public function test_empty_results_and_untrusted_page_details_are_rendered_safely(): void
    {
        $this->signIn();
        $result = $this->scanResult(['emails' => []]);
        $result['pages'][0]['error'] = '<img src=x onerror=alert(1)>';
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()->andReturn($result);
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape')
            ->assertSee('No e-mail addresses found')->assertSee('&lt;img', escape: false)
            ->assertDontSee('<img src=x onerror=alert(1)>', escape: false);
    }

    public function test_rate_limit_blocks_fetching_and_clears_old_results(): void
    {
        $admin = $this->signIn();
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('email-scrape:'.$admin->id, 60);
        }
        $this->mock(EmailScraperService::class)->shouldNotReceive('scrape');
        Livewire::test(EmailScraper::class)->set('result', $this->scanResult())
            ->fillForm(['url' => 'example.com'])->call('scrape')
            ->assertSet('result', null)->assertSee('Please wait a minute');
    }

    public function test_an_administrator_revoked_between_requests_cannot_start_a_scan(): void
    {
        $admin = $this->signIn();
        $this->mock(EmailScraperService::class)->shouldNotReceive('scrape');
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com']);
        $admin->forceFill(['is_admin' => false])->save();
        $component->call('scrape')->assertForbidden();
    }

    public function test_real_php_python_scrapling_single_page_and_website_fetches(): void
    {
        $server = new Process([config('scrapling.python'), base_path('tests/Fixtures/server.py')]);
        $server->start();
        try {
            $this->assertTrue($server->waitUntil(fn ($type, $output) => str_contains($output, 'http://')));
            $url = trim($server->getOutput()).'/emails';
            $scraper = app(EmailScraperService::class);
            $single = $scraper->scrape($url, publicOnly: false);
            $this->assertSame(['info@example.com'], array_column($single['emails'], 'email'));
            $this->assertCount(1, $single['pages']);
            $website = $scraper->scrape($url, website: true, publicOnly: false);
            $this->assertSame(['info@example.com', 'sales+web@example.com', 'team@example.com'], array_column($website['emails'], 'email'));
            $this->assertSame([$url, $url.'/contact'], $website['emails'][0]['sources']);
            $this->assertCount(4, $website['pages']);
            $this->assertSame('failed', $website['pages'][2]['status']);
            $this->assertSame($url.'/contact', $website['pages'][3]['discovered_from']);
            $scan = app(EmailArchive::class)->store($website, $url, 'Fixture website', $this->signIn());
            $this->assertDatabaseCount('email_contacts', 3);
            $this->assertDatabaseCount('email_contact_sources', 4);
            $this->assertSame(3, $scan->contacts()->count());
        } finally {
            $server->stop();
        }
    }

    public function test_real_service_blocks_private_urls_and_credentialed_urls(): void
    {
        $scraper = app(EmailScraperService::class);
        try {
            $scraper->scrape('http://127.0.0.1');
            $this->fail('Private addresses must be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local and private addresses are blocked', $exception->getMessage());
        }
        $this->expectExceptionMessage('without embedded credentials');
        $scraper->scrape('https://user:secret@example.com');
    }
}
