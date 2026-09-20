<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\InstalledPlugin;
use App\Plugins\PluginLoader;
use Illuminate\Console\Command;

/**
 * Lists the installed plugins and their state (S-264).
 *
 * Re-scans the plugins directory first, so a plugin's files dropped in by hand
 * show up, then prints what the loader knows: id, version, and whether it is
 * enabled. The admin UI (Phase 4) is the everyday surface; this is the CLI view
 * for a headless install or a quick check.
 */
class PluginList extends Command
{
    protected $signature = 'plugin:list {--rescan : Re-scan the plugins directory before listing}';

    protected $description = 'List installed plugins and whether each is enabled';

    public function handle(PluginLoader $loader): int
    {
        if ($this->option('rescan')) {
            $loader->discover();
        }

        $plugins = InstalledPlugin::query()->orderBy('name')->get();

        if ($plugins->isEmpty()) {
            $this->info('No plugins installed.');
            $this->line('Drop a plugin folder into '.config('soundchex.plugins.path').' and run this with --rescan.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Version', 'Enabled'],
            $plugins->map(fn (InstalledPlugin $p): array => [
                $p->plugin_id,
                $p->name,
                $p->version,
                $p->enabled ? 'yes' : 'no',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
