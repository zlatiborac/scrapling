<?php

namespace App\Filament\Resources;

use App\Filament\Pages\EmailScraper;
use App\Filament\Resources\EmailScanResource\Pages\ManageEmailScans;
use App\Models\EmailScan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailScanResource extends Resource
{
    protected static ?string $model = EmailScan::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationLabel = 'Saved e-mail scans';

    protected static ?string $pluralModelLabel = 'Saved e-mail scans';

    protected static ?string $modelLabel = 'E-mail scan';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'email-scans';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Scan name')->required()->maxLength(255)->rules(['regex:/\S/u']),
        ]);
    }

    public static function contactsUrl(EmailScan|int $scan): string
    {
        return EmailContactResource::getUrl('index', ['filters' => ['scans' => ['value' => $scan instanceof EmailScan ? $scan->id : $scan]]]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Scan name')->searchable()->sortable()->wrap(),
            TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => ucfirst($state))
                ->color(fn (string $state): string => match ($state) {
                    'completed' => 'success', 'failed' => 'danger', default => 'warning'
                }),
            TextColumn::make('url')->label('Starting page')->searchable()->wrap()->limit(80),
            TextColumn::make('mode')->label('Scan')->formatStateUsing(fn (string $state): string => $state === 'website' ? 'Website' : 'Single page'),
            TextColumn::make('contacts_count')->label('E-mails')->counts('contacts')->numeric(),
            TextColumn::make('new_count')->label('New')->numeric(),
            TextColumn::make('existing_count')->label('Already saved')->numeric(),
            TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
        ])
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->filters([SelectFilter::make('status')->options(['queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed'])])
            ->recordActions([
                Action::make('progress')->label('Status / results')->url(fn (EmailScan $record): string => EmailScraper::getUrl(['scan' => $record->id])),
                Action::make('contacts')->label('View e-mails')->url(fn (EmailScan $record): string => static::contactsUrl($record)),
                EditAction::make()->label('Rename'),
            ])
            ->emptyStateHeading('No saved scans yet')
            ->emptyStateDescription('Queued, running, completed, and failed E-mail scraper scans will appear here.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailScans::route('/')];
    }
}
