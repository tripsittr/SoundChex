<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Models\InstalledPlugin;
use App\Plugins\PluginLoader;
use App\Plugins\Registry;
use App\Plugins\StyleCompiler;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
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
use Illuminate\Support\Facades\Schema;
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
            // Each enabled plugin's compiled stylesheet, after the theme so a
            // plugin can lean on the panel's own variables (S-350). Built when
            // the plugin is enabled; absent until then, and absent entirely for
            // a plugin with no UI.
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => $this->pluginStyleTags(),
            )
            // The upload indicator. Registered here as well as in the media
            // center because uploading happens *in the panel* — the two share
            // no bundle, so leaving it out would mean no progress shown on the
            // one page where files are actually sent.
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => (string) app(Vite::class)([
                    'resources/js/upload-progress.js',
                    'resources/css/upload-progress.css',
                    // External links. A Tauri webview has no tabs, so
                    // `target="_blank"` is silently ignored and every link out
                    // of the panel is dead in the packaged app — which is where
                    // the Integrations page's "Open" buttons are used.
                    'resources/js/external-links.js',
                ]),
            )
            // The media center's accent, so buttons, links, focus rings and
            // active states inherit it rather than being patched one selector
            // at a time. The panel previously used a blue that appeared
            // nowhere else in the product.
            ->colors([
                'primary' => Color::hex('#e11d3a'),
                'gray' => Color::Zinc,
            ])
            // Dark by default, matching the app. Light stays available and
            // legible — the panel is where long tables live, and reading one
            // in a bright room is a real use.
            ->defaultThemeMode(ThemeMode::Dark)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                // Pages a plugin contributes via Registry::adminPage(). The
                // loader boots first (idempotent) so a plugin's register() has
                // run and populated the registry; each page still gates itself
                // through its own canAccess(). Filament only auto-discovers
                // pages under app/Filament, so a plugin's page — in its own
                // namespace — has to be handed over explicitly like this.
                ...$this->pluginAdminPages(),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                // FilamentInfoWidget::class,
                // Widgets contributed by enabled plugins (S-316), handed over
                // the same way their pages are: discovery cannot find a class
                // that lives outside the app's namespace.
                ...$this->pluginWidgets(),
            ])
            // Declared so the order is a decision rather than whatever order
            // the pages happen to be discovered in. System is last and holds
            // the screens that administer the machine rather than the library
            // — it is hidden entirely from profiles without
            // `Access:ServerAdministration`, so for most people the sidebar
            // simply ends at Settings.
            ->navigationGroups([
                NavigationGroup::make('Media Library'),
                NavigationGroup::make('Users & Permissions'),
                NavigationGroup::make('Settings'),
                NavigationGroup::make('Plugins'),
                NavigationGroup::make('System'),
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

    /**
     * Filament page classes contributed by plugins, for the panel's page list.
     *
     * Boots the plugin loader first — it is idempotent, so calling it here as
     * well as in PluginServiceProvider is harmless, and it guarantees every
     * plugin's register() has run before we read the registry, whatever order
     * the panel is built in. A misbehaving plugin must not take the whole panel
     * down, so a failure here is swallowed to an empty list.
     *
     * @return array<int, string>
     */
    private function pluginAdminPages(): array
    {
        try {
            app(PluginLoader::class)->boot();

            $pages = app(Registry::class)->adminPageClasses();

            foreach ($pages as $page) {
                $this->groupPluginPage($page);
            }

            return $pages;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Files a plugin's page under the Plugins nav group unless it asked for a
     * different one (S-364).
     *
     * Left alone, plugin pages scatter: one puts itself in System next to the
     * screens that administer the machine, another declares nothing and floats
     * ungrouped above the whole sidebar. Neither tells an operator which parts
     * of the panel came from a plugin.
     *
     * A plugin that names a group means it — the audit log genuinely belongs
     * beside the other System screens — so only pages that named none are
     * moved. "Named none" is not the same as "is null": every Filament page
     * redeclares the property, so the value is what distinguishes a deliberate
     * choice from a default left untouched.
     *
     * Best-effort per page. A page whose class cannot be loaded (an autoload
     * failure in a plugin's own namespace) keeps whatever group it had rather
     * than costing the panel its whole plugin page list.
     */
    private function groupPluginPage(string $page): void
    {
        try {
            if (! is_subclass_of($page, Page::class)) {
                return;
            }

            if ($page::getNavigationGroup() !== null) {
                return;
            }

            $page::navigationGroup('Plugins');
        } catch (\Throwable) {
            // Leave the page where it is; a misgrouped page beats a missing one.
        }
    }

    /**
     * Dashboard widgets contributed by enabled plugins (S-316).
     *
     * @return array<int, class-string>
     */
    private function pluginWidgets(): array
    {
        try {
            app(PluginLoader::class)->boot();

            return app(Registry::class)->widgetClasses();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * `<link>` tags for every enabled plugin that has a compiled stylesheet.
     *
     * Reads the install table rather than the loader, so this works on the very
     * request that follows an enable — before the loader has booted the plugin.
     */
    private function pluginStyleTags(): string
    {
        // The plugins table may not exist yet on a fresh install mid-migration.
        if (! Schema::hasTable('installed_plugins')) {
            return '';
        }

        $base = (string) config('soundchex.plugins.path');

        if ($base === '') {
            return '';
        }

        $compiler = app(StyleCompiler::class);
        $tags = '';

        foreach (InstalledPlugin::query()->where('enabled', true)->get() as $plugin) {
            $directory = $base.DIRECTORY_SEPARATOR.$plugin->directory;

            if ($compiler->compiledPath($directory) === null) {
                continue;
            }

            // Cache-bust on the file's own mtime, so a recompiled stylesheet is
            // picked up without the user clearing anything.
            $url = route('plugin.styles', ['plugin' => $plugin->plugin_id])
                .'?v='.filemtime($directory.'/'.StyleCompiler::OUTPUT);

            $tags .= '<link rel="stylesheet" href="'.e($url).'">';
        }

        return $tags;
    }
}
