<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Puts a built desktop bundle where the updater will find it.
 *
 * The updater endpoint reads storage/app/updates/{target}/{arch}/latest.json,
 * so publishing is copying the bundle and its signature there and writing that
 * file. Deliberately manual rather than a pipeline: for one household there is
 * no release process to automate, and a wrong bundle here is one the clients
 * will install.
 *
 * Desktop only. iOS forbids an app installing its own binary, so a phone is
 * updated by rebuilding and reinstalling — which matters far less than it
 * sounds, because almost nothing about this app is compiled into the client.
 */
class PublishRelease extends Command
{
    protected $signature = 'soundchex:release
        {version : The version being published, e.g. 0.2.0}
        {--bundle= : Path to the .app.tar.gz produced by the build}
        {--target=darwin : darwin, linux or windows}
        {--arch=aarch64 : aarch64 or x86_64}
        {--notes= : What changed, shown in the update prompt}';

    protected $description = 'Publish a desktop bundle for the updater to serve';

    public function handle(): int
    {
        $version = (string) $this->argument('version');
        $bundle = (string) ($this->option('bundle') ?? '');

        if ($bundle === '' || ! is_file($bundle)) {
            $this->error('Pass --bundle= pointing at the .app.tar.gz from the build.');
            $this->line('  Built by: npm run build:desktop -- --bundles app');

            return self::FAILURE;
        }

        $signature = $bundle . '.sig';

        if (! is_file($signature)) {
            // Without it the client refuses the update. Better to say so here
            // than to publish something no device will accept.
            $this->error('No signature beside the bundle.');
            $this->line('  Set TAURI_SIGNING_PRIVATE_KEY before building, and the bundle is signed for you.');

            return self::FAILURE;
        }

        $directory = storage_path(sprintf(
            'app/updates/%s/%s',
            preg_replace('/[^a-z0-9_-]/i', '', (string) $this->option('target')),
            preg_replace('/[^a-z0-9_-]/i', '', (string) $this->option('arch')),
        ));

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $filename = basename($bundle);

        copy($bundle, $directory . '/' . $filename);
        copy($signature, $directory . '/' . $filename . '.sig');

        file_put_contents($directory . '/latest.json', json_encode([
            'version' => $version,
            'notes' => (string) ($this->option('notes') ?? ''),
            'pub_date' => now()->toIso8601String(),
            'file' => $filename,
        ], JSON_PRETTY_PRINT));

        $this->info("Published {$version} for {$this->option('target')}/{$this->option('arch')}.");
        $this->line('  Desktop apps will offer it the next time they check.');

        return self::SUCCESS;
    }
}
