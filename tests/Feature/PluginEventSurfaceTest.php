<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Events\NotificationRecorded;
use App\Events\ScanFinished;
use App\Models\Notification;
use App\Models\User;
use App\Plugins\Registry;
use App\Services\LibraryScanner;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The expanded plugin event surface (S-276): the large catalogue is subscribable,
 * real lifecycle points dispatch, and a plugin can define and emit its own events
 * that other plugins observe.
 */
class PluginEventSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
    }

    /* --------------------------------------------------------- catalogue --- */

    public function test_the_catalogue_is_large_and_includes_the_new_events(): void
    {
        $events = Registry::builtInEvents();

        $this->assertGreaterThan(30, count($events));
        foreach (['scan.finished', 'transcode.finished', 'notification.recorded',
            'playback.completed', 'duplicate.detected', 'watchlist.added',
            'device.reported', 'user.searched'] as $name) {
            $this->assertContains($name, $events, "missing {$name}");
        }
    }

    /* ------------------------------------------------ built-in dispatches --- */

    public function test_recording_a_notification_dispatches_the_event(): void
    {
        Queue::fake();
        Event::fake([NotificationRecorded::class]);

        Notification::record(Notification::SCAN_FINISHED, 'Scan finished', '3 tracks');

        Event::assertDispatched(NotificationRecorded::class);
    }

    public function test_a_plugin_receives_scan_finished_with_its_counts(): void
    {
        Queue::fake();
        User::factory()->create(); // the scanner needs a user to assign to

        $seen = null;
        app(Registry::class)->on('scan.finished', function (ScanFinished $e) use (&$seen): void {
            $seen = $e->result;
        });

        $watched = Storage::disk('local')->path('watched');
        @mkdir($watched, 0755, true);
        file_put_contents($watched.'/Backrooms 2026.mkv', 'x');
        config()->set('library.watch_folders', [$watched]);
        app(SettingsService::class)->set('library_scan_storage', false);
        app(SettingsService::class)->set('library_settle_seconds', 0);

        app(LibraryScanner::class)->scan();

        $this->assertIsArray($seen);
        $this->assertArrayHasKey('imported', $seen);
    }

    /* ------------------------------------------- plugin-defined events --- */

    public function test_a_plugin_can_emit_a_custom_event_that_another_observes(): void
    {
        $received = null;

        // Plugin B subscribes to a name plugin A will define and emit.
        app(Registry::class)->forPlugin('acme.b', function (Registry $r) use (&$received): void {
            $r->on('acme.export.finished', function ($payload) use (&$received): void {
                $received = $payload;
            });
        });

        // Plugin A defines and fires it.
        app(Registry::class)->forPlugin('acme.a', function (Registry $r): void {
            $r->defineEvent('acme.export.finished', 'An export finished');
            $r->emit('acme.export.finished', ['file' => 'out.zip']);
        });

        $this->assertSame(['file' => 'out.zip'], $received);
    }

    public function test_a_defined_plugin_event_joins_the_catalogue(): void
    {
        $registry = app(Registry::class);
        $registry->forPlugin('acme.a', function (Registry $r): void {
            $r->defineEvent('acme.custom.thing');
        });

        $this->assertContains('acme.custom.thing', $registry->availableEvents());
        // ...and it does not pollute the app's built-in list.
        $this->assertNotContains('acme.custom.thing', Registry::builtInEvents());
    }

    public function test_emitting_with_no_subscribers_is_harmless(): void
    {
        app(Registry::class)->emit('acme.nobody.listens', 'x');

        $this->assertTrue(true); // no exception is the assertion
    }
}
