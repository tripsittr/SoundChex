<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Jobs\SendWebhookNotificationJob;
use App\Models\Notification;
use App\Services\SettingsService;
use App\Services\WebhookNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Server events reach the configured webhooks (S-262, S-263).
 */
class WebhookNotifierTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /* ------------------------------------------------- record dispatch --- */

    public function test_recording_a_notification_queues_delivery_when_a_hook_is_set(): void
    {
        Queue::fake();
        $this->settings()->set('webhook_discord_url', 'https://discord.test/hook');

        Notification::record(Notification::SCAN_FINISHED, 'Scan finished', '3 new tracks');

        Queue::assertPushed(SendWebhookNotificationJob::class);
    }

    public function test_no_delivery_is_queued_when_no_hook_is_set(): void
    {
        Queue::fake();

        Notification::record(Notification::SCAN_FINISHED, 'Scan finished');

        Queue::assertNotPushed(SendWebhookNotificationJob::class);
    }

    /* ---------------------------------------------------- destinations --- */

    public function test_it_posts_to_every_configured_destination(): void
    {
        Http::fake();
        $this->settings()->set('webhook_discord_url', 'https://discord.test/hook');
        $this->settings()->set('webhook_slack_url', 'https://slack.test/hook');
        $this->settings()->set('webhook_generic_url', 'https://ntfy.test/soundchex');

        $notification = Notification::record(Notification::SCAN_FINISHED, 'Scan finished', '3 new tracks');
        app(WebhookNotifier::class)->send($notification);

        Http::assertSent(fn ($r) => $r->url() === 'https://discord.test/hook' && isset($r->data()['embeds']));
        Http::assertSent(fn ($r) => $r->url() === 'https://slack.test/hook' && isset($r->data()['attachments']));
        Http::assertSent(fn ($r) => $r->url() === 'https://ntfy.test/soundchex' && ($r->data()['title'] ?? null) === 'Scan finished');
    }

    public function test_discord_gets_an_embed_with_the_title_and_body(): void
    {
        Http::fake();
        $this->settings()->set('webhook_discord_url', 'https://discord.test/hook');

        $notification = Notification::record(Notification::DOWNLOAD_FAILED, 'A download failed', 'Track 12');
        app(WebhookNotifier::class)->send($notification);

        Http::assertSent(function ($request) {
            $embed = $request->data()['embeds'][0] ?? [];

            return $embed['title'] === 'A download failed' && $embed['description'] === 'Track 12';
        });
    }

    public function test_a_dead_endpoint_does_not_throw(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        $this->settings()->set('webhook_generic_url', 'https://down.test/hook');

        $notification = Notification::record(Notification::SCAN_FINISHED, 'Scan finished');

        // Must not bubble up — a broken webhook can't break the scan.
        app(WebhookNotifier::class)->send($notification);

        $this->assertTrue(true);
    }

    public function test_test_returns_whether_the_endpoint_accepted_it(): void
    {
        Http::fake(['ok.test/*' => Http::response('', 200), 'bad.test/*' => Http::response('', 500)]);

        $notifier = app(WebhookNotifier::class);

        $this->assertTrue($notifier->test('generic', 'https://ok.test/hook'));
        $this->assertFalse($notifier->test('generic', 'https://bad.test/hook'));
    }
}
