<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * artwork:refresh — scoping, per-album efficiency, and safety (S-258).
 */
class RefreshArtworkCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config()->set('library.cover_fetch_throttle_ms', 0);

        Http::fake([
            'itunes.apple.com/*' => Http::response(['results' => [
                ['artistName' => 'Twenty One Pilots', 'collectionName' => 'Blurryface',
                    'artworkUrl100' => 'https://example.test/bf-100x100.jpg'],
            ]]),
            'example.test/*' => Http::response('IMG', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    private function track(string $title, string $artist, string $album, ?string $cover): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'cover_image_url' => $cover,
            'owned' => true,
        ]);
        $item->musicMetadata()->create(['artist' => $artist, 'album' => $album]);

        return $item->fresh();
    }

    public function test_dry_run_makes_no_calls(): void
    {
        $this->track('Stressed Out', 'Twenty One Pilots', 'A Sky Full of Stars - Modern Pop', 'artwork/old.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'likely-wrong'])
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_likely_wrong_scope_targets_only_compilation_albums(): void
    {
        // Compilation-tagged → in scope.
        $bad = $this->track('Stressed Out', 'Twenty One Pilots', 'A Sky Full of Stars - Modern Pop', 'artwork/bad.jpg');
        // A real album → out of scope for likely-wrong.
        $good = $this->track('Ride', 'Twenty One Pilots', 'Blurryface', 'artwork/good.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'likely-wrong', '--force' => true])
            ->assertSuccessful();

        $this->assertStringStartsWith('artwork/covers/', $bad->fresh()->cover_image_url);
        $this->assertSame('artwork/good.jpg', $good->fresh()->cover_image_url);
    }

    public function test_missing_scope_targets_only_tracks_without_a_cover(): void
    {
        $missing = $this->track('No Art', 'Twenty One Pilots', 'Blurryface', null);
        $has = $this->track('Has Art', 'Twenty One Pilots', 'Blurryface', 'artwork/x.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'missing', '--force' => true])
            ->assertSuccessful();

        $this->assertStringStartsWith('artwork/covers/', $missing->fresh()->cover_image_url);
        $this->assertSame('artwork/x.jpg', $has->fresh()->cover_image_url);
    }

    public function test_it_never_touches_a_hand_picked_cover(): void
    {
        $manual = $this->track('Manual', 'Twenty One Pilots', 'Blurryface', 'media/covers/manual.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'all', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('media/covers/manual.jpg', $manual->fresh()->cover_image_url);
    }

    public function test_all_scope_shares_one_fetch_across_an_album(): void
    {
        $a = $this->track('Track A', 'Twenty One Pilots', 'Blurryface', 'artwork/a.jpg');
        $b = $this->track('Track B', 'Twenty One Pilots', 'Blurryface', 'artwork/b.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'all', '--force' => true])
            ->assertSuccessful();

        // Both tracks get the same shared cover file.
        $this->assertSame($a->fresh()->cover_image_url, $b->fresh()->cover_image_url);
        $this->assertStringStartsWith('artwork/covers/', $a->fresh()->cover_image_url);

        // One album → one search + one download.
        Http::assertSentCount(2);
    }

    public function test_a_track_with_no_online_match_keeps_its_existing_cover(): void
    {
        // The faked iTunes result is for "Twenty One Pilots"; this track's artist
        // is someone else, so no candidate matches → nothing fetched.
        $track = $this->track('Song', 'A Different Band', 'Some Album', 'artwork/keep.jpg');

        $this->artisan('artwork:refresh', ['--scope' => 'all', '--force' => true])
            ->assertSuccessful();

        // Artist mismatch → no cover fetched → existing left in place.
        $this->assertSame('artwork/keep.jpg', $track->fresh()->cover_image_url);
    }
}
