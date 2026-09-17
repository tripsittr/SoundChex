<?php

namespace App\Console\Commands;

use App\Services\EnvironmentFile;
use App\Services\NetworkAddresses;
use Illuminate\Console\Command;

/**
 * Sets APP_URL to this machine's best detected address.
 *
 * The desktop Server.app runs this on launch, so the downloaded app is
 * zero-config: it finds the machine's own LAN or tailnet address and advertises
 * that, rather than shipping a placeholder APP_URL that points nowhere (the
 * stale-`macbookair` problem). A self-hoster can run it by hand too.
 *
 * By default it only fills APP_URL when it is empty or still the framework
 * default, so it never overrides an address someone deliberately set (a public
 * tunnel, a reverse proxy). `--force` sets it regardless.
 */
class DetectServerAddress extends Command
{
    protected $signature = 'server:detect-address {--force : Overwrite an address that is already set}';

    protected $description = "Set APP_URL to this machine's detected LAN or tailnet address";

    public function handle(NetworkAddresses $network, EnvironmentFile $env): int
    {
        $current = $env->get('APP_URL');

        // Leave a deliberately-set address alone unless forced. "Deliberate"
        // means anything other than empty or Laravel's install default.
        $isPlaceholder = blank($current) || $current === 'http://localhost';

        if (! $isPlaceholder && ! $this->option('force')) {
            $this->info("APP_URL is already set to {$current}; leaving it. Use --force to change.");

            return self::SUCCESS;
        }

        $addresses = $network->detected();
        $best = $addresses[0] ?? null;

        if ($best === null) {
            $this->warn('No address could be detected. Set APP_URL manually in Server Settings.');

            return self::FAILURE;
        }

        $env->set(['APP_URL' => $best]);

        $this->info("APP_URL set to {$best} (config cache cleared).");

        return self::SUCCESS;
    }
}
