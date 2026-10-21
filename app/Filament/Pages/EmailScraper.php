<?php

namespace App\Filament\Pages;

use App\Models\EmailScan;
use App\Services\EmailScanQueue;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

class EmailScraper extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $title = 'E-mail scraper';

    protected static ?string $slug = 'email-scraper';

    protected string $view = 'filament.pages.email-scraper';

    public ?array $data = [];

    public ?array $result = null;

    public ?string $error = null;

    public ?array $saveSummary = null;

    public ?string $saveError = null;

    #[Locked]
    #[Url(as: 'scan')]
    public ?int $scanId = null;

    public ?string $scanStatus = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public function mount(): void
    {
        $this->form->fill();
        $this->scanId ??= EmailScan::where('user_id', auth()->id())
            ->whereIn('status', ['queued', 'running'])->latest('id')->value('id');
        $this->refreshScan();
    }

    public function form(Schema $schema): Schema
    {
        $resolveUrl = static function (?string $state, Get $get): string {
            $url = trim($state ?? '');

            return $url === '' || preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) ? $url : $get('scheme').'://'.$url;
        };

        return $schema
            ->columns(['default' => 1, 'sm' => 4])
            ->components([
                Select::make('scheme')->label('Protocol')
                    ->options(['https' => 'https://', 'http' => 'http://'])
                    ->default('https')->selectablePlaceholder(false)->required()->in(['http', 'https']),
                TextInput::make('url')->label('Starting page URL')
                    ->placeholder('zlatibor.org.rs or https://example.com/contact')
                    ->columnSpan(['default' => 1, 'sm' => 3])
                    ->mutateStateForValidationUsing($resolveUrl)->dehydrateStateUsing($resolveUrl)
                    ->rules([
                        'url:http,https',
                        static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            $host = parse_url($value, PHP_URL_HOST);
                            if (! is_string($host) || (! str_contains($host, '.') && ! filter_var(trim($host, '[]'), FILTER_VALIDATE_IP))) {
                                $fail('Enter a valid public domain or HTTP or HTTPS URL.');
                            }
                        },
                    ])
                    ->required()->maxLength(2048)
                    ->helperText('A full URL overrides the protocol. Website mode follows links from this page on the same hostname.'),
                Select::make('mode')->label('Scan')->options(['page' => 'Single page', 'website' => 'Website (linked pages)'])
                    ->default('page')->required()->in(['page', 'website'])->selectablePlaceholder(false)->live()
                    ->columnSpan(['default' => 1, 'sm' => 2]),
                TextInput::make('name')->label('Scan name')->maxLength(255)
                    ->placeholder('Defaults to the starting website hostname')
                    ->helperText('Optional. You can rename the saved scan later.')
                    ->columnSpan(['default' => 1, 'sm' => 2]),
                TextInput::make('max_pages')->label('Maximum pages')->numeric()->integer()->minValue(1)->maxValue(50)->default(20)
                    ->required()->visible(fn (Get $get): bool => $get('mode') === 'website')
                    ->columnSpan(['default' => 1, 'sm' => 2]),
                TextInput::make('max_depth')->label('Maximum link depth')->numeric()->integer()->minValue(0)->maxValue(5)->default(3)
                    ->required()->helperText('Starting page = 0; its links = 1.')
                    ->visible(fn (Get $get): bool => $get('mode') === 'website')
                    ->columnSpan(['default' => 1, 'sm' => 2]),
            ])->statePath('data');
    }

    public function scrape(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        $this->result = null;
        $this->error = null;
        $this->saveSummary = null;
        $this->saveError = null;

        if ($this->scanId && EmailScan::whereKey($this->scanId)->whereIn('status', ['queued', 'running'])->exists()) {
            $this->refreshScan();

            return;
        }

        if (RateLimiter::tooManyAttempts('email-scrape:'.auth()->id(), 5)) {
            $this->error = 'Please wait a minute before starting another e-mail scan.';

            return;
        }

        $state = $this->form->getState();
        RateLimiter::hit('email-scrape:'.auth()->id(), 60);

        try {
            $this->scanId = app(EmailScanQueue::class)->submit($state, auth()->user())->id;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = 'The scan could not be queued. Check that migrations have been run and try again.';

            return;
        }

        $this->refreshScan();
    }

    public function refreshScan(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        if (! $this->scanId) {
            return;
        }

        $scan = EmailScan::findOrFail($this->scanId);
        // Normal polls only read, avoiding SQLite write contention while archiving.
        if ($scan->status === 'running' && $scan->started_at?->lt(now()->subSeconds(120))) {
            // Recover a killed worker without auto-repeating the crawl.
            EmailScan::whereKey($scan->id)->where('status', 'running')->where('started_at', '<', now()->subSeconds(120))
                ->update(['status' => 'failed', 'request' => null, 'finished_at' => now(),
                    'failure_message' => 'The background scan was interrupted or exceeded its time limit. Start a new scan.']);
            $scan->refresh();
        }

        $this->scanStatus = $scan->status;
        $this->error = null;
        $this->saveError = null;
        $this->saveSummary = null;
        $this->result = in_array($scan->status, ['completed', 'failed'], true) ? $scan->result : null;
        if ($scan->status === 'completed') {
            $this->saveSummary = [
                'id' => $scan->id, 'name' => $scan->name,
                'new' => $scan->new_count, 'existing' => $scan->existing_count, 'skipped' => $scan->skipped_count,
            ];
        } elseif ($scan->status === 'failed') {
            if ($this->result !== null) {
                $this->saveError = $scan->failure_message;
            } else {
                $this->error = $scan->failure_message;
            }
        }
    }

    public function cancelQueuedScan(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
        if ($this->scanId) {
            EmailScan::whereKey($this->scanId)->where('status', 'queued')->update([
                'status' => 'failed', 'request' => null, 'finished_at' => now(), 'failure_message' => 'The queued scan was cancelled.',
            ]);
            $this->refreshScan();
        }
    }
}
