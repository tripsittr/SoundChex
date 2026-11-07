<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Console\Commands\DlnaServe;
use App\Services\NetworkAddresses;
use PHPUnit\Framework\TestCase;

/**
 * Where DLNA tells devices to come back to (S-7).
 *
 * Each of these was wrong in a way that advertised a server nobody could
 * reach: field 7 of `ip route get` read as the address when it was the uid,
 * port 8200 advertised while Caddy listened on 8000, and the default route's
 * interface used on a machine routing between several networks.
 */
class DlnaAddressingTest extends TestCase
{
    public function test_the_route_source_is_read_with_a_gateway(): void
    {
        $this->assertSame(
            '192.168.1.5',
            NetworkAddresses::routeSource("1.1.1.1 via 192.168.1.1 dev eth0 src 192.168.1.5 uid 1000 \n    cache \n"),
        );
    }

    /** No `via`: field 7 is the uid, which was advertised as the address. */
    public function test_the_route_source_is_read_without_a_gateway(): void
    {
        $this->assertSame(
            '10.2.0.2',
            NetworkAddresses::routeSource("1.1.1.1 dev wg0 table 51820 src 10.2.0.2 uid 0 \n    cache \n"),
        );
    }

    public function test_no_route_is_no_address(): void
    {
        $this->assertNull(NetworkAddresses::routeSource(''));
        $this->assertNull(NetworkAddresses::routeSource('RTNETLINK answers: Network is unreachable'));
        $this->assertNull(NetworkAddresses::routeSource('1.1.1.1 dev eth0 src notanaddress uid 0'));
    }

    public function test_the_advertised_port_follows_the_listen_address(): void
    {
        $this->assertSame(8000, DlnaServe::advertisedPort(null, ':8000'));
        $this->assertSame(8443, DlnaServe::advertisedPort(null, '0.0.0.0:8443'));
        $this->assertSame(9000, DlnaServe::advertisedPort(null, '[::]:9000'));
    }

    public function test_an_explicit_port_wins(): void
    {
        $this->assertSame(8200, DlnaServe::advertisedPort('8200', ':8000'));
    }

    /** Caddy's own default, not the 8200 nothing listened on. */
    public function test_with_nothing_said_the_port_is_caddys_default(): void
    {
        $this->assertSame(8000, DlnaServe::advertisedPort(null, null));
        $this->assertSame(8000, DlnaServe::advertisedPort('', 'nonsense'));
        $this->assertSame(8000, DlnaServe::advertisedPort('0', ':0'));
        $this->assertSame(8000, DlnaServe::advertisedPort('70000', ':99999'));
    }

    public function test_the_interface_is_found_by_its_address(): void
    {
        // The shape net_get_interfaces() returns, for a router like HatCOS.
        $interfaces = [
            'lo' => ['unicast' => [['family' => 17], ['family' => 2, 'address' => '127.0.0.1']]],
            'eth0' => ['unicast' => [['family' => 2, 'address' => '100.64.1.20']]],
            'wlan0' => ['unicast' => [['family' => 2, 'address' => '192.168.50.1'], ['family' => 10, 'address' => 'fe80::1']]],
            'wg0' => ['unicast' => [['family' => 2, 'address' => '10.2.0.2']]],
        ];

        $this->assertSame('wlan0', DlnaServe::interfaceFor('192.168.50.1', $interfaces));
        $this->assertSame('wg0', DlnaServe::interfaceFor('10.2.0.2', $interfaces));
        $this->assertNull(DlnaServe::interfaceFor('192.168.1.99', $interfaces));
        $this->assertNull(DlnaServe::interfaceFor('192.168.50.1', []));
    }
}
