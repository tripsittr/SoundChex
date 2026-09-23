<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Models\InstalledPlugin;
use App\Plugins\StyleCompiler;
use Illuminate\Console\Command;

/**
 * Rebuilds every enabled plugin's stylesheet (S-350).
 *
 * Compilation normally happens when a plugin is enabled, which covers the
 * common case. This is for the ones it does not: upgrading the app (the design
 * tokens may have moved), updating a plugin in place, or a first run on a
 * machine whose plugins were enabled before this existed.
 */
class BuildPluginStyles extends Command
{
    protected $signature = 'plugins:styles
        {--plugin= : Only this plugin id}
        {--all : Include disabled plugins}';

    protected $description = "Compile each enabled plugin's stylesheet with the bundled Tailwind CLI";

    public function handle(StyleCompiler $compiler): int
    {
        $base = (string) config('soundchex.plugins.path');

        if ($base === '') {
            $this->error('No plugins path is configured.');

            return self::FAILURE;
        }

        $plugins = InstalledPlugin::query()
            ->when(! $this->option('all'), fn ($q) => $q->where('enabled', true))
            ->when($this->option('plugin'), fn ($q, $id) => $q->where('plugin_id', $id))
            ->orderBy('plugin_id')
            ->get();

        if ($plugins->isEmpty()) {
            $this->info('No plugins to build.');

            return self::SUCCESS;
        }

        $built = 0;
        $skipped = 0;

        foreach ($plugins as $plugin) {
            $directory = $base.DIRECTORY_SEPARATOR.$plugin->directory;

            if (! is_dir($directory)) {
                $this->warn("{$plugin->plugin_id}: directory is missing");
                $skipped++;

                continue;
            }

            $output = $compiler->compile($directory, $plugin->plugin_id);

            if ($output === null) {
                // Not an error: a plugin with no views has nothing to build,
                // and one without a CLI keeps the CSS it ships.
                $this->line("  <fg=gray>{$plugin->plugin_id}: nothing compiled</>");
                $skipped++;

                continue;
            }

            $this->line("  <info>{$plugin->plugin_id}</info>: ".number_format(filesize($output) / 1024, 1).' KB');
            $built++;
        }

        $this->newLine();
        $this->info("Built {$built}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
