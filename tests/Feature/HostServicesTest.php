<?php

namespace Tests\Feature;

use App\Services\HostServices;
use Tests\TestCase;

/**
 * Managing the processes a host needs running.
 *
 * All three have stopped silently: the web server, which shows on a phone as a
 * white screen with no explanation; the queue worker, whose absence is subtler
 * because the library appears to work while never finishing anything; and the
 * scheduler, without which nothing is scanned and no backup is taken.
 *
 * These deliberately do not load or unload anything. Doing so would stop the
 * machine's own server mid-test, and a test that takes down the thing running
 * it is worse than no test.
 */
class HostServicesTest extends TestCase
{
    public function test_it_knows_which_services_a_host_needs(): void
    {
        $services = app(HostServices::class)->all();

        $this->assertSame(
            ['serve', 'queue', 'scheduler'],
            array_keys($services),
        );
    }

    public function test_every_service_reports_a_state_and_a_reason(): void
    {
        foreach (app(HostServices::class)->all() as $key => $service) {
            $this->assertArrayHasKey('installed', $service, $key);
            $this->assertArrayHasKey('running', $service, $key);

            // Never a bare "stopped": the point of this screen is that a dead
            // service explains itself rather than being a mystery.
            $this->assertNotEmpty($service['detail'], $key);
            $this->assertNotEmpty($service['hint'], $key);
        }
    }

    public function test_every_service_has_a_plist_to_install(): void
    {
        foreach (HostServices::SERVICES as $key => $service) {
            $path = base_path('Documentation & Planning/' . $service['label'] . '.plist');

            // Installing looks for the file by label. A mismatch would fail at
            // the moment someone is trying to fix a dead server.
            $this->assertFileExists($path, "missing plist for {$key}");
        }
    }

    public function test_an_unknown_service_is_refused(): void
    {
        $host = app(HostServices::class);

        $this->assertFalse($host->start('not-a-service'));
        $this->assertFalse($host->stop('not-a-service'));
        $this->assertFalse($host->install('not-a-service'));
    }

    public function test_support_is_reported_honestly(): void
    {
        // launchd is macOS only, and claiming otherwise would offer buttons
        // that silently do nothing.
        $this->assertSame(
            PHP_OS_FAMILY === 'Darwin',
            app(HostServices::class)->supported(),
        );
    }

    public function test_a_log_can_be_read_for_each_service(): void
    {
        foreach (array_keys(HostServices::SERVICES) as $key) {
            // Never empty: a service that will not start says why here, and
            // the only alternative is a terminal — which is what the screen
            // exists to avoid.
            $this->assertNotEmpty(app(HostServices::class)->log($key), $key);
        }
    }

    public function test_reading_a_log_for_an_unknown_service_is_empty(): void
    {
        $this->assertSame('', app(HostServices::class)->log('not-a-service'));
    }

    public function test_the_log_is_tailed_rather_than_read_whole(): void
    {
        // ~/Library/Logs, where the agents write: the repository sits under
        // ~/Documents, which macOS gates behind TCC, and a launchd job told to
        // log there is dropped before its program runs.
        $path = ($_SERVER['HOME'] ?? getenv('HOME')) . '/Library/Logs/SoundChex/serve.log';
        $existing = is_file($path) ? file_get_contents($path) : null;

        // The folder is always there on macOS and never on Windows, where the
        // test errored outright rather than failing — one of the six red
        // results that made a real regression indistinguishable from noise.
        // The tailing being tested is not macOS-specific, so it is worth
        // running everywhere rather than skipped.
        $created = ! is_dir(dirname($path)) && @mkdir(dirname($path), 0755, true);

        try {
            // A hundred lines written, ten asked for.
            file_put_contents($path, implode("\n", array_map(
                fn (int $i): string => "line {$i}",
                range(1, 100),
            )));

            $log = app(HostServices::class)->log('serve', 10);

            $this->assertStringContainsString('line 100', $log);
            $this->assertStringNotContainsString('line 1' . "\n", $log);
            $this->assertLessThanOrEqual(10, substr_count($log, "\n") + 1);
        } finally {
            // Put back exactly what was there, including nothing. Leaving a
            // hundred lines of "line 42" in a real log the user may later read
            // is test data left behind, which this project asks us not to do.
            if ($existing !== null) {
                file_put_contents($path, $existing);
            } else {
                @unlink($path);
            }

            if ($created) {
                @rmdir(dirname($path));
            }
        }
    }
}
