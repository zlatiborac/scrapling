<?php

namespace Tests\Feature;

use App\Filament\Pages\EmailScraper;
use App\Filament\Resources\EmailContactResource\Pages\ManageEmailContacts;
use App\Filament\Resources\EmailScanResource;
use App\Filament\Resources\EmailScanResource\Pages\ManageEmailScans;
use App\Models\EmailContact;
use App\Models\EmailContactSource;
use App\Models\EmailScan;
use App\Models\User;
use App\Services\EmailArchive;
use App\Services\EmailScraper as EmailScraperService;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RunsEmailScans;
use Tests\TestCase;

class EmailArchiveTest extends TestCase
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

    private function archive(array $emails, ?string $name = null, string $url = 'https://example.com/'): EmailScan
    {
        return app(EmailArchive::class)->store(['mode' => 'website', 'emails' => $emails], $url, $name, $this->admin);
    }

    private function entry(string $email = 'info@example.com', array $sources = ['https://example.com/contact']): array
    {
        return ['email' => $email, 'sources' => $sources];
    }

    private function scanResult(): array
    {
        return [
            'url' => 'https://example.com/', 'mode' => 'website', 'emails' => [$this->entry()],
            'pages' => [], 'fetched' => 1, 'pending' => 0, 'limits' => ['pages'], 'complete' => false,
        ];
    }

    public function test_dashboard_saves_named_partial_results_automatically(): void
    {
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()->andReturn($this->scanResult());
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com', 'name' => 'Local tourism'])
            ->call('scrape')->assertHasNoFormErrors();
        $this->finishEmailScan($component)->assertSet('saveError', null)
            ->assertSee('Saved to database')->assertSee('Local tourism')->assertSee('1 new contact(s)');

        $this->assertDatabaseHas('email_contacts', ['email' => 'info@example.com', 'name' => 'example.com']);
        $scan = EmailScan::sole();
        $this->assertSame('Local tourism', $scan->name);
        $this->assertSame($this->admin->id, $scan->user_id);
        $this->assertSame(1, $scan->contacts()->count());
    }

    public function test_duplicate_entries_merge_sources_and_case_and_whitespace(): void
    {
        $scan = $this->archive([
            $this->entry(' INFO@Example.COM ', ['https://example.com/a', 'https://example.com/a']),
            $this->entry('info@example.com', ['https://example.com/b']),
        ]);
        $this->assertDatabaseCount('email_contacts', 1);
        $this->assertDatabaseCount('email_contact_sources', 2);
        $this->assertDatabaseCount('email_contact_email_scan', 1);
        $this->assertSame('info@example.com', EmailContact::sole()->email);
        $this->assertSame(1, $scan->new_count);
        $this->assertSame(0, $scan->existing_count);
    }

    public function test_repeated_scans_reuse_contacts_keep_manual_names_and_update_last_found(): void
    {
        $this->freezeTime();
        $first = $this->archive([$this->entry()], 'First pass');
        $contact = EmailContact::sole();
        $firstSeen = $contact->created_at;
        $contact->update(['name' => 'Tourism office']);
        $this->travel(2)->days();
        $second = $this->archive([
            $this->entry('INFO@example.com', ['https://example.com/contact', 'https://example.com/about']),
            $this->entry('new@example.com'),
        ], 'Second pass');

        $contact->refresh();
        $this->assertDatabaseCount('email_contacts', 2);
        $this->assertDatabaseCount('email_contact_sources', 3);
        $this->assertSame('Tourism office', $contact->name);
        $this->assertTrue($firstSeen->equalTo($contact->created_at));
        $this->assertSame(now()->toDateTimeString(), $contact->last_seen_at->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $contact->sources()->where('url', 'https://example.com/contact')->firstOrFail()->updated_at->toDateTimeString());
        $this->assertSame(1, $second->new_count);
        $this->assertSame(1, $second->existing_count);
        $this->assertSame(2, $contact->scans()->count());
        $this->assertSame('First pass', $first->fresh()->name);
    }

    public function test_distinct_mailbox_aliases_remain_distinct(): void
    {
        $this->archive([$this->entry('a.b@example.com'), $this->entry('ab@example.com'), $this->entry('ab+tag@example.com')]);
        $this->assertDatabaseCount('email_contacts', 3);
    }

    public function test_database_rejects_case_insensitive_duplicates_even_without_the_model(): void
    {
        $this->archive([$this->entry()]);
        $this->expectException(QueryException::class);
        DB::table('email_contacts')->insert(['name' => 'Duplicate', 'email' => 'INFO@EXAMPLE.COM', 'last_seen_at' => now()]);
    }

    public function test_default_names_empty_scans_and_invalid_addresses(): void
    {
        $scan = $this->archive([$this->entry('not-an-email')], '   ');
        $this->assertSame('example.com', $scan->name);
        $this->assertSame(1, $scan->skipped_count);
        $this->assertSame(0, $scan->new_count);
        $this->assertSame(0, $scan->contacts()->count());
        $this->assertDatabaseCount('email_contacts', 0);
        $empty = $this->archive([]);
        $this->assertSame(0, $empty->new_count);
        $this->assertDatabaseCount('email_scans', 2);
    }

    public function test_stored_urls_exclude_secrets_and_duplicate_source_pages(): void
    {
        $scan = $this->archive([$this->entry(sources: [
            'https://user:password@example.com/contact?token=one#fragment',
            'https://example.com/contact?token=two', 'javascript:alert(1)',
        ])], url: 'https://example.com/?api_key=secret#fragment');
        $this->assertSame('https://example.com/', $scan->url);
        $this->assertSame(['https://example.com/contact'], EmailContactSource::pluck('url')->all());
        $this->assertStringNotContainsString('password', EmailContactSource::all()->toJson());
        $this->assertStringNotContainsString('secret', $scan->toJson());
    }

    public function test_a_failed_save_rolls_back_contacts_sources_and_scan_membership(): void
    {
        try {
            // The invalid scan URL violates the NOT NULL constraint after contact inserts.
            $this->archive([$this->entry()], url: 'invalid');
            $this->fail('The transaction should fail.');
        } catch (QueryException) {
            $this->assertDatabaseCount('email_contacts', 0);
            $this->assertDatabaseCount('email_contact_sources', 0);
            $this->assertDatabaseCount('email_scans', 0);
            $this->assertDatabaseCount('email_contact_email_scan', 0);
        }
    }

    public function test_storage_error_keeps_fetched_results_without_fetching_again(): void
    {
        $this->mock(EmailScraperService::class)->shouldReceive('scrape')->once()->andReturn($this->scanResult());
        $this->mock(EmailArchive::class)->shouldReceive('store')->once()->andThrow(new RuntimeException('private database details'));
        $component = Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com'])->call('scrape');
        $this->finishEmailScan($component)->assertSet('error', null)->assertSet('saveSummary', null)
            ->assertSee('Results were not saved')->assertSee('info@example.com')
            ->assertDontSee('private database details');
    }

    public function test_an_overlong_scan_name_is_rejected_before_fetching(): void
    {
        $this->mock(EmailScraperService::class)->shouldNotReceive('scrape');
        Livewire::test(EmailScraper::class)->fillForm(['url' => 'example.com', 'name' => str_repeat('x', 256)])
            ->call('scrape')->assertHasFormErrors(['name']);
        $this->assertDatabaseCount('email_scans', 0);
    }

    public function test_saved_pages_require_an_administrator(): void
    {
        $this->archive([$this->entry()]);
        auth()->logout();
        foreach (['/admin/saved-emails', '/admin/email-scans'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
        $user = User::factory()->create();
        $this->actingAs($user);
        foreach (['/admin/saved-emails', '/admin/email-scans'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->assertFalse($user->can('update', EmailContact::sole()));
        $this->assertFalse($user->can('update', EmailScan::sole()));
    }

    public function test_saved_contacts_can_be_searched_filtered_viewed_and_renamed(): void
    {
        $first = $this->archive([$this->entry('info@example.com')], 'Tourism');
        $this->archive([$this->entry('other@elsewhere.com')], 'Other site');
        $contact = EmailContact::where('email', 'info@example.com')->firstOrFail();
        $other = EmailContact::where('email', 'other@elsewhere.com')->firstOrFail();

        $this->get('/admin/saved-emails')->assertOk()->assertSee('Saved e-mails');
        $component = Livewire::test(ManageEmailContacts::class)->assertCanSeeTableRecords([$contact, $other])
            ->searchTable('info@example.com')->assertCanSeeTableRecords([$contact])->assertCanNotSeeTableRecords([$other])
            ->searchTable('')->filterTable('scans', $first->id)
            ->assertCanSeeTableRecords([$contact])->assertCanNotSeeTableRecords([$other])
            ->mountTableAction('view', $contact)->assertActionMounted();
        $instance = $component->instance();
        $sources = $instance->getSchema($instance->getMountedActionSchemaName())
            ->getComponent(fn ($entry): bool => $entry instanceof TextEntry && $entry->getName() === 'sources.url');
        $this->assertSame(['https://example.com/contact'], $sources->getState());

        Livewire::test(ManageEmailContacts::class)
            ->callTableAction('edit', $contact, data: ['name' => 'Tourism office', 'email' => 'tampered@example.net'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Tourism office', $contact->fresh()->name);
        $this->assertSame('info@example.com', $contact->fresh()->email);
        Livewire::test(ManageEmailContacts::class)->searchTable('Tourism office')->assertCanSeeTableRecords([$contact]);
    }

    public function test_invalid_contact_and_scan_names_are_rejected(): void
    {
        $scan = $this->archive([$this->entry()]);
        $contact = EmailContact::sole();
        foreach (['', '   ', str_repeat('x', 256)] as $name) {
            Livewire::test(ManageEmailContacts::class)->callTableAction('edit', $contact, data: ['name' => $name])
                ->assertHasTableActionErrors(['name']);
            Livewire::test(ManageEmailScans::class)->callTableAction('edit', $scan, data: ['name' => $name])
                ->assertHasTableActionErrors(['name']);
        }
        $this->assertSame('example.com', $contact->fresh()->name);
        $this->assertSame('example.com', $scan->fresh()->name);
    }

    public function test_saved_scans_can_be_renamed_and_link_to_their_own_contacts(): void
    {
        $scan = $this->archive([$this->entry()]);
        $this->get('/admin/email-scans')->assertOk()->assertSee('Saved e-mail scans');
        Livewire::test(ManageEmailScans::class)->assertCanSeeTableRecords([$scan])
            ->assertTableActionHasUrl('contacts', EmailScanResource::contactsUrl($scan), record: $scan)
            ->callTableAction('edit', $scan, data: ['name' => 'Zlatibor contacts'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('Zlatibor contacts', $scan->fresh()->name);
        $this->assertSame('example.com', EmailContact::sole()->name);
        Livewire::test(ManageEmailScans::class)->searchTable('Zlatibor contacts')->assertCanSeeTableRecords([$scan]);
        $this->get(EmailScanResource::contactsUrl($scan))->assertOk()->assertSee('info@example.com');
    }

    public function test_revoked_administrator_cannot_rename_a_saved_contact_or_scan(): void
    {
        $scan = $this->archive([$this->entry()]);
        $contact = EmailContact::sole();
        $contacts = Livewire::test(ManageEmailContacts::class)->mountTableAction('edit', $contact);
        $scans = Livewire::test(ManageEmailScans::class)->mountTableAction('edit', $scan);
        $this->admin->forceFill(['is_admin' => false])->save();
        $contacts->callMountedTableAction()->assertForbidden();
        $scans->callMountedTableAction()->assertForbidden();
        $this->assertSame('example.com', $contact->fresh()->name);
        $this->assertSame('example.com', $scan->fresh()->name);
    }
}
