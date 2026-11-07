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

    public function handle(DlnaSettings $settings, NetworkAddresses $network): int
    {
        if (! extension_loaded('sockets')) {
            $this->error('The sockets extension is required for DLNA discovery.');

            return self::FAILURE;
        }

        $explicit = $this->option('address') ?: config('soundchex.dlna.address');

        if ($explicit !== null && $explicit !== '' && filter_var($explicit, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $this->error("Not an IPv4 address: {$explicit}");

            return self::FAILURE;
        }

        $address = $explicit ?: $network->lanAddress();

        if ($address === null) {
            $this->error('No LAN address found — nothing to advertise. Pass --address or set DLNA_ADDRESS.');

            return self::FAILURE;
        }

        // The interface holding that address, so multicast goes out on the
        // network being advertised to. Joined on "interface 0" — whatever the
        // default route uses — a router announced itself on the wrong network
        // entirely, and never heard the searches from the right one.
        $interface = self::interfaceFor($address, net_get_interfaces() ?: []);

        if ($interface === null && $explicit) {
            $this->error("No network interface has the address {$explicit}.");

            return self::FAILURE;
        }

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
        // machine advertised a server that then answered nothing. Checked each
        // loop, so switching it on in Settings appears within a second or two
        // and switching it off sends the byebye.
        //
        // The first announcement matters: a device already listening learns
        // about the server without searching again, which for a TV left on is
        // the difference between appearing now and appearing in an hour.
        $announced = false;

        while ($running) {
            $wanted = $settings->shouldRun();

            if ($wanted !== $announced) {
                $this->notify($socket, $settings, $base, $wanted ? 'ssdp:alive' : 'ssdp:byebye');
                $announced = $wanted;
            }

            $this->answerSearches($socket, $settings, $base);

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

        $joined = @socket_set_option($socket, IPPROTO_IP, MCAST_JOIN_GROUP, [
            'group' => self::MULTICAST_ADDRESS,
            'interface' => $interface ?? 0,
        ]);

        if ($joined === false) {
            // Not fatal: replies to a search still work without it. But a
            // server that never hears searches looks exactly like one that is
            // off, so the reason is written down.
            Log::warning('DLNA: could not join the SSDP multicast group', [
                'interface' => $interface ?? 'default',
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
        }

        // Announcements leave on the chosen network rather than the default
        // route's.
        if ($interface !== null && ! @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $interface)) {
            Log::warning('DLNA: could not send multicast on the chosen interface', [
                'interface' => $interface,
                'error' => socket_strerror(socket_last_error($socket)),
            ]);
        }

        // A second of patience per read, so the loop can notice a stop signal
        // and a settings change rather than blocking forever on a quiet LAN.
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);

        return $socket;
    }

    /**
     * Answers M-SEARCH requests that want a media server.
     *
     * A reply goes to the address the search came from, not to multicast: the
     * device asked, and broadcasting the answer to the whole network is noise
     * every other device has to parse.
     */
    private function answerSearches($socket, DlnaSettings $settings, string $base): void
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

        // Switched off since the last search: stop answering immediately
        // rather than at the next restart.
        if (! $settings->shouldRun()) {
            return;
        }

        $target = $this->searchTarget($buffer);

        if (! $this->wantsUs($target)) {
            return;
        }

        $response = $this->response($settings, $base, $target === 'ssdp:all' ? $this->deviceType() : $target);

        @socket_sendto($socket, $response, strlen($response), 0, $from, $port);
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
     * A multicast NOTIFY — "here I am" on start, "I am going" on stop.
     *
     * Sent three times because SSDP is UDP over multicast and a dropped packet
     * means the server simply never appears; the spec expects the redundancy.
     */
    private function notify($socket, DlnaSettings $settings, string $base, string $type): void
    {
        $message = $this->headers([
            'NOTIFY * HTTP/1.1',
            'HOST: '.self::MULTICAST_ADDRESS.':'.self::MULTICAST_PORT,
            'CACHE-CONTROL: max-age='.self::CACHE_SECONDS,
            'LOCATION: '.$base.'/dlna/device.xml',
            'NT: '.$this->deviceType(),
            'NTS: '.$type,
            'SERVER: '.$this->serverHeader(),
            'USN: uuid:'.$settings->uuid().'::'.$this->deviceType(),
        ]);

        for ($i = 0; $i < 3; $i++) {
            if (@socket_sendto($socket, $message, strlen($message), 0, self::MULTICAST_ADDRESS, self::MULTICAST_PORT) === false) {
                Log::warning('DLNA: could not send an SSDP notification', [
                    'type' => $type,
                    'error' => socket_strerror(socket_last_error($socket)),
                ]);

                return;
            }
        }
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
