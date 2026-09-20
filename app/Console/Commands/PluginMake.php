<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scaffolds a working starter plugin (S-264 Phase 5).
 *
 * The single biggest adoption lever, borrowed from Filament's `configure.php`
 * and Jellyfin's `dotnet new` templates: nobody should start a plugin from a
 * blank file. This writes a complete, valid plugin — manifest, entry class, and
 * a sample metadata source — that appears in the admin the moment it is enabled,
 * so an author edits something that already runs rather than assembling one from
 * the docs.
 */
class PluginMake extends Command
{
    protected $signature = 'plugin:make
        {id? : The plugin id, e.g. acme.discogs}
        {--name= : Human name (defaults from the id)}
        {--author= : Author name}';

    protected $description = 'Scaffold a new plugin from a working template';

    public function handle(): int
    {
        $id = $this->argument('id') ?: $this->ask('Plugin id (e.g. acme.discogs)');

        if (! is_string($id) || ! preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*$/i', $id)) {
            $this->error('The id must be a slug like "acme.discogs" (letters, digits, . _ -).');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: Str::of($id)->afterLast('.')->headline()->toString();
        $author = $this->option('author') ?: 'You';

        // The namespace is derived from the id: "acme.discogs" → "Acme\Discogs".
        $namespace = collect(preg_split('/[._-]/', $id))
            ->map(fn (string $part): string => Str::studly($part))
            ->implode('\\');

        $dir = rtrim((string) config('soundchex.plugins.path'), '/').'/'.preg_replace('/[^A-Za-z0-9._-]/', '-', $id);

        if (is_dir($dir)) {
            $this->error("A plugin already exists at {$dir}.");

            return self::FAILURE;
        }

        @mkdir($dir.'/src', 0755, true);

        file_put_contents($dir.'/plugin.json', $this->manifest($id, $name, $author, $namespace));
        file_put_contents($dir.'/src/Plugin.php', $this->pluginClass($id, $namespace));
        file_put_contents($dir.'/src/ExampleSource.php', $this->sourceClass($namespace));

        $this->info("Created {$name} at {$dir}");
        $this->line('  plugin.json          the manifest');
        $this->line('  src/Plugin.php       the entry class (getId / register / boot)');
        $this->line('  src/ExampleSource.php  a sample metadata source');
        $this->newLine();
        $this->line('Enable it under Admin → System → Plugins, then edit src/ to build it out.');
        $this->line('Reference: docs/plugins/README.md');

        return self::SUCCESS;
    }

    private function manifest(string $id, string $name, string $author, string $namespace): string
    {
        return json_encode([
            'id' => $id,
            'name' => $name,
            'version' => '0.1.0',
            'author' => $author,
            'description' => 'A new SoundChex plugin.',
            'minSoundChexVersion' => config('soundchex.version', '0.1.0'),
            'requiresPhp' => '8.2',
            'provides' => ['metadata-source'],
            'entrypoint' => $namespace.'\\Plugin',
            'license' => 'AGPL-3.0-or-later',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function pluginClass(string $id, string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use App\\Plugins\\Contracts\\SoundChexPlugin;
        use App\\Plugins\\Registry;

        /**
         * Your plugin's entry class. `register()` wires your contributions into
         * SoundChex; `boot()` runs only when the app is serving a request.
         */
        class Plugin implements SoundChexPlugin
        {
            public function getId(): string
            {
                return '{$id}';
            }

            public function register(Registry \$registry): void
            {
                // Add a metadata source (see src/ExampleSource.php):
                \$registry->metadataSource('music', ExampleSource::class, 100);

                // React to events:
                // \$registry->on('media.enriched', fn (\$event) => /* ... */);

                // Transform a value passing through:
                // \$registry->filter('metadata.title', fn (\$title, \$item) => \$title);
            }

            public function boot(Registry \$registry): void
            {
                //
            }
        }

        PHP;
    }

    private function sourceClass(string $namespace): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use App\\Enums\\MediaItemType;
        use App\\Models\\MediaItem;
        use App\\Services\\Metadata\\Contracts\\MetadataSource;

        /**
         * A metadata source. `supports()` decides whether it runs for an item;
         * `enrich()` writes into the item's metadata. Delete this if your plugin
         * does not provide one.
         */
        class ExampleSource implements MetadataSource
        {
            public function name(): string
            {
                return 'Example Source';
            }

            public function priority(): int
            {
                return 100; // lower runs earlier
            }

            public function requiredSettings(): array
            {
                return []; // ['my_api_key' => 'My API Key'] to require a key
            }

            public function supports(MediaItem \$item): bool
            {
                return \$item->type === MediaItemType::Music;
            }

            public function enrich(MediaItem \$item): void
            {
                // Write into \$item->musicMetadata here.
            }
        }

        PHP;
    }
}
