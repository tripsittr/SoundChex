<?php

namespace App\Console\Commands;

use App\Services\HostServices;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Ships a change to every device.
 *
 * Almost nothing about this app is compiled into the clients: the Blade, CSS
 * and JavaScript are all served, so rebuilding the assets here is what reaches
 * a phone. Clients notice the build changed and reload themselves, which is the
 * difference between a deploy and a reinstall — and the only workable answer
 * when the device is not in the same building.
 *
 * Deliberately not a migration runner by default. Schema changes are the one
 * step that can lose data, and they should be a decision rather than a side
 * effect of shipping a CSS tweak.
 */
class Deploy extends Command
{
    protected $signature = 'soundchex:deploy
        {--migrate : Run database migrations as part of the deploy}
        {--no-backup : Skip the safety backup taken before migrating}';

    protected $description = 'Rebuild assets, refresh caches, and restart the queue';

    public function handle(): int
    {
        $this->line('');

        if ($this->option('migrate') && ! $this->option('no-backup')) {
            // Before the one step that can lose data. The media survives a lost
            // database but play history, watchlists and playlists do not.
            $this->step('Backing up first', fn () => $this->call('db:backup') === 0);
        }

        if (! $this->step('Building assets', fn () => $this->shell(['npm', 'run', 'build']))) {
            $this->error('The build failed, so nothing was deployed.');

            // Stopped here on purpose: a half-built manifest serves a page
            // referencing files that do not exist, which is worse than the
            // version already running.
            return self::FAILURE;
        }

        if ($this->option('migrate')) {
            $this->step('Migrating', fn () => $this->call('migrate', ['--force' => true]) === 0);
        }

        $this->step('Refreshing caches', function (): bool {
            $this->call('config:clear');
            $this->call('route:clear');
            $this->call('view:clear');

            return true;
        });

        // The worker holds the old code in memory, so a deploy it does not
        // restart for keeps running the previous version indefinitely.
        $this->step('Restarting the queue', fn () => $this->call('queue:restart') === 0);

        $this->newLine();
        $this->info('Deployed. Devices will pick it up within a minute, or the next time they are opened.');

        $this->line('  build ' . $this->buildId());

        if (! app(HostServices::class)->supported()) {
            return self::SUCCESS;
        }

        $stopped = collect(app(HostServices::class)->all())
            ->filter(fn (array $s): bool => $s['installed'] && ! $s['running'])
            ->keys();

        if ($stopped->isNotEmpty()) {
            $this->newLine();
            $this->warn('These services are stopped: ' . $stopped->implode(', '));
            $this->line('  Start them from Services in the admin panel.');
        }

        return self::SUCCESS;
    }

    private function step(string $label, callable $work): bool
    {
        $this->output->write(str_pad("  {$label}…", 34));

        $ok = (bool) $work();

        $this->line($ok ? '<fg=green>done</>' : '<fg=red>failed</>');

        return $ok;
    }

    private function shell(array $command): bool
    {
        $process = new Process($command, base_path(), timeout: 600);
        $process->run();

        if (! $process->isSuccessful() && $this->output->isVerbose()) {
            $this->line($process->getErrorOutput());
        }

        return $process->isSuccessful();
    }

    private function buildId(): string
    {
        $manifest = public_path('build/manifest.json');

        return is_file($manifest)
            ? substr(hash_file('sha1', $manifest), 0, 12)
            : 'unknown';
    }
}
