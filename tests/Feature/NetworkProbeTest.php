<?php

namespace Tests\Feature;

use App\Jobs\ProbeNetworkJob;
use App\Services\NetworkAddresses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Measuring the server's reachability without blocking it.
 *
 * `artisan serve` is single-threaded, so an HTTP request made while serving a
 * request waits on the process that would answer it. The dashboard probed
 * inline and reported "0 of 3 addresses answering, clients cannot reach this
 * server" about a server answering all three — the measurement caused the
 * failure it reported.
 *
 * These pin the shape that fixes it: reads never probe, and probing happens
 * somewhere that is not a web request.
 */
class NetworkProbeTest extends TestCase
{
    // The address list is read from the settings table.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(NetworkAddresses::PROBE_KEY);
    }

    public function test_reading_the_probe_makes_no_requests(): void
    {
        // The whole bug in one assertion: if this ever issues a request again,
        // the dashboard goes back to deadlocking against its own server.
        Http::fake();

        app(NetworkAddresses::class)->probe();

        Http::assertNothingSent();
    }

    public function test_an_unmeasured_probe_is_empty_rather_than_failing(): void
    {
        // Distinguishable from "measured, and nothing answered" — the widget
        // shows those differently, and saying the second when the first is
        // true trains people to ignore the dashboard.
        $network = app(NetworkAddresses::class);

        $this->assertFalse($network->probed());
        $this->assertSame([], $network->probe());
    }

    public function test_measuring_records_what_answered(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $results = app(NetworkAddresses::class)->measure();

        $this->assertNotEmpty($results);

        foreach ($results as $row) {
            $this->assertTrue($row['reachable'], $row['address'] . ' answered');
            $this->assertIsInt($row['ms']);
        }

        $this->assertTrue(app(NetworkAddresses::class)->probed());
    }

    public function test_an_address_that_does_not_answer_is_recorded_as_such(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        foreach (app(NetworkAddresses::class)->measure() as $row) {
            $this->assertFalse($row['reachable']);
        }
    }

    public function test_a_measured_result_is_read_back_without_remeasuring(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        app(NetworkAddresses::class)->measure();

        // A second reader must not re-probe: that is what made every dashboard
        // load pay for its own measurement.
        Http::fake();
        $results = app(NetworkAddresses::class)->probe();

        Http::assertNothingSent();
        $this->assertNotEmpty($results);
    }

    public function test_the_job_measures(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        app(ProbeNetworkJob::class)->handle(app(NetworkAddresses::class));

        $this->assertTrue(app(NetworkAddresses::class)->probed());
    }
}
