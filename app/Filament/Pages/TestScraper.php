<?php

namespace App\Filament\Pages;

use App\Services\ScrapeHistory;
use App\Services\Scraper;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TestScraper extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $title = 'Test scraper';

    protected static ?string $slug = 'test-scraper';

    protected string $view = 'filament.pages.test-scraper';

    public ?array $data = [];

    public ?array $result = null;

    public ?string $error = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('url')
                    ->label('Page URL')
                    ->placeholder('https://example.com')
                    ->url()
                    ->required()
                    ->maxLength(2048)
                    ->helperText('Paste a public HTTP or HTTPS link to extract its title and headings.'),
            ])
            ->statePath('data');
    }

    public function scrape(Scraper $scraper): void
    {
        // Never trust authentication from a previous Livewire request.
        abort_unless(auth()->user()?->is_admin, 403);
        $this->result = null;
        $this->error = null;

        if (RateLimiter::tooManyAttempts('scrape:'.auth()->id(), 10)) {
            $this->error = 'Please wait a minute before testing another URL.';

            return;
        }

        $state = $this->form->getState();
        RateLimiter::hit('scrape:'.auth()->id(), 60);
        $startedAt = hrtime(true);

        try {
            $this->result = $scraper->scrape($state['url']);
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }

        // Keep audit storage errors distinct from a failed fetch; never rerun the request.
        try {
            app(ScrapeHistory::class)->record(
                auth()->user(),
                $state['url'],
                $this->result,
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
            );
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()
                ->title('The attempt could not be saved to scrape history.')
                ->warning()
                ->send();
        }
    }
}
