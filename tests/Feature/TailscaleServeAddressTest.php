<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\NetworkAddresses;
use Tests\TestCase;

/**
 * Advertising the address that actually works.
 *
 * The raw tailnet address needs an inbound firewall rule for the port, and on
 * Windows there is none — the installer runs per-user and cannot elevate to add
 * one, so `netsh advfirewall` refuses. The server answered on loopback, the
 * address list offered `http://100.x.x.x:8000`, and every other device on the
 * tailnet was refused: indistinguishable from a server that does not work.
 *
 * A `tailscale serve` front end has no such problem. tailscaled is already a
 * privileged service, so it accepts the connection itself and forwards it to
 * loopback — nothing needs opening — and it brings a real certificate and a
 * hostname rather than an IP over plain http.
 *
 * The output's shape is the only thing here that can break, so that is what is
 * tested. The fixtures are verbatim from a real tailnet.
 */
class TailscaleServeAddressTest extends TestCase
{
    private function parse(string $output, int $port = 8000): ?string
    {
        return app(NetworkAddresses::class)->parseServeStatus($output, $port);
    }

    public function test_it_reads_the_front_end_url(): void
    {
        // Verbatim from `tailscale serve status` on a real tailnet.
        $output = "https://a5.tail7e590c.ts.net (tailnet only)\n|-- / proxy http://127.0.0.1:8000\n";

        $this->assertSame('https://a5.tail7e590c.ts.net', $this->parse($output));
    }

    /** Nothing configured is not an address. */
    public function test_no_serve_configuration_gives_nothing(): void
    {
        $this->assertNull($this->parse(''));
        $this->assertNull($this->parse("\n  \n"));
    }

    /**
     * Another service on the same tailnet is not ours.
     *
     * A tailnet can serve several things. Handing a client somebody else's URL
     * is worse than handing it nothing, because it looks like it worked.
     */
    public function test_a_front_end_for_another_port_is_ignored(): void
    {
        $output = "https://a5.tail7e590c.ts.net (tailnet only)\n|-- / proxy http://127.0.0.1:3000\n";

        $this->assertNull($this->parse($output));
    }

    /** The right one is picked out from among several. */
    public function test_it_finds_ours_among_several_front_ends(): void
    {
        $output = "https://nas.tail7e590c.ts.net (tailnet only)\n"
            ."|-- / proxy http://127.0.0.1:5000\n"
            ."https://a5.tail7e590c.ts.net (tailnet only)\n"
            ."|-- / proxy http://127.0.0.1:8000\n";

        $this->assertSame('https://a5.tail7e590c.ts.net', $this->parse($output));
    }

    /**
     * A sub-path front end is refused.
     *
     * Every absolute link this app writes starts at the root, so a URL that
     * only serves `/soundchex` would break each one of them.
     */
    public function test_a_sub_path_front_end_is_refused(): void
    {
        $output = "https://a5.tail7e590c.ts.net (tailnet only)\n|-- /soundchex proxy http://127.0.0.1:8000\n";

        $this->assertNull($this->parse($output));
    }

    /** localhost is the same place as 127.0.0.1. */
    public function test_localhost_counts_as_loopback(): void
    {
        $output = "https://a5.tail7e590c.ts.net (tailnet only)\n|-- / proxy http://localhost:8000\n";

        $this->assertSame('https://a5.tail7e590c.ts.net', $this->parse($output));
    }

    /** A trailing slash is not part of the address. */
    public function test_the_url_has_no_trailing_slash(): void
    {
        $output = "https://a5.tail7e590c.ts.net/ (tailnet only)\n|-- / proxy http://127.0.0.1:8000\n";

        $this->assertSame('https://a5.tail7e590c.ts.net', $this->parse($output));
    }

    /**
     * And it is offered ahead of the raw tailnet address.
     *
     * Order is the whole point: clients take the first address that answers,
     * and the raw one does not answer from another device.
     */
    public function test_the_serve_url_outranks_the_raw_tailnet_address(): void
    {
        $addresses = app(NetworkAddresses::class)->detected();

        $serve = array_search('https://a5.tail7e590c.ts.net', $addresses, true);
        $raw = null;

        foreach ($addresses as $i => $address) {
            if (str_starts_with($address, 'http://100.')) {
                $raw = $i;
                break;
            }
        }

        if ($serve === false || $raw === null) {
            $this->markTestSkipped('This machine has no tailscale serve front end to compare.');
        }

        $this->assertLessThan($raw, $serve, 'The serve URL must come before the address a firewall blocks.');
    }
}
