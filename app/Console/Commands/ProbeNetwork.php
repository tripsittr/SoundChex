<?php

namespace App\Console\Commands;

use App\Services\NetworkAddresses;
use Illuminate\Console\Command;

/**
 * Measures how reachable this server is, from its own vantage point.
 *
 * Out of band deliberately. `artisan serve` is single-threaded, so a request
 * made *while serving a request* waits on the process that would answer it —
 * the dashboard widget probed inline and reported "0 of 3 addresses answering,
 * clients cannot reach this server" about a server answering all three. The
 * scheduler runs this; the widget only reads what it left behind.
 */
class ProbeNetwork extends Command
{
    protected $signature = 'network:probe';

    protected $description = 'Measure how reachable this server is on each of its addresses';

    public function handle(NetworkAddresses $network): int
    {
        $results = $network->measure();

        if ($results === []) {
            $this->warn('No addresses to probe.');

            return self::SUCCESS;
        }

        foreach ($results as $row) {
            $this->line(sprintf(
                '  %-42s %s',
                $row['address'],
                $row['reachable'] ? $row['ms'] . ' ms' : 'no answer',
            ));
        }

        $reachable = collect($results)->where('reachable', true)->count();

        $this->info(sprintf('%d of %d answering.', $reachable, count($results)));

        return self::SUCCESS;
    }
}
