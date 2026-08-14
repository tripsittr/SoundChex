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

        $list = is_array($stored) ? $stored : json_decode((string) $stored, true);

        return is_array($list) ? array_values(array_filter($list)) : $this->detected();
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

        $this->settings->set(self::KEY, $clean);

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

        // The LAN address, from the interface actually carrying traffic.
        $lan = trim((string) @shell_exec("ipconfig getifaddr en0 2>/dev/null"));

        if ($lan !== '') {
            $found[] = "http://{$lan}:{$port}";
        }

        // The tailnet address, when Tailscale is up.
        $tailscale = trim((string) @shell_exec('tailscale ip -4 2>/dev/null'));

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
