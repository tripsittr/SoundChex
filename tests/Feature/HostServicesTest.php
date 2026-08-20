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
}
