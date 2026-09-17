<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * How long was listened, as distinct from where playback got to.
 *
 * `position_seconds` is a bookmark and is overwritten on every save, so it
 * cannot answer "how long did this hold your attention" — a track played twice
 * to 3:00 looks identical to one played once. And plays × duration counts a
 * ten-second skip as a full listen, which is the number a statistics page must
 * not quietly get wrong.
 *
 * The seek guard is the part worth testing hardest: without it, one drag of
 * the scrubber to the end banks the whole track as listened, and every chart
 * built on the column is wrong in the flattering direction.
 */
class ListeningTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    private MediaItem $track;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
        ]);

        $this->track = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => '/tmp/a-song.mp3',
            'owned' => true,
        ]);
    }

    /** Posts a position the way the player does. */
    private function report(int $position, int $duration = 300)
    {
        return $this->actingAs($this->user)
            ->withSession([
                'profile_id' => $this->profile->id,
                'profile_unlocked_at' => now()->timestamp,
            ])
            ->postJson("/app/item/{$this->track->id}/progress", [
                'position' => $position,
                'duration' => $duration,
            ]);
    }

    private function listened(): ?int
    {
        return $this->track->plays()->latest('id')->first()?->listened_seconds;
    }

    public function test_playing_forward_accumulates_time(): void
    {
        $this->report(10)->assertOk();
        $this->report(20)->assertOk();
        $this->report(30)->assertOk();

        // Three ten-second steps, and the first counts from zero.
        $this->assertSame(30, $this->listened());
    }

    public function test_a_seek_forward_is_not_counted_as_listening(): void
    {
        // The regression this guard exists for: dragging the scrubber from the
        // start to near the end would otherwise bank four and a half minutes
        // of "listening" nobody did.
        $this->report(10)->assertOk();
        $this->report(280)->assertOk();

        $this->assertSame(10, $this->listened());
    }

    public function test_seeking_backwards_does_not_subtract(): void
    {
        $this->report(60)->assertOk();
        $this->report(10)->assertOk();

        // Still 60 — replaying part of a track is not negative listening, and
        // a naive delta would make the total go down.
        $this->assertSame(60, $this->listened());
    }

    public function test_listening_after_a_seek_still_counts(): void
    {
        $this->report(10)->assertOk();
        $this->report(280)->assertOk();  // the seek, not counted
        $this->report(290)->assertOk();  // playing on from there

        $this->assertSame(20, $this->listened());
    }

    public function test_a_generous_step_still_counts(): void
    {
        // A slow request over a relay, or a webview suspended for a minute,
        // is still listening — the cap is deliberately well above the
        // player's own 10-second report interval.
        $this->report(80)->assertOk();

        $this->assertSame(80, $this->listened());
    }

    public function test_position_is_still_the_bookmark(): void
    {
        // The two columns answer different questions and must not converge.
        $this->report(10)->assertOk();
        $this->report(280)->assertOk();

        $play = $this->track->plays()->latest('id')->first();

        $this->assertSame(280, $play->position_seconds, 'resume goes where playback got to');
        $this->assertSame(10, $play->listened_seconds, 'listening counts only what was played');
    }

    public function test_a_play_is_attributed_to_the_profile(): void
    {
        // 84% of existing rows have no profile_id, because `recordPlay()`
        // stamped only the account. A household shares one login, so a
        // per-person statistic reading those rows was reading one sixth of
        // the data.
        $this->report(10)->assertOk();

        $this->assertSame(
            $this->profile->id,
            $this->track->plays()->latest('id')->first()->profile_id,
        );
    }
}
