<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\Notification;
use App\Plugins\Contracts\NotificationTarget;
use App\Plugins\Registry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends server events out to Discord, Slack and generic webhook targets (S-262,
 * S-263).
 *
 * One notifier, three destinations, each a URL the admin pastes on the
 * Integrations page. A destination formats the same event to suit itself —
 * Discord an embed, Slack an attachment, a generic hook plain JSON that ntfy,
 * Apprise, Gotify and the like accept. Every destination is opt-in and off until
 * a URL is saved.
 *
 * Wired into `Notification::record()`, so anything that records an in-app
 * notification also reaches the configured webhooks with nothing extra to call.
 */
class WebhookNotifier
{
    /** Destination setting keys → their kind, for formatting and the UI. */
    public const DESTINATIONS = [
        'webhook_discord_url' => 'discord',
        'webhook_slack_url' => 'slack',
        'webhook_generic_url' => 'generic',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Whether any webhook destination is configured — a cheap gate so
     * `Notification::record()` does no work when nothing is set up.
     */
    public function hasDestinations(): bool
    {
        foreach (array_keys(self::DESTINATIONS) as $key) {
            if (filled($this->settings->get($key))) {
                return true;
            }
        }

        // A plugin may add its own targets (S-264, #281); if any is configured,
        // there is work to do even with no built-in webhook set.
        foreach ($this->pluginTargets() as $target) {
            if ($target->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends one recorded notification to every configured destination.
     *
     * Failures are logged and swallowed: a webhook that is down or a URL that
     * has rotated must never break the scan or download that triggered it.
     */
    public function send(Notification $notification): void
    {
        foreach (self::DESTINATIONS as $key => $kind) {
            $url = $this->settings->get($key);

            if (blank($url)) {
                continue;
            }

            $this->deliver($kind, (string) $url, $notification->title, $notification->body);
        }

        // Then any target a plugin contributed (S-264, #281). Each is resolved
        // through the container and asked to send; a target that throws is
        // logged and skipped, so one plugin cannot break the others or the
        // event that recorded this.
        foreach ($this->pluginTargets() as $target) {
            if (! $target->isConfigured()) {
                continue;
            }

            try {
                $target->send($notification);
            } catch (\Throwable $e) {
                Log::warning('A plugin notification target failed', [
                    'target' => $target->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The plugin notification targets, resolved through the container.
     *
     * @return array<int, NotificationTarget>
     */
    private function pluginTargets(): array
    {
        $targets = [];

        foreach (app(Registry::class)->notificationTargetClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $target = app($class);

            if ($target instanceof NotificationTarget) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Sends a one-off test message to a single destination — the "Send test"
     * button on the Integrations page. Returns whether it was accepted.
     */
    public function test(string $kind, string $url): bool
    {
        return $this->deliver(
            $kind,
            $url,
            'SoundChex test notification',
            'If you can see this, webhooks are working.',
        );
    }

    private function deliver(string $kind, string $url, string $title, ?string $body): bool
    {
        try {
            $response = Http::timeout(10)->post($url, $this->payload($kind, $title, $body));

            if ($response->successful()) {
                return true;
            }

            Log::warning('Webhook notification was rejected', [
                'kind' => $kind,
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Webhook notification failed to send', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * The body each service wants for the same event.
     *
     * @return array<string, mixed>
     */
    private function payload(string $kind, string $title, ?string $body): array
    {
        return match ($kind) {
            'discord' => [
                'username' => 'SoundChex',
                'embeds' => [array_filter([
                    'title' => $title,
                    'description' => $body,
                    'color' => 0xE11D3A, // the SoundChex accent
                ], fn ($v) => $v !== null)],
            ],
            'slack' => [
                'text' => $title,
                'attachments' => $body === null ? [] : [[
                    'text' => $body,
                    'color' => '#e11d3a',
                ]],
            ],
            // Generic: a flat shape ntfy/Apprise/Gotify and custom endpoints read.
            default => array_filter([
                'title' => $title,
                'message' => $body,
                'source' => 'soundchex',
            ], fn ($v) => $v !== null),
        };
    }
}
