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

    /** Linux with Docker: the bridge is not a second LAN. Every such box warned. */
    public function test_container_and_vm_bridges_are_not_lans(): void
    {
        $interfaces = [
            'eth0' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '192.168.1.10', 'netmask' => '255.255.255.0']]],
            'docker0' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '172.17.0.1', 'netmask' => '255.255.0.0']]],
            'br-3f2a9c0d1e2b' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '172.18.0.1', 'netmask' => '255.255.0.0']]],
            'virbr0' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '192.168.122.1', 'netmask' => '255.255.255.0']]],
        ];

        $this->assertNull(DlnaServe::autoAddressDoubt('192.168.1.10', 'eth0', $interfaces));
    }

    /** Windows: no ptp flag and adapters listed while down; known by description. */
    public function test_windows_virtual_and_down_adapters_are_not_lans(): void
    {
        $interfaces = [
            '{A1}' => ['up' => true, 'description' => 'Intel(R) Wi-Fi 6 AX201', 'unicast' => [['family' => 2, 'address' => '192.168.1.20']]],
            '{B2}' => ['up' => true, 'description' => 'Hyper-V Virtual Ethernet Adapter', 'unicast' => [['family' => 2, 'address' => '172.29.16.1']]],
            '{C3}' => ['up' => true, 'description' => 'WireGuard Tunnel', 'unicast' => [['family' => 2, 'address' => '10.2.0.2']]],
            '{D4}' => ['up' => false, 'description' => 'Realtek PCIe GbE', 'unicast' => [['family' => 2, 'address' => '192.168.0.5']]],
        ];

        $this->assertNull(DlnaServe::autoAddressDoubt('192.168.1.20', '{A1}', $interfaces));
    }

    /** A real second LAN still warns: two physical networks, both up. */
    public function test_two_physical_lans_still_warn(): void
    {
        $interfaces = [
            'eth0' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '192.168.1.10']]],
            'wlan0' => ['up' => true, 'unicast' => [['family' => 2, 'address' => '192.168.50.1']]],
        ];

        $this->assertStringContainsString('several networks', DlnaServe::autoAddressDoubt('192.168.1.10', 'eth0', $interfaces));
    }

    /** No interface found for the guessed address: no deprecation, no crash. */
    public function test_a_guess_with_no_interface_is_handled(): void
    {
        $this->assertNull(DlnaServe::autoAddressDoubt('192.168.1.10', null, []));
    }

    /** HatCOS: searches from the uplink and the VPN are not answered. */
    public function test_searches_from_our_other_networks_are_foreign(): void
    {
        $router = $this->router();

        $this->assertTrue(DlnaServe::foreignSource('100.64.1.1', 'wlan0', $router));
        $this->assertTrue(DlnaServe::foreignSource('10.2.0.1', 'wlan0', $router), 'the tunnel peer');
        // Our own VPN address is a client on this machine; it can reach us.
        $this->assertFalse(DlnaServe::foreignSource('10.2.0.2', 'wlan0', $router), 'our own VPN address');
        $this->assertFalse(DlnaServe::foreignSource('192.168.50.77', 'wlan0', $router));
    }

    /**
     * A TV on an IoT VLAN, relayed in by an SSDP relay, used to be answered
     * and must still be. The first version of the filter dropped it.
     */
    public function test_relayed_searches_from_another_vlan_are_answered(): void
    {
        $this->assertFalse(DlnaServe::foreignSource('192.168.60.25', 'wlan0', $this->router()));
    }

    /** Loopback is not "another network"; a /32 does not swallow everything. */
    public function test_loopback_and_host_routes_do_not_filter(): void
    {
        $this->assertFalse(DlnaServe::foreignSource('127.0.0.1', 'wlan0', $this->router()));

        $hostRoute = [
            'eth0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.10', 'netmask' => '255.255.255.255']]],
            'eth1' => ['unicast' => [['family' => 2, 'address' => '192.168.2.10', 'netmask' => '255.255.255.255']]],
        ];

        $this->assertFalse(DlnaServe::foreignSource('192.168.1.77', 'eth0', $hostRoute));
        $this->assertFalse(DlnaServe::foreignSource('192.168.2.77', 'eth0', $hostRoute));
    }

    /** A /31 uplink's far end is another of our networks. */
    public function test_the_far_end_of_a_31_uplink_is_foreign(): void
    {
        $interfaces = [
            'wan0' => ['unicast' => [['family' => 2, 'address' => '203.0.113.10', 'netmask' => '255.255.255.254']]],
            'lan0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.1', 'netmask' => '255.255.255.0']]],
        ];

        $this->assertTrue(DlnaServe::foreignSource('203.0.113.11', 'lan0', $interfaces));
    }

    public function test_a_prefix_out_of_range_is_clamped_not_an_error(): void
    {
        $this->assertTrue(DlnaServe::inRange('10.0.0.1', '10.0.0.1/40'));
        $this->assertTrue(DlnaServe::inRange('8.8.8.8', '0.0.0.0/0'));
        $this->assertFalse(DlnaServe::inRange('10.0.0.2', '10.0.0.1/32'));
    }

    /**
     * The Windows fix: a join by name that fails falls back to the default
     * interface, and IP_MULTICAST_IF is then not given the unusable name.
     */
    public function test_a_failed_join_by_name_falls_back_to_the_default_interface(): void
    {
        $tried = [];
        $byName = DlnaServe::joinWithFallback('{GUID-1234}', function (string|int $on) use (&$tried): bool {
            $tried[] = $on;

            return $on === 0;
        });

        $this->assertFalse($byName);
        $this->assertSame(['{GUID-1234}', 0], $tried);
    }

    public function test_a_join_by_name_that_works_does_not_also_join_the_default(): void
    {
        // Joining 0 after a named join fails with EADDRINUSE on Linux.
        $tried = [];
        $byName = DlnaServe::joinWithFallback('eth0', function (string|int $on) use (&$tried): bool {
            $tried[] = $on;

            return true;
        });

        $this->assertTrue($byName);
        $this->assertSame(['eth0'], $tried);
    }

    public function test_no_interface_joins_the_default(): void
    {
        $tried = [];
        DlnaServe::joinWithFallback(null, function (string|int $on) use (&$tried): bool {
            $tried[] = $on;

            return true;
        });

        $this->assertSame([0], $tried);
    }

    /**
     * Another interface's subnet overlapping ours must not hide our own LAN.
     * Docker's 172.17/16 over a LAN on 172.17.5.x dropped every TV.
     */
    public function test_our_own_lan_wins_over_an_overlapping_subnet(): void
    {
        $interfaces = [
            'eth0' => ['unicast' => [['family' => 2, 'address' => '172.17.5.10', 'netmask' => '255.255.255.0']]],
            'docker0' => ['unicast' => [['family' => 2, 'address' => '172.17.0.1', 'netmask' => '255.255.0.0']]],
        ];

        $this->assertFalse(DlnaServe::foreignSource('172.17.5.20', 'eth0', $interfaces));
        // Outside our /24 but inside the bridge's /16: that one is foreign.
        $this->assertTrue(DlnaServe::foreignSource('172.17.9.9', 'eth0', $interfaces));
    }

    /** A /0 from a misreported netmask must not make everything foreign. */
    public function test_a_zero_netmask_on_another_interface_is_ignored(): void
    {
        $interfaces = [
            'eth0' => ['unicast' => [['family' => 2, 'address' => '192.168.1.10', 'netmask' => '255.255.255.0']]],
            'odd0' => ['unicast' => [['family' => 2, 'address' => '10.9.9.9', 'netmask' => '0.0.0.0']]],
        ];

        $this->assertFalse(DlnaServe::foreignSource('192.168.7.7', 'eth0', $interfaces));
    }

    /** OpenWrt's LAN bridge is a real LAN; Docker's are not. */
    public function test_br_lan_is_not_mistaken_for_a_docker_bridge(): void
    {
        $this->assertFalse(DlnaServe::isVirtual('br-lan', []));
        $this->assertTrue(DlnaServe::isVirtual('br-3f2a9c0d1e2b', []));
        $this->assertTrue(DlnaServe::isVirtual('docker0', []));
        $this->assertTrue(DlnaServe::isVirtual('ztabcdef12', []));
        $this->assertFalse(DlnaServe::isVirtual('bridge0', []), 'often a real Thunderbolt link on macOS');
        $this->assertFalse(DlnaServe::isVirtual('{X}', ['description' => 'Intel Ethernet (VPN-ready)']));
    }

    /** Wall time far ahead of monotonic time is a wake; a backwards step is not. */
    public function test_a_wake_from_sleep_is_noticed(): void
    {
        $this->assertTrue(DlnaServe::woke(3600, 1), 'an hour asleep');
        $this->assertFalse(DlnaServe::woke(1, 1), 'an ordinary second');
        $this->assertFalse(DlnaServe::woke(50, 1), 'a small NTP step forward');
        $this->assertFalse(DlnaServe::woke(-3600, 1), 'the clock stepped back');
    }

    /**
     * A wide LAN must not swallow a VPN or uplink inside it: the most
     * specific subnet decides. The own-subnet-first rule answered both.
     */
    public function test_a_wide_lan_does_not_swallow_a_narrower_other_network(): void
    {
        $interfaces = [
            'eth0' => ['unicast' => [['family' => 2, 'address' => '10.0.0.5', 'netmask' => '255.0.0.0']]],
            'tun0' => ['unicast' => [['family' => 2, 'address' => '10.2.0.2', 'netmask' => '255.255.255.255', 'ptp' => '10.2.0.1']]],
            'wan0' => ['unicast' => [['family' => 2, 'address' => '10.200.0.2', 'netmask' => '255.255.255.252']]],
        ];

        $this->assertTrue(DlnaServe::foreignSource('10.2.0.1', 'eth0', $interfaces), 'the VPN peer');
        $this->assertTrue(DlnaServe::foreignSource('10.200.0.1', 'eth0', $interfaces), 'the uplink peer');
        $this->assertFalse(DlnaServe::foreignSource('10.4.5.6', 'eth0', $interfaces), 'an ordinary LAN host');
    }

    /** Swarm's gateway bridge and macOS's VM bridges are virtual; bridge0 is not. */
    public function test_more_virtual_bridges_are_recognised(): void
    {
        $this->assertTrue(DlnaServe::isVirtual('docker_gwbridge', []));
        $this->assertTrue(DlnaServe::isVirtual('bridge100', []));
        $this->assertFalse(DlnaServe::isVirtual('bridge0', []));
        $this->assertTrue(DlnaServe::isVirtual('{E}', ['description' => 'Tailscale Tunnel']));
        $this->assertTrue(DlnaServe::isVirtual('{F}', ['description' => 'Wintun Userspace Tunnel']));
    }
}
