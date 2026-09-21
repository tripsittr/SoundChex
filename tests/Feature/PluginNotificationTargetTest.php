<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\Notification;
use App\Plugins\Contracts\NotificationTarget;
use App\Plugins\Registry;
use App\Services\WebhookNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A plugin notification target receives every recorded notification alongside
 * the built-in webhook destinations (S-264, #281).
 */
class PluginNotificationTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // record() auto-dispatches the delivery job when destinations exist; fake
        // the queue so it does not fire, and the test drives send() directly.
        Queue::fake();

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
        Cache::forget('plugin-test.notified');
    }

    public function test_a_configured_target_receives_the_notification(): void
    {
        app(Registry::class)->forPlugin('acme.notify', function (Registry $r): void {
            $r->notificationTarget(RecordingTarget::class);
        });

        $notification = Notification::record(Notification::SCAN_FINISHED, 'Scan finished', '3 tracks');
        app(WebhookNotifier::class)->send($notification);

        $this->assertSame(['Scan finished'], Cache::get('plugin-test.notified', []));
    }

    public function test_an_unconfigured_target_is_skipped(): void
    {
        app(Registry::class)->forPlugin('acme.notify', function (Registry $r): void {
            $r->notificationTarget(UnconfiguredTarget::class);
        });

        $notification = Notification::record(Notification::SCAN_FINISHED, 'Scan finished');
        app(WebhookNotifier::class)->send($notification);

        $this->assertSame([], Cache::get('plugin-test.notified', []));
    }

    public function test_has_destinations_is_true_when_only_a_plugin_target_is_configured(): void
    {
        // No built-in webhook set, but a plugin target is configured.
        app(Registry::class)->forPlugin('acme.notify', function (Registry $r): void {
            $r->notificationTarget(RecordingTarget::class);
        });

        $this->assertTrue(app(WebhookNotifier::class)->hasDestinations());
    }

    public function test_a_throwing_target_does_not_break_the_send(): void
    {
        app(Registry::class)->forPlugin('acme.notify', function (Registry $r): void {
            $r->notificationTarget(ExplodingTarget::class);
            $r->notificationTarget(RecordingTarget::class);
        });

        $notification = Notification::record(Notification::SCAN_FINISHED, 'Scan finished');

        // Must not throw, and the good target still runs.
        app(WebhookNotifier::class)->send($notification);

        $this->assertSame(['Scan finished'], Cache::get('plugin-test.notified', []));
    }
}

class RecordingTarget implements NotificationTarget
{
    public function name(): string
    {
        return 'Recording';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Notification $notification): void
    {
        $seen = Cache::get('plugin-test.notified', []);
        $seen[] = $notification->title;
        Cache::forever('plugin-test.notified', $seen);
    }
}

class UnconfiguredTarget implements NotificationTarget
{
    public function name(): string
    {
        return 'Unconfigured';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function send(Notification $notification): void
    {
        $seen = Cache::get('plugin-test.notified', []);
        $seen[] = $notification->title;
        Cache::forever('plugin-test.notified', $seen);
    }
}

class ExplodingTarget implements NotificationTarget
{
    public function name(): string
    {
        return 'Exploding';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(Notification $notification): void
    {
        throw new \RuntimeException('boom');
    }
}
