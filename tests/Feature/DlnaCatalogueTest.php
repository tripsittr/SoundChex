<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\Dlna\DlnaCatalogue;
use App\Services\Dlna\DlnaSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the DLNA server shows the LAN (S-7).
 *
 * DLNA cannot ask who is browsing, so one configured profile stands for every
 * device on the network. That makes the gating here the only thing between a
 * rating cap and a television — worth its own tests rather than trusting that
 * the shared gate is wired up.
 */
class DlnaCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function movie(string $title, ?string $rating): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => '/tmp/'.str($title)->slug().'.mp4',
            'owned' => true,
        ]);

        $item->movieMetadata()->create(['mpaa_rating' => $rating]);

        return $item->fresh();
    }

    private function track(string $title): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/tmp/'.str($title)->slug().'.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }

    private function serveAs(?Profile $profile): DlnaCatalogue
    {
        app(SettingsService::class)->set(DlnaSettings::PROFILE, $profile?->id);

        return app(DlnaCatalogue::class);
    }

    private function profile(string $name, ?string $cap): Profile
    {
        return Profile::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'is_owner' => $cap === null,
            'max_rating' => $cap,
        ]);
    }

    public function test_the_root_lists_a_section_per_kind_of_media(): void
    {
        $this->track('A Song');
        $this->movie('A Film', 'PG');

        $root = $this->serveAs($this->profile('Owner', null))->browse(DlnaCatalogue::ROOT);

        $this->assertSame(['music', 'movies'], array_column($root['containers'], 'id'));
    }

    public function test_an_empty_section_is_not_offered(): void
    {
        // On a TV remote, opening a folder to find nothing costs several
        // presses to discover.
        $this->track('A Song');

        $root = $this->serveAs($this->profile('Owner', null))->browse(DlnaCatalogue::ROOT);

        $this->assertSame(['music'], array_column($root['containers'], 'id'));
    }

    public function test_the_configured_profiles_rating_cap_is_enforced(): void
    {
        // The whole reason DLNA is opt-in and profile-bound: a television
        // cannot say who is watching.
        $this->movie('Gentle', 'G');
        $this->movie('Grown Up', 'R');

        $kid = $this->profile('Kid', 'PG');

        $browse = $this->serveAs($kid)->browse('movies');

        $this->assertSame(['Gentle'], $browse['items']->pluck('title')->all());
        $this->assertSame(1, $browse['total']);
    }

    public function test_a_capped_profile_cannot_reach_a_blocked_item_by_id(): void
    {
        // Browsing is only half of it — a client that remembers an id from
        // another session must not be able to fetch it.
        $blocked = $this->movie('Grown Up', 'R');

        $this->assertNull($this->serveAs($this->profile('Kid', 'PG'))->find($blocked->id));
    }

    public function test_an_item_with_no_file_is_never_listed(): void
    {
        // A DLNA client handed a dead URL reports a device error, not a
        // missing file.
        $this->track('Playable');
        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Ghost',
            'file_path' => null,
            'owned' => false,
        ]);

        $browse = $this->serveAs($this->profile('Owner', null))->browse('music');

        $this->assertSame(['Playable'], $browse['items']->pluck('title')->all());
    }

    public function test_paging_is_stable_and_reports_the_true_total(): void
    {
        foreach (['C', 'A', 'B'] as $title) {
            $this->track($title);
        }

        $catalogue = $this->serveAs($this->profile('Owner', null));

        $first = $catalogue->browse('music', offset: 0, limit: 2);
        $second = $catalogue->browse('music', offset: 2, limit: 2);

        $this->assertSame(['A', 'B'], $first['items']->pluck('title')->all());
        $this->assertSame(['C'], $second['items']->pluck('title')->all());
        // The total is the whole section, not the page.
        $this->assertSame(3, $first['total']);
    }

    public function test_an_unknown_container_is_empty_rather_than_an_error(): void
    {
        $browse = $this->serveAs($this->profile('Owner', null))->browse('nonsense');

        $this->assertSame(0, $browse['total']);
        $this->assertTrue($browse['items']->isEmpty());
    }
}
