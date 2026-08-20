<?php

namespace App\Console\Commands;

use App\Models\DeviceReport;
use Illuminate\Console\Command;

/**
 * Shows device reports as they arrive.
 *
 * The admin panel lists them, but diagnosing a failure means reproducing it and
 * watching what comes back — and refreshing a page between every attempt is a
 * poor way to do that. This prints each report once, as it lands.
 */
class WatchDeviceReports extends Command
{
    protected $signature = 'soundchex:watch-reports
        {--seconds=2 : How often to look}
        {--all : Show what has already been reported before waiting}';

    protected $description = 'Print device diagnostics as they arrive';

    /** Kinds that describe something going wrong, coloured to stand out. */
    private const SERIOUS = ['error', 'rejection', 'served-offline-page'];

    private const NOTABLE = ['navigation-retry', 'switching-address', 'reloading-for-build'];

    public function handle(): int
    {
        $lastId = $this->option('all')
            ? 0
            : (DeviceReport::max('id') ?? 0);

        $this->line('');
        $this->info('Watching for device reports. Ctrl+C to stop.');

        if (! $this->option('all') && $lastId > 0) {
            $this->line("  <fg=gray>Ignoring {$lastId} already recorded; pass --all to see them.</>");
        }

        $this->line('');

        while (true) {
            $reports = DeviceReport::where('id', '>', $lastId)->orderBy('id')->get();

            foreach ($reports as $report) {
                $this->show($report);
                $lastId = $report->id;
            }

            // Polled rather than pushed: this is a development tool watching a
            // local database, and a queue or a socket would be more moving
            // parts than the problem deserves.
            sleep(max(1, (int) $this->option('seconds')));
        }
    }

    private function show(DeviceReport $report): void
    {
        $this->line(sprintf(
            '<fg=cyan>%s</> <fg=gray>%s · build %s</>',
            $report->platform ?: 'unknown device',
            substr($report->device, 0, 8),
            $report->build ?: '?',
        ));

        if (filled($report->origin)) {
            // The origin has explained more than one failure on its own: a
            // relayed address answers from across the country.
            $relayed = str_contains((string) $report->origin, '.ts.net');

            $this->line(sprintf(
                '  <fg=gray>via</> %s%s',
                $report->origin,
                $relayed ? ' <fg=yellow>(relayed)</>' : '',
            ));
        }

        foreach ($report->events ?? [] as $event) {
            $kind = $event['kind'] ?? 'unknown';

            $colour = match (true) {
                in_array($kind, self::SERIOUS, true) => 'red',
                in_array($kind, self::NOTABLE, true) => 'yellow',
                default => 'gray',
            };

            $detail = filled($event['detail'] ?? null)
                ? ' ' . json_encode($event['detail'])
                : '';

            $this->line(sprintf(
                '  <fg=gray>%6sms</> <fg=%s>%-22s</> <fg=gray>%s</>%s',
                $event['at'] ?? '?',
                $colour,
                $kind,
                $event['path'] ?? '',
                $detail,
            ));
        }

        $this->line('');
    }
}
