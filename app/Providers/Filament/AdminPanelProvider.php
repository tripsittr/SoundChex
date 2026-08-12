<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
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
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            // The file names describe the artwork, not the theme: the "dark"
            // logo has black elements and needs a light background, and vice
            // versa. So they pair with the opposite-named mode.
            ->brandLogo(fn (): string => asset('storage/soundchex_logo_dark.png'))
            ->darkModeBrandLogo(fn (): string => asset('storage/soundchex_logo_white.png'))
            ->brandLogoHeight('3.5rem')
            ->sidebarWidth('15rem')
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => (app()->isLocal() && config('app.dev_login_autofill.enabled'))
                    ? view('filament.dev-login-autofill')->render()
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => (string) app(Vite::class)('resources/css/filament/admin/theme.css'),
            )
            ->colors([
                'primary' => '#5A98D7',
                'secondary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Media Library'),
                NavigationGroup::make('Users & Permissions'),
                NavigationGroup::make('Settings'),
            ])
            // The media center is a separate Blade app, so Filament can't
            // discover it — the way back has to be declared explicitly.
            ->navigationItems([
                NavigationItem::make('Back to Library')
                    ->url(fn (): string => route('media.home'))
                    ->icon(Heroicon::OutlinedFilm)
                    // Dashboard sorts at -2, so this must be lower to sit above it.
                    ->sort(-3),
            ])
            ->userMenuItems([
                Action::make('mediaCenter')
                    ->label('Back to Library')
                    ->icon(Heroicon::OutlinedFilm)
                    ->url(fn (): string => route('media.home')),
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
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Users & Permissions'),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

}
