<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Auth\Login;
use Apriansyahrs\MekayaTheme\MekayaPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->plugin(
                MekayaPlugin::make()
                    ->colors([
                        'primary' => Color::Amber,
                    ])
                    ->sidebarWidth('16rem'),
            )
            ->brandName('WMS')
            ->viteTheme('resources/css/app.css')
            ->login(Login::class)
            ->profile(EditProfile::class, isSimple: false)
            ->registration(null)
            ->passwordReset(null)
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn () => view('auth.login-extra'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                'Logistik & Ekspedisi',
                'Tracking Order',
                'Ekspedisi',
                'Audit',
                'Filament Shield',
                'Developer & Tools',
            ])
            ->navigationItems([
                NavigationItem::make('API Documentation')
                    ->url('/docs/api', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedCodeBracketSquare)
                    ->group('Developer & Tools')
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin')
                        || (auth()->user()?->can('ViewApiDocs') ?? false)
                        || (auth()->user()?->can('view_api_docs') ?? false)
                        || (auth()->user()?->can('ViewScramble') ?? false)
                    )
                    ->sort(1),

                NavigationItem::make('Laravel Horizon')
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedCpuChip)
                    ->group('Developer & Tools')
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin')
                        || (auth()->user()?->can('ViewHorizon') ?? false)
                        || (auth()->user()?->can('view_horizon') ?? false)
                    )
                    ->sort(2),

                NavigationItem::make('Log Viewer')
                    ->url('/log-viewer', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
                    ->group('Developer & Tools')
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin')
                        || (auth()->user()?->can('ViewLogViewer') ?? false)
                        || (auth()->user()?->can('view_log_viewer') ?? false)
                    )
                    ->sort(3),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
