<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Radarr, Sonarr and Lidarr, when they are running.
 *
 * These find new media; SoundChex catalogs what exists. They are joined at the
 * folder the scanner watches rather than through an API, which is why this
 * class only ever *reads*: it reports what the apps are doing so the admin
 * panel can show it, and nothing here queues a download or touches a file.
 *
 * **Not running is the normal case.** Most installs will not have Docker
 * started, let alone this stack, so every method answers with a shape rather
 * than throwing — an unreachable app is a fact to display, not an error. The
 * timeout is deliberately short for the same reason: a refused connection on
 * loopback is immediate, but a host that blackholes the port would otherwise
 * hang a page render for everyone who does not use this.
 */
class ArrServices
{
    /** Cache key prefix, so a stale answer can be dropped by name. */
    private const CACHE_PREFIX = 'arr:status:';

    public function __construct(private SettingsService $settings) {}

    /**
     * Every configured app and what it is currently doing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $out = [];

        foreach (array_keys(config('arr.apps', [])) as $name) {
            $out[$name] = $this->status($name);
        }

        return $out;
    }

    /**
     * One app's health, queue and version.
     *
     * @return array<string, mixed>
     */
    public function status(string $name): array
    {
        $app = config("arr.apps.{$name}");

        if ($app === null) {
            return $this->offline($name, 'Unknown app.');
        }

        $base = [
            'name' => $name,
            'label' => $app['label'],
            'kind' => $app['kind'],
            'url' => $this->url($name),
        ];

        $key = $this->apiKey($name);

        // Told apart deliberately. "No API key" is a setup step the user can
        // act on; "not running" is a different problem with a different fix,
        // and collapsing them into one message sends people to the wrong one.
        if ($key === null || $key === '') {
            return $base + $this->offline($name, 'No API key set.', needsKey: true);
        }

        return Cache::remember(
            self::CACHE_PREFIX . $name,
            (int) config('arr.cache_seconds', 15),
            fn (): array => $base + $this->probe($name, $this->url($name), $key, $app['api'] ?? 'v3'),
        );
    }

    /**
     * Asks one app how it is, in as few calls as will answer the question.
     *
     * @return array<string, mixed>
     */
    private function probe(string $name, string $url, string $key, string $api): array
    {
        try {
            $system = $this->get($url, $key, "/api/{$api}/system/status");

            if ($system === null) {
                return $this->offline($name, 'Not running.');
            }

            $queue = $this->get($url, $key, "/api/{$api}/queue", ['pageSize' => 1]);

            // Health warnings are the thing worth surfacing: an app that is up
            // but cannot reach its download client looks fine from outside and
            // silently does nothing.
            $health = $this->get($url, $key, "/api/{$api}/health") ?? [];

            return [
                'running' => true,
                'version' => $system['version'] ?? null,
                'queued' => $queue['totalRecords'] ?? 0,
                'warnings' => collect($health)
                    ->whereIn('type', ['warning', 'error'])
                    ->pluck('message')
                    ->values()
                    ->all(),
                'error' => null,
                'needs_key' => false,
            ];
        } catch (\Throwable $e) {
            // Logged rather than swallowed: "the page says it is not running
            // and it is" needs a cause, and a wrong API key looks identical to
            // a stopped container from the outside.
            Log::warning('Could not read an acquisition app', [
                'app' => $name,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return $this->offline($name, 'Not reachable.');
        }
    }

    /**
     * A single GET, returning null rather than throwing for the ordinary case
     * of nothing listening on the port.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private function get(string $url, string $key, string $path, array $query = []): ?array
    {
        $response = Http::timeout((int) config('arr.timeout', 3))
            ->withHeaders(['X-Api-Key' => $key])
            ->acceptJson()
            ->get(rtrim($url, '/') . $path, $query);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * The key, preferring the encrypted setting over the environment.
     *
     * Settings first because that is where the admin panel writes it and where
     * every other credential in this app lives; env only so a headless install
     * can be configured without opening a browser.
     */
    /**
     * Where this app actually lives.
     *
     * Settings first, then config, then the documented default. The default is
     * loopback because that is where our own compose stack puts it — but these
     * apps are installed a dozen other ways: natively through Homebrew, on a
     * Synology or unRAID box, in someone else's Docker stack, or on a different
     * machine entirely. None of those are on 127.0.0.1 from here, so the
     * address has to be editable without touching a file on the server.
     */
    public function url(string $name): string
    {
        $stored = $this->settings->get("arr.{$name}.url");

        if (is_string($stored) && $stored !== '') {
            return rtrim($stored, '/');
        }

        return rtrim((string) config("arr.apps.{$name}.url"), '/');
    }

    private function apiKey(string $name): ?string
    {
        $stored = $this->settings->get("arr.{$name}.api_key");

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fallback = config("arr.apps.{$name}.key");

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /** @return array<string, mixed> */
    private function offline(string $name, string $reason, bool $needsKey = false): array
    {
        return [
            'running' => false,
            'version' => null,
            'queued' => 0,
            'warnings' => [],
            'error' => $reason,
            'needs_key' => $needsKey,
        ];
    }

    /** Drops cached answers, so a page can show the effect of a change now. */
    public function forget(): void
    {
        foreach (array_keys(config('arr.apps', [])) as $name) {
            Cache::forget(self::CACHE_PREFIX . $name);
        }
    }
}
