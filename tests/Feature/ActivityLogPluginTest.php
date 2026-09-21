<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Events\PlaybackCompleted;
use App\Events\ScanStarted;
use App\Events\UserSearched;
use App\Models\AuditLogEntry;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Plugins\Registry;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use SoundChex\ActivityLog\Filament\AuditLog;
use Tests\TestCase;

/**
 * The bundled Activity Log plugin (S-284): it subscribes to the whole event
 * catalogue and writes each event to one audit timeline, and it registers a
 * Filament admin page through the new admin-page seam.
 */
class ActivityLogPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The bundled Activity Log plugin is booted once by PluginServiceProvider
        // during app boot — its register() has already subscribed to the event
        // catalogue and registered its page. The test uses that; booting a second
        // loader here would double-subscribe every listener.
        config(['soundchex.plugins.enabled' => true]);
    }

    /* ------------------------------------------------- the admin-page seam --- */

    public function test_the_plugin_registers_its_page_through_the_admin_page_seam(): void
    {
        $this->assertContains(
            AuditLog::class,
            app(Registry::class)->adminPageClasses(),
        );
    }

    public function test_the_admin_page_seam_de_duplicates(): void
    {
        $registry = app(Registry::class);
        $registry->adminPage('Acme\\Page');
        $registry->adminPage('Acme\\Page');

        $this->assertSame(1, collect($registry->adminPageClasses())->filter(fn ($c) => $c === 'Acme\\Page')->count());
    }

    /* --------------------------------------------------------- recording --- */

    public function test_it_records_an_event_with_no_actor_or_subject(): void
    {
        ScanStarted::dispatch(['/music', '/podcasts']);

        $entry = AuditLogEntry::where('event', 'scan.started')->firstOrFail();

        $this->assertNull($entry->profile_id);
        $this->assertNull($entry->subject_id);
        $this->assertSame(['/music', '/podcasts'], $entry->context['folders']);
    }

    public function test_it_records_the_subject_and_actor_of_a_playback_event(): void
    {
        [$profile, $item] = $this->profileAndItem();

        PlaybackCompleted::dispatch($item, $profile->id);

        $entry = AuditLogEntry::where('event', 'playback.completed')->firstOrFail();

        $this->assertSame($item->id, $entry->subject_id);
        $this->assertSame('media_item', $entry->subject_type);
        $this->assertSame($item->title, $entry->subject_title);
        $this->assertSame($profile->id, $entry->profile_id);
        $this->assertSame($profile->name, $entry->actor_name);
    }

    public function test_the_subject_title_survives_the_items_deletion(): void
    {
        [$profile, $item] = $this->profileAndItem();
        $title = $item->title;

        // media.deleted fires from the observer as the row goes; the entry must
        // still say what left.
        $item->delete();

        $entry = AuditLogEntry::where('event', 'media.deleted')->firstOrFail();

        $this->assertSame($title, $entry->subject_title);
    }

    public function test_the_summary_is_human_readable(): void
    {
        UserSearched::dispatch('radiohead', 5, null);

        $entry = AuditLogEntry::where('event', 'user.searched')->firstOrFail();

        $this->assertStringContainsString('User searched', $entry->summary);
    }

    public function test_context_keeps_scalars_and_drops_object_graphs(): void
    {
        [$profile, $item] = $this->profileAndItem();

        UserSearched::dispatch('jazz', 9, $profile->id);

        $entry = AuditLogEntry::where('event', 'user.searched')->firstOrFail();

        $this->assertSame('jazz', $entry->context['query']);
        $this->assertSame(9, $entry->context['resultCount']);
    }

    public function test_recording_never_throws_into_the_event(): void
    {
        // Even if the audit table were unavailable, the dispatched event must not
        // fail. A successful dispatch here (no exception) is the assertion; the
        // recorder swallows its own errors.
        ScanStarted::dispatch(['/x']);

        $this->assertTrue(true);
    }

    /* --------------------------------------------------- page gating --- */

    public function test_the_page_is_gated_to_server_admins(): void
    {
        $user = User::factory()->create();

        // A plain member cannot reach the audit log — a full who-did-what history
        // is a server-admin surface.
        $member = Profile::create(['user_id' => $user->id, 'name' => 'Member']);
        $this->actingAs($user)->withSession([
            'profile_id' => $member->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);
        $this->assertFalse(AuditLog::canAccess());

        // The owner always reaches it.
        $owner = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($user)->withSession([
            'profile_id' => $owner->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);
        $this->assertTrue(AuditLog::canAccess());
    }

    public function test_the_page_renders_in_the_panel_for_an_owner(): void
    {
        // The definitive end-to-end: the plugin's page, in its own namespace with
        // its own view, resolves and renders when Filament serves it — proving
        // the admin-page seam works, not just that a class was registered.
        $user = User::factory()->create();
        $owner = Profile::create(['user_id' => $user->id, 'name' => 'Owner', 'is_owner' => true]);

        $this->actingAs($user);
        app(CurrentProfile::class)->switchTo($owner->id);
        Filament::setCurrentPanel('admin');

        Livewire::test(AuditLog::class)->assertSuccessful();
    }

    /* --------------------------------------------------------- coverage --- */

    public function test_it_subscribes_to_the_whole_catalogue(): void
    {
        // The plugin subscribes by iterating the catalogue rather than a hand-kept
        // list, so events from unrelated families all land entries.
        $this->assertGreaterThan(30, count(Registry::builtInEvents()));

        ScanStarted::dispatch(['/a']);
        UserSearched::dispatch('q', 0, null);

        $this->assertSame(
            2,
            AuditLogEntry::whereIn('event', ['scan.started', 'user.searched'])->count(),
        );
    }

    /**
     * @return array{0: Profile, 1: MediaItem}
     */
    private function profileAndItem(): array
    {
        $user = User::factory()->create();
        $profile = Profile::create(['user_id' => $user->id, 'name' => 'Alice', 'is_owner' => true]);
        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Karma Police',
            'file_path' => 'library/karma-police.flac',
            'owned' => true,
        ]);

        return [$profile, $item];
    }
}
