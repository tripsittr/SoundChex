<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\InstalledPlugin;
use App\Models\PluginRepository;
use App\Plugins\Catalog\CatalogEntry;
use App\Plugins\Catalog\PluginCatalog;
use App\Plugins\Catalog\PluginInstaller;
use App\Plugins\Exceptions\PluginInstallException;
use App\Plugins\PluginLoader;
use App\Support\RevealInFileManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Request;
use UnitEnum;

/**
 * The plugin manager (S-264 Phase 4).
 *
 * Installed plugins live as folders on disk; this is where an admin sees them,
 * turns them on and off, and reads what each one is and provides. Enabling is a
 * deliberate act here — a plugin is arbitrary code, so it arrives disabled and a
 * human switches it on, having seen who wrote it and what it touches.
 *
 * Read the trust note on the page: no plugin is sandboxed. This surface makes
 * the choice explicit rather than automatic.
 */
class Plugins extends Page
{
    use RestrictsToServerAdmins;

    protected string $view = 'filament.pages.plugins';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Plugins';

    protected static ?string $navigationLabel = 'Plugins';

    /** @var array<int, array<string, mixed>> */
    public array $plugins = [];

    /** The catalog entries fetched from the configured repositories. */
    public array $catalog = [];

    /** A repository URL being added. */
    public string $newRepositoryUrl = '';

    /** Whether the catalog has been fetched this visit (it costs a network call). */
    public bool $catalogLoaded = false;

    public function mount(): void
    {
        $this->rescan();
    }

    /**
     * Whether this panel is being used *on the home server itself*.
     *
     * Plugin files live on the server's own disk, so managing them — dropping a
     * folder in, opening it in a file manager — only makes sense from the machine
     * running the server. Checked against the connection's own address, the same
     * loopback test the server-inspection routes use, never a header a remote
     * caller could set.
     */
    public function isLocalRequest(): bool
    {
        // An operator behind a loopback reverse proxy (every request arrives as
        // 127.0.0.1) can force this off so the local-only controls never appear;
        // left null, the connection's own address decides. Never a header — that
        // is set by whoever is asking.
        $override = config('soundchex.plugins.local_management');

        if ($override !== null) {
            return (bool) $override;
        }

        return in_array(Request::ip(), ['127.0.0.1', '::1', 'localhost'], true);
    }

    /**
     * A path shown to the operator: the plugins directory, but only when they
     * are on the server. Remotely it is withheld — the location of files on
     * someone else's machine is not a remote admin's concern, and the absolute
     * path would be meaningless to them anyway.
     */
    public function pluginsPathForDisplay(): ?string
    {
        return $this->isLocalRequest()
            ? (string) config('soundchex.plugins.path')
            : null;
    }

    /**
     * Opens the plugins directory in the server's file manager. Local-only, and
     * best-effort: on a headless server there is nothing to open, so it shows the
     * path to navigate to instead.
     */
    public function openPluginsFolder(): void
    {
        if (! $this->isLocalRequest()) {
            return;
        }

        $path = (string) config('soundchex.plugins.path');

        // Make sure it exists before trying to open it — a fresh install may not
        // have scanned yet.
        app(PluginLoader::class)->discover();

        if (RevealInFileManager::open($path)) {
            return;
        }

        Notification::make()
            ->title('Open it here')
            ->body($path)
            ->info()
            ->persistent()
            ->send();
    }

    /**
     * Re-scans the plugins directory (picking up folders added by hand) and
     * reloads the list. Scanning never changes a plugin's enabled state.
     */
    public function rescan(): void
    {
        app(PluginLoader::class)->discover();
        $this->load();
    }

    public function load(): void
    {
        $this->plugins = InstalledPlugin::query()
            ->orderBy('name')
            ->get()
            ->map(fn (InstalledPlugin $p): array => [
                'id' => $p->plugin_id,
                'name' => $p->name,
                'version' => $p->version,
                'enabled' => $p->enabled,
                'author' => $p->manifest['author'] ?? null,
                'description' => $p->manifest['description'] ?? null,
                'provides' => $p->manifest['provides'] ?? [],
                'license' => $p->manifest['license'] ?? null,
            ])
            ->all();
    }

