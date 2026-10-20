<?php

namespace Tests\Feature;

use App\Filament\Pages\TestScraper;
use App\Filament\Resources\ScrapeHistoryResource\Pages\ListScrapeHistory;
use App\Models\User;
use App\Services\ScrapeHistory;
use App\Services\Scraper;
use Database\Seeders\LocalAdminSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ScrapeHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->seed(LocalAdminSeeder::class);
    }

    private function admin(): User
    {
        return User::where('username', 'vladmar')->firstOrFail();
    }

    public function test_successful_attempt_records_actor_and_metadata_without_url_secrets(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $url = 'https://example.com/article?api_key=private-token#private-fragment';
        $this->mock(Scraper::class, function ($mock) use ($url) {
            $mock->shouldReceive('scrape')->once()->with($url)->andReturn([
                'url' => $url, 'status' => 200, 'title' => 'Example article', 'headings' => ['Heading'],
            ]);
        });
        Livewire::test(TestScraper::class)->fillForm(['url' => $url])->call('scrape')->assertSet('error', null);

        $activity = Activity::sole();
        $this->assertSame('succeeded', $activity->event);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('https://example.com/article', $activity->properties['url']);
        $this->assertSame(200, $activity->properties['http_status']);
        $this->assertSame(1, $activity->properties['heading_count']);
        $this->assertGreaterThanOrEqual(0, $activity->properties['duration_ms']);
        $this->assertStringNotContainsString('private-token', $activity->toJson());
        $this->assertStringNotContainsString('private-fragment', $activity->toJson());
    }

    public function test_failed_fetch_is_recorded_without_storing_raw_exception_details(): void
    {
        $this->actingAs($this->admin());
        $this->mock(Scraper::class, function ($mock) {
            $mock->shouldReceive('scrape')->once()->andThrow(new RuntimeException('HTTP failure: sensitive-token'));
        });
        Livewire::test(TestScraper::class)->fillForm(['url' => 'https://example.com/missing'])
            ->call('scrape')->assertSet('result', null)->assertSee('HTTP failure');

        $activity = Activity::sole();
        $this->assertSame('failed', $activity->event);
        $this->assertSame('https://example.com/missing', $activity->properties['url']);
        $this->assertStringNotContainsString('sensitive-token', $activity->toJson());
    }

    public function test_invalid_form_input_does_not_create_an_attempt(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(TestScraper::class)->fillForm(['url' => 'invalid'])->call('scrape')->assertHasFormErrors(['url']);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_history_supports_url_search_outcome_filters_and_read_only_details(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $history = app(ScrapeHistory::class);
        $history->record($admin, 'https://example.com/article', ['status' => 200, 'title' => 'Article', 'headings' => []], 123);
        $history->record($admin, 'https://example.com/missing', null, 456);
        [$success, $failure] = Activity::orderBy('id')->get()->all();
        Livewire::test(ListScrapeHistory::class)
            ->assertCanSeeTableRecords([$success, $failure])
            ->searchTable('article')
            ->assertCanSeeTableRecords([$success])
            ->assertCanNotSeeTableRecords([$failure])
            ->searchTable('')
            ->filterTable('event', 'failed')
            ->assertCanSeeTableRecords([$failure])
            ->assertCanNotSeeTableRecords([$success]);
        $this->get('/admin/scrape-history/'.$success->id)->assertOk()->assertSee('https://example.com/article');
        $this->assertFalse($admin->can('update', $success));
        $this->assertFalse($admin->can('delete', $success));
    }

    public function test_history_pages_require_an_administrator(): void
    {
        app(ScrapeHistory::class)->record($this->admin(), 'https://example.com', null, 1);
        $id = Activity::sole()->id;
        $this->get('/admin/scrape-history')->assertRedirect('/admin/login');
        $this->get('/admin/scrape-history/'.$id)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get('/admin/scrape-history')->assertForbidden();
        $this->get('/admin/scrape-history/'.$id)->assertForbidden();
    }

    public function test_history_storage_failure_preserves_the_successful_result_without_fetching_again(): void
    {
        $this->actingAs($this->admin());
        $this->mock(Scraper::class, function ($mock) {
            $mock->shouldReceive('scrape')->once()->andReturn([
                'url' => 'https://example.com', 'status' => 200, 'title' => 'Preserved result', 'headings' => [],
            ]);
        });
        $this->mock(ScrapeHistory::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('Storage unavailable'));
        });
        Livewire::test(TestScraper::class)->fillForm(['url' => 'https://example.com'])->call('scrape')
            ->assertSet('error', null)->assertSee('Preserved result')
            ->assertNotified('The attempt could not be saved to scrape history.');
    }
}
