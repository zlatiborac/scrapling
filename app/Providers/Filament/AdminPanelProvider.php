<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Filament\Pages\TestScraper;
use App\Filament\Resources\ScrapeHistoryResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use pxlrbt\FilamentEnvironmentIndicator\EnvironmentIndicatorPlugin;
use Rmsramos\Activitylog\ActivitylogPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Scrapling')
            ->homeUrl(fn () => TestScraper::getUrl())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugins([
                ActivitylogPlugin::make()
                    ->resource(ScrapeHistoryResource::class)
                    ->label('Scrape attempt')
                    ->pluralLabel('Scrape history')
                    ->navigationGroup('Monitoring')
                    ->navigationSort(20)
                    ->navigationCountBadge(false)
                    ->authorize(fn () => (bool) auth()->user()?->is_admin)
                    ->isRestoreActionHidden(true)
                    ->isRestoreModelActionHidden(true)
                    ->isResourceActionHidden(true),
                EnvironmentIndicatorPlugin::make()
                    ->visible(fn () => (bool) auth()->user()?->is_admin)
                    ->showBadge()
                    ->showBorder()
                    ->showGitBranch(false),
            ])
            ->login(Login::class)
            ->profile(isSimple: false)
            ->colors(['primary' => Color::Indigo])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