    /**
     * Turns a plugin on or off. A change takes effect on the next request —
     * plugin code is wired at boot, so the app says a reload is needed rather
     * than pretending a running plugin vanished mid-request.
     */
    public function toggle(string $pluginId): void
    {
        $plugin = InstalledPlugin::query()->where('plugin_id', $pluginId)->first();

        if ($plugin === null) {
            return;
        }

        $plugin->forceFill(['enabled' => ! $plugin->enabled])->save();
        $this->load();

        Notification::make()
            ->title($plugin->enabled ? $plugin->name.' enabled' : $plugin->name.' disabled')
            ->body('Takes effect on the next page load.')
            ->success()
            ->send();
    }

    /* -------------------------------------------------------- catalog --- */

    /** The configured repositories, for the browse UI. */
    public function repositories(): array
    {
        return PluginRepository::query()
            ->orderByDesc('official')
            ->orderBy('name')
            ->get(['id', 'name', 'url', 'official'])
            ->all();
    }

    /**
     * Fetches every repository's catalog and lists what can be installed, minus
     * anything already installed. A network call, so it runs on demand rather
     * than on every page load.
     */
    public function browse(): void
    {
        $catalog = app(PluginCatalog::class);
        $installed = InstalledPlugin::query()->pluck('plugin_id')->all();

        $entries = [];

        foreach (PluginRepository::query()->get() as $repository) {
            foreach ($catalog->fetch($repository->url) as $entry) {
                if (in_array($entry->id, $installed, true)) {
                    continue;
                }

                // Plain data only — a Livewire property cannot hold the entry
                // object. The entry is re-fetched from its repository at install.
                $entries[$entry->id] = [
                    'id' => $entry->id,
                    'name' => $entry->name,
                    'description' => $entry->description,
                    'author' => $entry->author,
                    'version' => $entry->version(),
                    'installable' => $entry->isInstallable(),
                    'repository' => $repository->name,
                    'repositoryUrl' => $repository->url,
                ];
            }
        }

        $this->catalog = array_values($entries);
        $this->catalogLoaded = true;
    }

    /** Installs a catalog entry by id — re-fetched fresh from its repository. */
    public function install(string $pluginId): void
    {
        $listed = collect($this->catalog)->firstWhere('id', $pluginId);

        // Re-fetch from the repository rather than trusting stale page state, so
        // the download URL and checksum are the repository's current ones.
        $entry = $listed
            ? collect(app(PluginCatalog::class)->fetch($listed['repositoryUrl']))->firstWhere('id', $pluginId)
            : null;

        if (! $entry instanceof CatalogEntry) {
            Notification::make()->title('Refresh the catalogue and try again.')->warning()->send();

            return;
        }

        try {
            app(PluginInstaller::class)->install($entry);

            Notification::make()
                ->title($entry->name.' installed')
                ->body('It is disabled — enable it below once you have reviewed it.')
                ->success()
                ->send();
        } catch (PluginInstallException $e) {
            Notification::make()->title('Install failed')->body($e->getMessage())->danger()->send();

            return;
        }

        $this->rescan();
        $this->browse();
    }

    /** Adds a repository URL to browse from. */
    public function addRepository(): void
    {
        $url = trim($this->newRepositoryUrl);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            Notification::make()->title('Enter a valid repository URL.')->warning()->send();

            return;
        }

        PluginRepository::query()->firstOrCreate(['url' => $url], [
            'name' => parse_url($url, PHP_URL_HOST) ?: $url,
            'official' => false,
        ]);

        $this->newRepositoryUrl = '';
        $this->browse();

        Notification::make()->title('Repository added')->success()->send();
    }

    public function removeRepository(int $id): void
    {
        PluginRepository::query()->whereKey($id)->where('official', false)->delete();
        $this->browse();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            // Only offered on the server itself — opening a folder on the host is
            // meaningless to a remote browser, and plugin files are the server's
            // own disk.
            Action::make('openPluginsFolder')
                ->label('Open Plugins Folder')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->visible(fn (): bool => $this->isLocalRequest())
                ->action(fn () => $this->openPluginsFolder()),

            Action::make('rescan')
                ->label('Re-scan')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $this->rescan();

                    Notification::make()
                        ->title(count($this->plugins).' '.str('plugin')->plural(count($this->plugins)).' found')
                        ->success()
                        ->send();
                }),
        ];
    }
}
