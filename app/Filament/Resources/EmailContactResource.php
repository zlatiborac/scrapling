<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailContactResource\Pages\ManageEmailContacts;
use App\Models\EmailContact;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EmailContactResource extends Resource
{
    protected static ?string $model = EmailContact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'Saved e-mails';

    protected static ?string $pluralModelLabel = 'Saved e-mails';

    protected static ?string $modelLabel = 'E-mail contact';

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $slug = 'saved-emails';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Contact name')->required()->maxLength(255)->rules(['regex:/\S/u'])
                ->helperText('Use a person or organization name. Future scans preserve your changes.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Contact name'),
            TextEntry::make('email')->label('E-mail')->copyable(),
            TextEntry::make('sources.url')->label('Source pages')->listWithLineBreaks()->placeholder('No source pages')->columnSpanFull(),
            TextEntry::make('scans.name')->label('Saved scans')->listWithLineBreaks()->columnSpanFull(),
            TextEntry::make('created_at')->label('First found')->dateTime(),
            TextEntry::make('last_seen_at')->label('Last found')->dateTime(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Contact name')->searchable()->sortable()->wrap(),
            TextColumn::make('email')->label('E-mail')->searchable()->sortable()->wrap()->copyable(),
            TextColumn::make('sources_count')->label('Source pages')->counts('sources')->numeric(),
            TextColumn::make('scans_count')->label('Scans')->counts('scans')->numeric(),
            TextColumn::make('last_seen_at')->label('Last found')->dateTime()->sortable(),
        ])
            ->defaultSort('last_seen_at', 'desc')
            ->filters([
                SelectFilter::make('scans')->label('Saved scan')->relationship('scans', 'name')->searchable()->preload(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()->label('Rename')])
            ->emptyStateHeading('No saved e-mails yet')
            ->emptyStateDescription('Run the E-mail scraper to save discovered contacts automatically.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEmailContacts::route('/')];
    }
}
