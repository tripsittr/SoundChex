<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The addresses this server can be reached on.
 *
 * A self-hosted library is reachable several ways at once — over the LAN, over
 * a VPN, and through a public tunnel — and they are not remotely equivalent.
 * Measured on one real setup: 22ms over Tailscale, 53ms over the LAN, and
 * 782ms through a Tailscale Funnel relay routing via Los Angeles.
 *
 * A client that knows only one of those is stuck with whichever was typed
 * first, which is how an app on the same wifi as its server ends up waiting
 * three quarters of a second for every page. Stored here so the server can
 * tell every client what the alternatives are, rather than each device having
 * to be configured by hand.
 */
class NetworkAddresses
{
    private const KEY = 'network_addresses';

    public function __construct(private SettingsService $settings) {}

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        $stored = $this->settings->get(self::KEY);

        if (blank($stored)) {
            return $this->detected();
        }

        // Tolerates both shapes: a value stored before this was encoded, and
        // the JSON written since.
        $list = is_array($stored) ? $stored : json_decode((string) $stored, true);

        if (! is_array($list) || $list === []) {
            return $this->detected();
        }

        // Merged with what the machine currently reports, rather than returned
        // as stored. A LAN address is only true until the server joins a
        // different network — moving between wifi and a hotspot changes it —
        // and the stored list kept handing out the old one while never
        // mentioning the new one, so every client was pointed at an address
        // that no longer existed. Detected addresses come first because they
        // are the ones known to be current.
        return array_values(array_unique([
            ...$this->detected(),
            ...array_filter($list),
        ]));
    }

    /**
     * @param array<int, string> $addresses
     */
    public function save(array $addresses): void
    {
        $clean = collect($addresses)
            ->map(fn ($address) => $this->normalise((string) $address))
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Encoded, because a Setting holds a string: the column is encrypted
        // per-value and the mutator is typed ?string, so handing it an array
        // throws — which made every save fail rather than storing anything.
        $this->settings->set(self::KEY, json_encode($clean));

        Cache::forget('network_addresses_probe');
    }

    /**
     * Addresses the server can work out for itself.
     *
     * A starting point rather than an answer: the machine knows its own LAN
     * address and, if Tailscale is running, its tailnet one. It cannot know
     * the public tunnel URL, because that is configured outside the app.
     *
     * @return array<int, string>
     */
    public function detected(): array
    {
        $port = (int) parse_url(config('app.url'), PHP_URL_PORT) ?: 8000;
        $found = [];

        $lan = $this->lanAddress();

        if ($lan !== null) {
            $found[] = "http://{$lan}:{$port}";
        }

        // The tailnet address, when Tailscale is up.
        // Found by path rather than name. A web server started by launchd has
        // a minimal PATH that does not include Homebrew, so `tailscale` was
        // simply not found — and the tailnet address, the one route that is
        // both fast and survives the machine changing networks, was silently
        // dropped from the list every client reads.
        // NUL rather than /dev/null on Windows, or cmd creates a file called
        // "dev" and the command still writes its error to the page.
        $quiet = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';

        $tailscale = trim((string) @shell_exec($this->tailscaleBinary() . ' ip -4 ' . $quiet));

        if ($tailscale !== '') {
            $found[] = 'http://' . strtok($tailscale, "\n") . ":{$port}";
        }

        // Whatever the app is configured to call itself — usually the public
        // one, and the only one a device outside the house can use.
        if (filled(config('app.url'))) {
            $found[] = rtrim((string) config('app.url'), '/');
        }

        return array_values(array_unique($found));
    }

    /**
     * Where the tailscale command actually is.
     *
     * Checked in the usual places rather than trusted to PATH, which under
     * launchd contains none of them.
     */
    /**
     * The address of the interface actually carrying traffic.
     *
     * Asked of the operating system rather than derived, because a machine has
     * several addresses and only one of them is the one other devices can
     * reach: a VPN adapter, a virtual switch and a disconnected ethernet port
     * all have addresses that would be advertised and never answer.
     */
    private function lanAddress(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // PowerShell rather than ipconfig: parsing ipconfig's output means
            // parsing whatever language Windows is installed in.
            $found = trim((string) @shell_exec(
                'powershell -NoProfile -Command "'
                . '(Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -ne $null '
                . '-and $_.NetAdapter.Status -eq \'Up\' } '
                . '| Select-Object -First 1).IPv4Address.IPAddress" 2>NUL'
            ));

            return $found === '' ? null : $found;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $found = trim((string) @shell_exec("ip route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}'"));

            return $found === '' ? null : $found;
        }

        $found = trim((string) @shell_exec('ipconfig getifaddr en0 2>/dev/null'));

        return $found === '' ? null : $found;
    }

    private function tailscaleBinary(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ([
                'C:\\Program Files\\Tailscale\\tailscale.exe',
                'C:\\Program Files (x86)\\Tailscale\\tailscale.exe',
            ] as $candidate) {
                if (is_file($candidate)) {
                    return '"' . $candidate . '"';
                }
            }

            return 'tailscale';
        }

        foreach ([
            '/opt/homebrew/bin/tailscale',
            '/usr/local/bin/tailscale',
            '/Applications/Tailscale.app/Contents/MacOS/Tailscale',
            // Linux packages install here.
            '/usr/bin/tailscale',
        ] as $candidate) {
            if (is_executable($candidate)) {
                return escapeshellarg($candidate);
            }
        }

        return 'tailscale';
    }

    /**
     * How each address performs, from the server's own vantage point.
     *
     * Cached briefly: this makes a request per address, and a dashboard widget
     * that reprobes on every page load would add its own latency to the thing
     * it is measuring.
     *
     * @return array<int, array{address: string, ms: int|null, reachable: bool}>
     */
    public function probe(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('network_addresses_probe');
        }

        return Cache::remember('network_addresses_probe', now()->addMinutes(2), function (): array {
            return collect($this->all())
                ->map(function (string $address): array {
                    $started = microtime(true);

                    try {
                        $response = Http::timeout(5)
                            ->withoutVerifying()
                            ->get($address . '/login');

                        return [
                            'address' => $address,
                            'ms' => (int) round((microtime(true) - $started) * 1000),
                            'reachable' => $response->successful() || $response->redirect(),
                        ];
                    } catch (\Throwable) {
                        return ['address' => $address, 'ms' => null, 'reachable' => false];
                    }
                })
                ->sortBy(fn (array $row) => $row['ms'] ?? PHP_INT_MAX)
                ->values()
                ->all();
        });
    }

    /**
     * The quickest address that answered, or null when none did.
     */
    public function fastest(): ?array
    {
        return collect($this->probe())->firstWhere('reachable', true);
    }

    /**
     * Accepts what a private address actually looks like.
     *
     * http is fine on a LAN or a VPN — those hosts have no certificate and
     * need none — so requiring https would rule out the fast routes entirely.
     */
    private function normalise(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $isPrivate = (bool) preg_match(
            '#^(https?://)?(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.)#',
            $trimmed,
        );

        $withScheme = preg_match('#^https?://#i', $trimmed) === 1
            ? $trimmed
            : ($isPrivate ? 'http://' : 'https://') . $trimmed;

        $parts = parse_url($withScheme);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (($parts['scheme'] ?? '') !== 'https' && ! $isPrivate) {
            return null;
        }

        return rtrim($withScheme, '/');
    }
}
