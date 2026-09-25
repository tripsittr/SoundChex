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
        {--port=8200 : The HTTP port to advertise}
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

        $address = $network->lanAddress();

        if ($address === null) {
            $this->error('No LAN address found — nothing to advertise.');

            return self::FAILURE;
        }

        $socket = $this->openSocket();

        if ($socket === null) {
            return self::FAILURE;
        }

        $base = sprintf('http://%s:%d', $address, (int) $this->option('port'));

        $this->info("Advertising {$settings->friendlyName()} at {$base}");

        // Announce on start. A device that was already listening learns about
        // the server without waiting to search again, which for a TV left on
        // is the difference between appearing now and appearing in an hour.
        $this->notify($socket, $settings, $base, 'ssdp:alive');

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

        while ($running) {
            $this->answerSearches($socket, $settings, $base);

            if ($this->option('once')) {
                break;
            }
        }

        $this->notify($socket, $settings, $base, 'ssdp:byebye');

        socket_close($socket);

        return self::SUCCESS;
    }

    /** The multicast socket, or null with the reason reported. */
    private function openSocket()
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

        @socket_set_option($socket, IPPROTO_IP, MCAST_JOIN_GROUP, [
            'group' => self::MULTICAST_ADDRESS,
            'interface' => 0,
        ]);

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
