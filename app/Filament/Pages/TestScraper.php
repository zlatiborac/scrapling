<?php

namespace App\Filament\Pages;

use App\Services\Scraper;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
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

        try {
            $state = $this->form->getState();
            RateLimiter::hit('scrape:'.auth()->id(), 60);
            $this->result = $scraper->scrape($state['url']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();
        }
    }
}
