<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Pages\TestScraper;
use App\Models\User;
use App\Services\Scraper;
use Database\Seeders\LocalAdminSeeder;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    private function admin(): User
    {
        $this->seed(LocalAdminSeeder::class);

        return User::where('username', 'vladmar')->firstOrFail();
    }

    public function test_guests_are_redirected_to_username_login(): void
    {
        $this->get('/admin/test-scraper')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Username');
    }

    public function test_the_seeded_admin_can_log_in_with_username(): void
    {
        $admin = $this->admin();
        $this->assertNotSame('tiborzla', $admin->password);
        Livewire::test(Login::class)
            ->fillForm(['email' => 'vladmar', 'password' => 'tiborzla'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->admin();
        Livewire::test(Login::class)
            ->fillForm(['email' => 'vladmar', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_non_admin_users_cannot_open_the_tester(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/test-scraper')->assertForbidden();
    }

    public function test_admin_can_open_the_tester_and_see_scraping_results(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/test-scraper')->assertOk()->assertSee('Page URL');

        $this->mock(Scraper::class, function ($mock) {
            $mock->shouldReceive('scrape')->once()->with('https://example.com')->andReturn([
                'url' => 'https://example.com/', 'status' => 200,
                'title' => 'Example Domain', 'headings' => ['Example heading'],
            ]);
        });
        Livewire::test(TestScraper::class)
            ->fillForm(['url' => 'https://example.com'])
            ->call('scrape')
            ->assertHasNoFormErrors()
            ->assertSet('error', null)
            ->assertSee('Example Domain')
            ->assertSee('Example heading');
    }

    public function test_invalid_urls_are_rejected_in_the_form(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(TestScraper::class)
            ->fillForm(['url' => 'not-a-url'])
            ->call('scrape')->assertHasFormErrors(['url']);
    }

    public function test_private_urls_show_an_error_and_clear_old_results(): void
    {
        $this->actingAs($this->admin());
        Livewire::test(TestScraper::class)
            ->set('result', ['title' => 'Old result', 'url' => 'https://example.com', 'status' => 200, 'headings' => []])
            ->fillForm(['url' => 'http://127.0.0.1'])
            ->call('scrape')
            ->assertSet('result', null)
            ->assertSee('local and private addresses are blocked');
    }

    public function test_seeding_again_preserves_a_changed_password(): void
    {
        $admin = $this->admin();
        $admin->password = 'changed-password';
        $admin->save();
        $this->seed(LocalAdminSeeder::class);
        $this->assertSame(1, User::where('username', 'vladmar')->count());
        $this->assertTrue(Hash::check('changed-password', $admin->fresh()->password));
    }

    public function test_production_seeding_does_not_create_default_credentials(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('db:seed', ['--class' => LocalAdminSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_can_change_password_from_the_profile(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(EditProfile::class)
            ->fillForm([
                'password' => 'a-new-local-password',
                'passwordConfirmation' => 'a-new-local-password',
                'currentPassword' => 'tiborzla',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('a-new-local-password', $admin->fresh()->password));
    }
}
