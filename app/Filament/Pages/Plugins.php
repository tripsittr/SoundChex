<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\InstalledPlugin;
use App\Plugins\PluginLoader;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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

    public function mount(): void
    {
        $this->rescan();
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

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
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
