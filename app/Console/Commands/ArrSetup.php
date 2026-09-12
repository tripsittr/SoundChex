<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Gets the acquisition stack running with as little asked of the user as the
 * job allows.
 *
 * Everything this needs is already known — where the library lives, who owns
 * the files, which ports are free — so asking someone to derive three absolute
 * paths and their own UID and paste them into `.env` is three chances to get it
 * wrong for no benefit. This writes the environment file the compose stack
 * reads, makes the folders, and starts it.
 *
 * What it deliberately does *not* do is configure an indexer. Nothing arrives
 * until the user adds one by hand inside each app, which is a decision that
 * belongs to them and not to a setup command.
 */
class ArrSetup extends Command
{
    protected $signature = 'arr:setup
        {--start : Start the stack once the files are written}
        {--force : Overwrite an existing environment file}';

    protected $description = 'Prepare the Radarr, Sonarr and Lidarr stack';

    public function handle(): int
    {
        $compose = base_path('docker/arr/compose.yaml');

        if (! File::exists($compose)) {
            $this->components->error('docker/arr/compose.yaml is missing.');

            return self::FAILURE;
        }

        if (! $this->dockerAvailable()) {
            // Not fatal. The files are still worth writing — someone may be
            // installing Docker next, and a half-done setup is worse than a
            // complete one that is not yet running.
            $this->components->warn('Docker is not running. Files will be written; start it, then run with --start.');
        }

        $paths = config('arr.paths');

        foreach (['config', 'downloads'] as $which) {
            File::ensureDirectoryExists($paths[$which]);
        }

        // The media root is not created: it is the library, and if it does not
        // exist then something is wrong that this command must not paper over
        // by making an empty directory the scanner will happily watch.
        if (! File::isDirectory($paths['media'])) {
            $this->components->error("The media folder does not exist: {$paths['media']}");

            return self::FAILURE;
        }

        $envPath = base_path('docker/arr/.env');

        if (File::exists($envPath) && ! $this->option('force')) {
            $this->components->info('docker/arr/.env already exists; leaving it alone. Use --force to rewrite.');
        } else {
            File::put($envPath, $this->envContents($paths));
            $this->components->info('Wrote docker/arr/.env');
        }

        $this->table(
            ['Setting', 'Value'],
            [
                ['Media (watched)', $paths['media']],
                ['App config', $paths['config']],
                ['Downloads', $paths['downloads']],
                ['Owner', config('arr.puid') . ':' . config('arr.pgid')],
            ],
        );

        if (! $this->option('start')) {
            $this->newLine();
            $this->components->info('Run with --start to bring the stack up, or: docker compose -f docker/arr/compose.yaml up -d');

            return self::SUCCESS;
        }

        return $this->start($compose);
    }

    private function start(string $compose): int
    {
        $this->components->info('Starting…');

        // Inherit the terminal so image pulls show progress; the first run
        // fetches several hundred megabytes and silence reads as a hang.
        $process = new Process(['docker', 'compose', '-f', $compose, 'up', '-d'], base_path());
        $process->setTimeout(600);

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->components->error('Compose failed. The output above says why.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Running. Open each app, copy its API key from Settings → General,');
        $this->components->info('and paste it into Admin → System → Acquisition.');

        $this->table(
            ['App', 'Address'],
            collect(config('arr.apps'))
                ->map(fn (array $app): array => [$app['label'], $app['url']])
                ->push(['qBittorrent', 'http://127.0.0.1:8080'])
                ->all(),
        );

        return self::SUCCESS;
    }

    /** @param array<string, string> $paths */
    private function envContents(array $paths): string
    {
        $lines = [
            '# Written by `php artisan arr:setup`. Safe to edit and re-run with --force.',
            '#',
            '# These are bind mounts of real directories. What the containers write',
            '# here appears immediately to the library scanner — and what they delete',
            '# is really deleted.',
            '',
            'SOUNDCHEX_MEDIA_ROOT=' . $paths['media'],
            'ARR_CONFIG_ROOT=' . $paths['config'],
            'ARR_DOWNLOADS_ROOT=' . $paths['downloads'],
            '',
            'ARR_PUID=' . config('arr.puid'),
            'ARR_PGID=' . config('arr.pgid'),
            'ARR_TZ=' . config('app.timezone', 'UTC'),
            '',
        ];

        return implode(PHP_EOL, $lines);
    }

    private function dockerAvailable(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }
}
