<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Console\Commands;

use App\Services\Dlna\DlnaSettings;
use App\Services\NetworkAddresses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * SSDP discovery for the DLNA server (S-7).
 *
 * The HTTP half of DLNA is ordinary Laravel routes, but discovery is not: a
 * device finds a media server by multicasting an M-SEARCH to 239.255.255.250
 * and listening for replies. That needs a UDP socket held open, which PHP-FPM
 * cannot do — hence a long-running process, supervised alongside the queue
 * worker and scheduler.
 *
 * Runs only when DLNA is switched on *and* pointed at a profile, and re-checks
 * that on every loop: switching it off in Settings stops the advertising
 * within a few seconds rather than needing the service restarted.
 *
 * Multicast never leaves the LAN, so nothing here is reachable from the
 * internet. The HTTP routes it advertises enforce that separately, because
 * they are reachable.
 */
class DlnaServe extends Command
{
    protected $signature = 'dlna:serve
        {--port= : The HTTP port to advertise (default: the port in SOUNDCHEX_LISTEN, else 8000)}
        {--address= : The LAN address to advertise and multicast on (default: DLNA_ADDRESS, else the default route\'s)}
        {--once : Answer what is waiting and exit, for testing}';

    protected $description = 'Announce the DLNA media server on the local network';

    private const MULTICAST_ADDRESS = '239.255.255.250';

    private const MULTICAST_PORT = 1900;

    /** How long a device should consider the advertisement valid. */
    private const CACHE_SECONDS = 1800;

    /**
     * How often to announce again while running: half the cache time, as the
     * SSDP spec advises. Announcing only once meant a device that listens
     * rather than searches dropped the server after half an hour.
     */
    private const REANNOUNCE_SECONDS = 900;

    /**
     * How often to re-read the DLNA settings. Every loop was once a second,
     * forever, plus once per packet on a busy network: two cache reads and a
     * profile query each time.
     */
    private const SETTINGS_SECONDS = 5;

    /** Linux's IP_MULTICAST_ALL; PHP has no constant for it. */
    private const LINUX_IP_MULTICAST_ALL = 49;

    public function handle(DlnaSettings $settings, NetworkAddresses $network): int
    {
        if (! extension_loaded('sockets')) {
            $this->error('The sockets extension is required for DLNA discovery.');

            return self::FAILURE;
        }

        $explicit = self::explicitAddress($this->option('address'), config('soundchex.dlna.address'));
        $interfaces = net_get_interfaces() ?: [];

        $problem = self::addressProblem($explicit, $interfaces);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $address = $explicit ?? $network->lanAddress();

        if ($address === null) {
            $this->error('No LAN address found — nothing to advertise. Pass --address or set DLNA_ADDRESS.');

            return self::FAILURE;
        }

        // The interface holding that address, so multicast goes out on the
        // network being advertised to. Joined on "interface 0" — whatever the
        // default route uses — a router announced itself on the wrong network
        // entirely, and never heard the searches from the right one.
        $interface = self::interfaceFor($address, $interfaces);

        // A guessed address on a machine with several networks is probably
        // the wrong one, and the result — no TV ever finds the server — says
        // nothing about why. Said once, loudly, with the fix.
        if ($explicit === null) {
            $doubt = self::autoAddressDoubt($address, $interface, $interfaces);

            if ($doubt !== null) {
                $this->warn("{$doubt} Set DLNA_ADDRESS (or --address) to the address of the network your TVs are on.");
                Log::warning('DLNA: the guessed address is probably the wrong network', [
                    'address' => $address,
                    'interface' => $interface,
                    'reason' => $doubt,
                ]);
            }
        }

        $subnet = $interface !== null ? self::subnetOf($address, $interfaces[$interface] ?? []) : null;

        $socket = $this->openSocket($interface);

        if ($socket === null) {
            return self::FAILURE;
        }

        $port = self::advertisedPort($this->option('port'), getenv('SOUNDCHEX_LISTEN') ?: null);
        $base = sprintf('http://%s:%d', $address, $port);

        $this->info("Advertising {$settings->friendlyName()} at {$base}".($interface ? " on {$interface}" : ''));

        $running = true;

        // Stop cleanly on the signals a supervisor sends, so the byebye goes
        // out and devices drop the entry rather than keeping a dead one.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$running): void {
                    $running = false;
                });
            }
        }

        // Announced while DLNA is on, and only then. It used to announce at
        // start whatever the setting, so a supervisor that runs this on every
        // machine advertised a server that then answered nothing. Switching
        // it on in Settings appears within a few seconds, switching it off
        // sends the byebye, and while on it announces again every fifteen
        // minutes (see `transition`).
        //
        // The first announcement matters: a device already listening learns
        // about the server without searching again, which for a TV left on is
        // the difference between appearing now and appearing in an hour.
        $announced = false;
        $announcedAt = 0;
        $wanted = false;
        $checkedAt = 0;

        while ($running) {
            $now = time();

            if ($now - $checkedAt >= self::SETTINGS_SECONDS) {
                $wanted = $this->wanted($settings);
                $checkedAt = $now;
            }

            $nts = self::transition($announced, $wanted, $now - $announcedAt);

            if ($nts !== null) {
                $this->notify($socket, $settings, $base, $nts);
                $announced = $nts === 'ssdp:alive';
                $announcedAt = $now;
            }

            $this->answerSearches($socket, $settings, $base, $wanted, $subnet);

            if ($this->option('once')) {
                break;
            }
        }

        if ($announced) {
            $this->notify($socket, $settings, $base, 'ssdp:byebye');
        }

        socket_close($socket);

        return self::SUCCESS;
    }

    /**
     * Whether DLNA should be advertised now. Never throws: a locked SQLite
     * file or an unmigrated database on first boot used to kill the process,
     * and the supervisor restarted it every two seconds into the same error.
     */
    private function wanted(DlnaSettings $settings): bool
    {
        try {
            return $settings->shouldRun();
        } catch (\Throwable $e) {
            Log::warning('DLNA: could not read the DLNA settings; not advertising', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * What to send now, given whether it is announced, whether it should be,
     * and how long since the last announcement: `ssdp:alive`, `ssdp:byebye`,
     * or null for nothing.
     */
    public static function transition(bool $announced, bool $wanted, int $sinceAnnounce): ?string
    {
        if ($wanted && (! $announced || $sinceAnnounce >= self::REANNOUNCE_SECONDS)) {
            return 'ssdp:alive';
        }

        if (! $wanted && $announced) {
            return 'ssdp:byebye';
        }

        return null;
    }

    /** `--address`, else DLNA_ADDRESS, as a trimmed string or null for "guess". */
    public static function explicitAddress(mixed $option, mixed $configured): ?string
    {
        foreach ([$option, $configured] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Why an explicitly chosen address cannot be used, or null.
     *
     * Checked before any socket opens: advertising an address the machine
     * does not have sends every device somewhere that never answers.
     *
     * @param  array<string, array<string, mixed>>  $interfaces  from `net_get_interfaces()`
     */
    public static function addressProblem(?string $explicit, array $interfaces): ?string
    {
        if ($explicit === null) {
            return null;
        }

        if (filter_var($explicit, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return "Not an IPv4 address: {$explicit}";
        }

        if (self::interfaceFor($explicit, $interfaces) === null) {
            return "No network interface has the address {$explicit}.";
        }

        return null;
    }

    /**
     * Why a guessed address is probably the wrong network, or null.
     *
     * The guess is the default route's address. On a machine that routes
     * between networks (HatCOS: Starlink, Wi-Fi, a VPN) that is the uplink or
     * the tunnel, not the LAN the TVs are on.
     *
     * @param  array<string, array<string, mixed>>  $interfaces  from `net_get_interfaces()`
     */
    public static function autoAddressDoubt(string $address, ?string $interface, array $interfaces): ?string
    {
        foreach ($interfaces[$interface] ?? [] as $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && ($entry['address'] ?? null) === $address && isset($entry['ptp'])) {
                    return "{$address} is on {$interface}, a point-to-point link (a VPN or tunnel).";
                }
            }
        }

        if (! self::isPrivateLan($address)) {
            return "{$address} is not a private LAN address.";
        }

        $lans = [];

        foreach ($interfaces as $name => $details) {
            foreach ($details['unicast'] ?? [] as $entry) {
                $candidate = $entry['address'] ?? null;

                // A tunnel is not a network a TV sits on, even with a private
                // address: a VPN's 10.x would otherwise make every machine
                // running one look like it had two LANs.
                if (isset($entry['ptp'])) {
                    continue;
                }

                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && self::isPrivateLan($candidate)) {
                    $lans[$name] = $candidate;
                }
            }
        }

        if (count($lans) > 1) {
            $list = implode(', ', array_map(fn ($name, $ip) => "{$name} {$ip}", array_keys($lans), $lans));

            return "Guessed {$address}, but this machine is on several networks ({$list}).";
        }

        return null;
    }

    /** RFC 1918 only: not loopback, link-local or CGNAT (100.64/10). */
    private static function isPrivateLan(string $address): bool
    {
        foreach (['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'] as $range) {
            if (self::inRange($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The interface's network for an address, as "network/prefix", or null
     * when `net_get_interfaces()` gave no netmask.
     *
     * @param  array<string, mixed>  $details  one interface from `net_get_interfaces()`
     */
    public static function subnetOf(string $address, array $details): ?string
    {
        foreach ($details['unicast'] ?? [] as $entry) {
            if (($entry['address'] ?? null) !== $address || ! is_string($entry['netmask'] ?? null)) {
                continue;
            }

            $mask = ip2long($entry['netmask']);
            $ip = ip2long($address);

            if ($mask === false || $ip === false) {
                return null;
            }

            $prefix = substr_count(decbin($mask & 0xFFFFFFFF), '1');

            return long2ip($ip & $mask).'/'.$prefix;
        }

        return null;
    }

    /** Whether an IPv4 address is inside "network/prefix". */
    public static function inRange(string $address, string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, '32');

        $ip = ip2long($address);
        $net = ip2long($network);

        if ($ip === false || $net === false) {
            return false;
        }

        $mask = (int) $prefix === 0 ? 0 : (~0 << (32 - (int) $prefix)) & 0xFFFFFFFF;

        return ($ip & $mask) === ($net & $mask);
    }

    /**
     * The HTTP port to advertise: `--port`, else the port Caddy was told to
     * listen on, else 8000.
     *
     * The default was 8200 while Caddy listens on 8000, and no service unit
     * passed a port, so every install advertised a port nothing answered on.
     * The supervisors already put SOUNDCHEX_LISTEN in this process's
     * environment, so following it keeps the two in step.
     */
    public static function advertisedPort(mixed $option, ?string $listen): int
    {
        if (is_numeric($option) && (int) $option > 0 && (int) $option < 65536) {
            return (int) $option;
        }

        if ($listen !== null && preg_match('/:(\d+)$/', trim($listen), $match) === 1) {
            $port = (int) $match[1];

            if ($port > 0 && $port < 65536) {
                return $port;
            }
        }

        return 8000;
    }

    /**
     * The interface holding an IPv4 address, from `net_get_interfaces()`.
     *
     * @param  array<string, array{unicast?: array<int, array{family?: int, address?: string}>}>  $interfaces
     */
    public static function interfaceFor(string $address, array $interfaces): ?string
    {
        foreach ($interfaces as $name => $details) {
            foreach ($details['unicast'] ?? [] as $entry) {
                if (($entry['address'] ?? null) === $address) {
                    return (string) $name;
                }
            }
        }

        return null;
    }

    /** The multicast socket, or null with the reason reported. */
    private function openSocket(?string $interface = null)
    {
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            $this->error('Could not create a UDP socket: '.socket_strerror(socket_last_error()));

            return null;
        }

        // Several media servers on one machine is normal — and a test suite
        // binding the same port is normal too.
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (! @socket_bind($socket, '0.0.0.0', self::MULTICAST_PORT)) {
            $this->error('Could not bind port '.self::MULTICAST_PORT.': '.socket_strerror(socket_last_error($socket)));
            socket_close($socket);

            return null;
        }

        // Joined on the chosen interface by name, falling back to the default
        // interface. The fallback is what keeps Windows working:
        // net_get_interfaces() names a Windows adapter by its GUID, which
        // if_nametoindex() does not accept, so the join by name fails there
        // and without it the server would never hear a search.
        $byName = $interface !== null && $this->join($socket, $interface);

        if (! $byName) {
            $this->join($socket, 0);
        }

        // Announcements leave on the chosen network rather than the default
        // route's — only where the interface's name was usable at all.
        if ($byName) {
            error_clear_last();

            if (! @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $interface)) {
                Log::warning('DLNA: could not send multicast on the chosen interface', [
                    'interface' => $interface,
                    'error' => $this->lastSocketError($socket),
                ]);
            }
        }

        // Linux delivers a group's traffic from every interface where any
        // process joined it, not only ours — so on a router running another
        // UPnP daemon, searches from the uplink or the VPN arrived here and
        // were answered with the LAN address. Off, it hears only its own
        // join. Not fatal: the subnet check in answerSearches covers it too.
        if (PHP_OS_FAMILY === 'Linux') {
            @socket_set_option($socket, IPPROTO_IP, self::LINUX_IP_MULTICAST_ALL, 0);
        }

        // A second of patience per read, so the loop can notice a stop signal
        // and a settings change rather than blocking forever on a quiet LAN.
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

        return $socket;
    }

    /**
     * Join the SSDP group on an interface (a name, or 0 for the default).
     * A failure is logged and is not fatal — replies to a search still work —
     * but a server that never hears searches looks exactly like one that is
     * off, so the reason is written down.
     */
    private function join($socket, string|int $interface): bool
    {
        error_clear_last();

        $joined = @socket_set_option($socket, IPPROTO_IP, MCAST_JOIN_GROUP, [
            'group' => self::MULTICAST_ADDRESS,
            'interface' => $interface,
        ]);

        if ($joined === false) {
            Log::warning('DLNA: could not join the SSDP multicast group', [
                'interface' => $interface === 0 ? 'default' : $interface,
                'error' => $this->lastSocketError($socket),
            ]);
        }

        return $joined !== false;
    }

    /**
     * What went wrong with the last socket call.
     *
     * PHP reports some failures — "No interface with name … could be found"
     * among them — as a warning, which `@` hides and which leaves the socket
     * error at 0. Logging only socket_strerror() then said "Undefined error:
     * 0", which explains nothing.
     */
    private function lastSocketError($socket): string
    {
        $code = socket_last_error($socket);

        if ($code !== 0) {
            return socket_strerror($code);
        }

        return error_get_last()['message'] ?? 'unknown error';
    }

    /**
     * Answers M-SEARCH requests that want a media server.
     *
     * A reply goes to the address the search came from, not to multicast: the
     * device asked, and broadcasting the answer to the whole network is noise
     * every other device has to parse.
     */
    private function answerSearches($socket, DlnaSettings $settings, string $base, bool $wanted, ?string $subnet): void
    {
        $buffer = '';
        $from = '';
        $port = 0;

        $bytes = @socket_recvfrom($socket, $buffer, 2048, 0, $from, $port);

        if ($bytes === false || $buffer === '') {
            return;
        }

        if (! str_starts_with($buffer, 'M-SEARCH')) {
            return;
        }

        // Switched off: no answer. Read from the loop's last check rather
        // than per packet, which on a busy network was a query per packet.
        if (! $wanted) {
            return;
        }

        // Only searches from the network being advertised to. One from the
        // uplink or a VPN would be answered with an address it cannot reach.
        if ($subnet !== null && ! self::inRange($from, $subnet)) {
            return;
        }

        $target = $this->searchTarget($buffer);

        if (! $this->wantsUs($target)) {
            return;
        }

        $response = $this->response($settings, $base, $target === 'ssdp:all' ? $this->deviceType() : $target);

        if (@socket_sendto($socket, $response, strlen($response), 0, $from, $port) === false) {
            Log::warning('DLNA: could not answer a search', [
                'to' => "{$from}:{$port}",
                'target' => $target,
                'error' => $this->lastSocketError($socket),
            ]);
        }
    }

    /** The ST header — what the device is looking for. */
    private function searchTarget(string $request): string
    {
        if (preg_match('/^ST:\s*(.+)$/mi', $request, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }

    /** Whether a search is one this server should answer. */
    private function wantsUs(string $target): bool
    {
        return in_array($target, [
            'ssdp:all',
            'upnp:rootdevice',
            $this->deviceType(),
            'urn:schemas-upnp-org:service:ContentDirectory:1',
        ], true);
    }

    private function deviceType(): string
    {
        return 'urn:schemas-upnp-org:device:MediaServer:1';
    }

    /** A unicast reply to one device's search. */
    private function response(DlnaSettings $settings, string $base, string $target): string
    {
        return $this->headers([
            'HTTP/1.1 200 OK',
            'CACHE-CONTROL: max-age='.self::CACHE_SECONDS,
            'DATE: '.gmdate('D, d M Y H:i:s \G\M\T'),
            'EXT:',
            'LOCATION: '.$base.'/dlna/device.xml',
            'SERVER: '.$this->serverHeader(),
            'ST: '.$target,
            'USN: uuid:'.$settings->uuid().'::'.$target,
        ]);
    }

    /**
     * The multicast NOTIFYs — "here I am" while on, "I am going" on stop.
     *
     * One per notification type a device may be watching for: the root
     * device, the device's UUID, its device type and its ContentDirectory
     * service. Only the device type went out before, and a client keyed on
     * `upnp:rootdevice` never saw the server announce itself.
     *
     * Each sent three times because SSDP is UDP over multicast and a dropped
     * packet means the server simply never appears; the spec expects the
     * redundancy.
     */
    private function notify($socket, DlnaSettings $settings, string $base, string $type): void
    {
        foreach (self::notifications($settings->uuid()) as [$nt, $usn]) {
            $message = $this->headers([
                'NOTIFY * HTTP/1.1',
                'HOST: '.self::MULTICAST_ADDRESS.':'.self::MULTICAST_PORT,
                'CACHE-CONTROL: max-age='.self::CACHE_SECONDS,
                'LOCATION: '.$base.'/dlna/device.xml',
                'NT: '.$nt,
                'NTS: '.$type,
                'SERVER: '.$this->serverHeader(),
                'USN: '.$usn,
            ]);

            for ($i = 0; $i < 3; $i++) {
                if (@socket_sendto($socket, $message, strlen($message), 0, self::MULTICAST_ADDRESS, self::MULTICAST_PORT) === false) {
                    Log::warning('DLNA: could not send an SSDP notification', [
                        'type' => $type,
                        'nt' => $nt,
                        'error' => $this->lastSocketError($socket),
                    ]);

                    return;
                }
            }
        }
    }

    /**
     * The NT and USN pairs a root device announces, per UPnP Device
     * Architecture 1.0 §1.1.2.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function notifications(string $uuid): array
    {
        $device = 'urn:schemas-upnp-org:device:MediaServer:1';
        $service = 'urn:schemas-upnp-org:service:ContentDirectory:1';

        return [
            ['upnp:rootdevice', "uuid:{$uuid}::upnp:rootdevice"],
            ["uuid:{$uuid}", "uuid:{$uuid}"],
            [$device, "uuid:{$uuid}::{$device}"],
            [$service, "uuid:{$uuid}::{$service}"],
        ];
    }

    private function serverHeader(): string
    {
        return sprintf(
            '%s/%s UPnP/1.0 SoundChex/%s',
            PHP_OS_FAMILY,
            php_uname('r'),
            config('soundchex.version', '0.1.0'),
        );
    }

    /** @param array<int, string> $lines */
    private function headers(array $lines): string
    {
        // CRLF and a blank line at the end: this is an HTTP-shaped message,
        // and devices that parse it strictly drop anything else.
        return implode("\r\n", $lines)."\r\n\r\n";
    }
}
