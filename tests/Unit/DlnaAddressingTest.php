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

    /** The shape net_get_interfaces() returns on Linux, for a router like HatCOS. */
    private function router(): array
    {
        return [
            'lo' => ['unicast' => [['family' => 2, 'address' => '127.0.0.1', 'netmask' => '255.0.0.0']]],
            'eth0' => ['unicast' => [['family' => 2, 'address' => '100.64.1.20', 'netmask' => '255.192.0.0']]],
            'wlan0' => ['unicast' => [['family' => 2, 'address' => '192.168.50.1', 'netmask' => '255.255.255.0']]],
            'wg0' => ['unicast' => [['family' => 2, 'address' => '10.2.0.2', 'netmask' => '255.255.255.255', 'ptp' => '10.2.0.1']]],
        ];
    }

    /**
     * Refusals decided without opening a socket. They used to be tested by
     * running the command, which would have bound UDP 1900 on the developer's
     * real network the moment a refusal regressed.
     */
    public function test_an_unusable_explicit_address_is_refused(): void
    {
        $this->assertStringContainsString('Not an IPv4 address', DlnaServe::addressProblem('not-an-ip', $this->router()));
        $this->assertStringContainsString('Not an IPv4 address', DlnaServe::addressProblem('fe80::1', $this->router()));
        $this->assertStringContainsString('No network interface has the address 203.0.113.7', DlnaServe::addressProblem('203.0.113.7', $this->router()));
        $this->assertNull(DlnaServe::addressProblem('192.168.50.1', $this->router()));
        $this->assertNull(DlnaServe::addressProblem(null, $this->router()));
    }

    public function test_the_option_wins_over_configuration_and_blank_means_guess(): void
    {
        $this->assertSame('192.168.50.1', DlnaServe::explicitAddress('192.168.50.1', '10.0.0.5'));
        $this->assertSame('10.0.0.5', DlnaServe::explicitAddress(null, ' 10.0.0.5 '));
        $this->assertSame('10.0.0.5', DlnaServe::explicitAddress('', '10.0.0.5'));
        $this->assertNull(DlnaServe::explicitAddress(null, null));
        $this->assertNull(DlnaServe::explicitAddress('  ', ''));
    }

    /** The cases that made a router advertise the wrong network, silently. */
    public function test_a_guessed_address_on_the_wrong_network_is_doubted(): void
    {
        $this->assertStringContainsString('point-to-point', DlnaServe::autoAddressDoubt('10.2.0.2', 'wg0', $this->router()));
        $this->assertStringContainsString('not a private LAN address', DlnaServe::autoAddressDoubt('100.64.1.20', 'eth0', $this->router()));
    }

    public function test_a_guess_is_doubted_when_there_are_several_lans(): void
    {
        $interfaces = [
            'eth0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.10']]],
            'wlan0' => ['unicast' => [['family' => 2, 'address' => '192.168.50.1']]],
        ];

        $this->assertStringContainsString('several networks', DlnaServe::autoAddressDoubt('192.168.1.10', 'eth0', $interfaces));
    }

    /**
     * A VPN alongside one LAN is still one LAN. This Mac runs exactly that,
     * and the first version warned about "several networks" on it.
     */
    public function test_a_tunnel_does_not_count_as_a_second_lan(): void
    {
        $interfaces = [
            'en0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.155', 'netmask' => '255.255.255.0']]],
            'utun5' => ['unicast' => [['family' => 2, 'address' => '10.2.0.2', 'netmask' => '255.255.255.255', 'ptp' => '10.2.0.2']]],
        ];

        $this->assertNull(DlnaServe::autoAddressDoubt('192.168.1.155', 'en0', $interfaces));
    }

    /** One ordinary LAN: no warning to cry wolf on every home server. */
    public function test_an_ordinary_single_lan_guess_is_not_doubted(): void
    {
        $interfaces = [
            'lo0' => ['unicast' => [['family' => 2, 'address' => '127.0.0.1']]],
            'en0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.10'], ['family' => 30, 'address' => 'fe80::1']]],
        ];

        $this->assertNull(DlnaServe::autoAddressDoubt('192.168.1.10', 'en0', $interfaces));
    }

    public function test_announcing_follows_the_setting_and_repeats(): void
    {
        // Switched on: announce now.
        $this->assertSame('ssdp:alive', DlnaServe::transition(false, true, 0));
        // On and announced recently: nothing.
        $this->assertNull(DlnaServe::transition(true, true, 899));
        // On for fifteen minutes since the last: again, so a listening device
        // does not drop it when its thirty-minute cache runs out.
        $this->assertSame('ssdp:alive', DlnaServe::transition(true, true, 900));
        // Switched off while announced: say goodbye.
        $this->assertSame('ssdp:byebye', DlnaServe::transition(true, false, 10));
        // Off and never announced: silence, whatever the time.
        $this->assertNull(DlnaServe::transition(false, false, 5000));
    }

    public function test_every_notification_type_is_announced(): void
    {
        $sent = DlnaServe::notifications('abc');

        $this->assertSame([
            ['upnp:rootdevice', 'uuid:abc::upnp:rootdevice'],
            ['uuid:abc', 'uuid:abc'],
            ['urn:schemas-upnp-org:device:MediaServer:1', 'uuid:abc::urn:schemas-upnp-org:device:MediaServer:1'],
            ['urn:schemas-upnp-org:service:ContentDirectory:1', 'uuid:abc::urn:schemas-upnp-org:service:ContentDirectory:1'],
        ], $sent);
    }

    /** Searches from the uplink or the VPN are not answered with the LAN address. */
    public function test_searches_are_answered_only_from_the_advertised_network(): void
    {
        $subnet = DlnaServe::subnetOf('192.168.50.1', $this->router()['wlan0']);

        $this->assertSame('192.168.50.0/24', $subnet);
        $this->assertTrue(DlnaServe::inRange('192.168.50.77', $subnet));
        $this->assertFalse(DlnaServe::inRange('100.64.1.1', $subnet));
        $this->assertFalse(DlnaServe::inRange('10.2.0.1', $subnet));
        $this->assertFalse(DlnaServe::inRange('not-an-ip', $subnet));
    }

    /** No netmask to go on: no subnet, and so no filtering rather than dropping everything. */
    public function test_without_a_netmask_there_is_no_subnet_filter(): void
    {
        $this->assertNull(DlnaServe::subnetOf('192.168.1.10', ['unicast' => [['family' => 2, 'address' => '192.168.1.10']]]));
    }
}
