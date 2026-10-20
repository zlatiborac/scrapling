<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ScrapeHistoryResource\Pages\ListScrapeHistory;
use App\Filament\Resources\ScrapeHistoryResource\Pages\ViewScrapeAttempt;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Rmsramos\Activitylog\Resources\Activitylog\ActivitylogResource;

class ScrapeHistoryResource extends ActivitylogResource
{
    protected static ?string $slug = 'scrape-history';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('log_name', 'scraper');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('event')->label('Outcome')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'succeeded' ? 'success' : 'danger'),
                TextColumn::make('properties.url')->label('URL')->wrap()->limit(80)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('properties->url', 'like', '%'.$search.'%')),
                TextColumn::make('properties.http_status')->label('HTTP')->placeholder('—'),
                TextColumn::make('properties.title')->label('Title')->limit(60)->wrap()->toggleable(),
                TextColumn::make('properties.duration_ms')->label('Duration')->suffix(' ms'),
                TextColumn::make('causer.name')->label('Run by')->placeholder('—')->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event')->label('Outcome')->options([
                    'succeeded' => 'Succeeded',
                    'failed' => 'Failed',
                ]),
                static::getDateFilterComponent(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('event')->label('Outcome')->badge()
                ->formatStateUsing(fn (string $state): string => ucfirst($state))
                ->color(fn (string $state): string => $state === 'succeeded' ? 'success' : 'danger'),
            TextEntry::make('created_at')->label('When')->dateTime(),
            TextEntry::make('properties.url')->label('URL')->copyable()->columnSpanFull(),
            TextEntry::make('properties.title')->label('Page title')->placeholder('—')->columnSpanFull(),
            TextEntry::make('properties.http_status')->label('HTTP status')->placeholder('—'),
            TextEntry::make('properties.heading_count')->label('H1 headings')->placeholder('—'),
            TextEntry::make('properties.duration_ms')->label('Duration')->suffix(' ms'),
            TextEntry::make('causer.name')->label('Run by')->placeholder('—'),
        ])->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScrapeHistory::route('/'),
            'view' => ViewScrapeAttempt::route('/{record}'),
        ];
    }
}
