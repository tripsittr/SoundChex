<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Events\MediaItemCatalogued;
use App\Events\MediaItemEnriched;
use App\Events\PlaybackRecorded;
use App\Models\MediaItem;
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
 * The Phase 3 extension surface (S-264): named events a plugin reacts to, and
 * filters a plugin uses to transform a value passing through the app.
 */
class PluginHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The scanner assigns imported items to a user; without one it imports
        // nothing (matching how the scanner tests set up).
        User::factory()->create();

        $this->app->forgetInstance(Registry::class);
        $this->app->singleton(Registry::class);
    }

    /* ------------------------------------------------------------ events --- */

    public function test_a_plugin_subscribes_to_an_event_by_its_friendly_name(): void
    {
        $seen = null;

        app(Registry::class)->forPlugin('acme.demo', function (Registry $r) use (&$seen): void {
            $r->on('media.enriched', function (MediaItemEnriched $event) use (&$seen): void {
                $seen = $event->item->id;
            });
        });

        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Track',
            'owned' => true,
        ]);

        MediaItemEnriched::dispatch($item);

        $this->assertSame($item->id, $seen);
    }

    public function test_cataloguing_a_file_fires_the_catalogued_event(): void
    {
        Queue::fake();

        // A real listener rather than Event::fake, so this also proves a plugin
        // subscription actually receives the scan's event end to end.
        $caught = [];
        app(Registry::class)->on('media.catalogued', function (MediaItemCatalogued $e) use (&$caught): void {
            $caught[] = $e->item->title;
        });

        $watched = Storage::disk('local')->path('watched');
        @mkdir($watched, 0755, true);
        file_put_contents($watched.'/Backrooms 2026.mkv', 'x');

        config()->set('library.watch_folders', [$watched]);
        app(SettingsService::class)->set('library_scan_storage', false);
        app(SettingsService::class)->set('library_settle_seconds', 0);

        $result = app(LibraryScanner::class)->scan();

        $this->assertSame(1, $result['imported'], 'The fixture film should have been catalogued.');
        $this->assertNotEmpty($caught, 'The catalogued event should have reached the listener.');
    }

    public function test_the_named_events_are_advertised(): void
    {
        $events = Registry::availableEvents();

        $this->assertContains(MediaItemCatalogued::NAME, $events);
        $this->assertContains(MediaItemEnriched::NAME, $events);
        $this->assertContains(PlaybackRecorded::NAME, $events);
    }

    /* ----------------------------------------------------------- filters --- */

    public function test_a_filter_transforms_the_value_and_chains_in_order(): void
    {
        $registry = app(Registry::class);

        $registry->forPlugin('acme.one', function (Registry $r): void {
            $r->filter('demo.value', fn (string $v): string => $v.'-one');
        });
        $registry->forPlugin('acme.two', function (Registry $r): void {
            $r->filter('demo.value', fn (string $v): string => $v.'-two');
        });

        // Filters run in registration order, each seeing the previous result.
        $this->assertSame('base-one-two', $registry->apply('demo.value', 'base'));
    }

    public function test_apply_returns_the_value_untouched_when_nothing_is_registered(): void
    {
        $this->assertSame('unchanged', app(Registry::class)->apply('nobody.listens', 'unchanged'));
    }

    public function test_a_throwing_filter_is_skipped_not_fatal(): void
    {
        $registry = app(Registry::class);

        $registry->filter('demo.value', function (): string {
            throw new \RuntimeException('boom');
        });
        $registry->filter('demo.value', fn (string $v): string => $v.'-ok');

        // The bad filter is swallowed; the good one still runs.
        $this->assertSame('base-ok', $registry->apply('demo.value', 'base'));
    }

    public function test_a_filter_receives_context_it_was_applied_with(): void
    {
        $registry = app(Registry::class);
        $captured = null;

        $registry->filter('demo.value', function (string $v, $context) use (&$captured): string {
            $captured = $context;

            return $v;
        });

        $registry->apply('demo.value', 'x', 'the-context');

        $this->assertSame('the-context', $captured);
    }
}
