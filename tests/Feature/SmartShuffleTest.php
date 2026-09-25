<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\Profile;
use App\Models\User;
use App\Services\SmartShuffle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shuffle that leans towards what a profile listens to (S-289).
 *
 * The thing worth testing is the *tendency*, not any one queue: a weighted
 * draw is random by design, so an assertion on exact contents would be
 * asserting on a seed. These run the draw repeatedly and check the bias.
 */
class SmartShuffleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);
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

    private function play(MediaItem $item, int $times = 1, int $daysAgo = 0): void
    {
        foreach (range(1, $times) as $ignored) {
            $play = MediaPlay::create([
                'media_item_id' => $item->id,
                'user_id' => $this->user->id,
                'profile_id' => $this->profile->id,
                'position_seconds' => 0,
            ]);

            // forceFill, because created_at is not fillable — passing it to
            // create() is silently dropped and every play lands as "now",
            // which makes a recency test assert nothing at all.
            if ($daysAgo > 0) {
                $play->forceFill([
                    'created_at' => now()->subDays($daysAgo),
                    'updated_at' => now()->subDays($daysAgo),
                ])->saveQuietly();
            }
        }
    }

    /** How often each track appears across many draws. */
    private function frequencies(int $runs = 40, int $limit = 10): array
    {
        $counts = [];

        foreach (range(1, $runs) as $ignored) {
            foreach (app(SmartShuffle::class)->queue($this->profile->id, $limit) as $item) {
                $counts[$item->title] = ($counts[$item->title] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function test_a_played_track_turns_up_more_often_than_one_never_played(): void
    {
        $loved = $this->track('Loved');
        $this->play($loved, times: 10);

        foreach (range(1, 30) as $n) {
            $this->track("Filler {$n}");
        }

        $counts = $this->frequencies();

        $fillerAverage = collect($counts)
            ->filter(fn ($_, string $title) => str_starts_with($title, 'Filler'))
            ->avg() ?? 0;

        $this->assertGreaterThan(
            $fillerAverage * 2,
            $counts['Loved'] ?? 0,
            'A track played ten times should clearly outrank untouched ones.',
        );
    }

    public function test_a_recent_play_outranks_an_old_one_of_the_same_count(): void
    {
        // Something played ten times last week is a better guess than
        // something played ten times a year ago.
        $recent = $this->track('Recent');
        $stale = $this->track('Stale');
        $this->play($recent, times: 10, daysAgo: 1);
        $this->play($stale, times: 10, daysAgo: 400);

        // The weight itself, not the drawn queue. With only two played tracks
        // the draw takes both almost every time, so the queue cannot show a
        // difference the scoring genuinely makes — and a randomised assertion
        // that passes on the seed is worse than no assertion.
        $scores = new \ReflectionMethod(SmartShuffle::class, 'scores');
        $weights = collect($scores->invoke(app(SmartShuffle::class), $this->profile->id))
            ->keyBy('media_item_id');

        $this->assertGreaterThan(
            $weights[$stale->id]->weight,
            $weights[$recent->id]->weight,
            'Ten plays last week should weigh more than ten plays over a year ago.',
        );
    }

    public function test_it_still_reaches_tracks_that_were_never_played(): void
    {
        // A weighted draw that only ever returns favourites is a playlist, not
        // a shuffle.
        $this->play($this->track('Loved'), times: 50);

        foreach (range(1, 20) as $n) {
            $this->track("Never {$n}");
        }

        $counts = $this->frequencies();
        $reached = collect($counts)->keys()->filter(fn (string $t) => str_starts_with($t, 'Never'));

        $this->assertGreaterThan(10, $reached->count(), 'Discovery should reach most of the library over many draws.');
    }

    public function test_a_queue_never_repeats_a_track(): void
    {
        $this->play($this->track('Loved'), times: 5);

        foreach (range(1, 30) as $n) {
            $this->track("Filler {$n}");
        }

        $queue = app(SmartShuffle::class)->queue($this->profile->id, 20);

        $this->assertSame($queue->count(), $queue->pluck('id')->unique()->count());
    }

    public function test_with_no_history_it_falls_back_to_a_plain_shuffle(): void
    {
        // Nothing to be smart with; a weighted draw over no scores is just a
        // slower uniform one.
        foreach (range(1, 10) as $n) {
            $this->track("Track {$n}");
        }

        $queue = app(SmartShuffle::class)->queue($this->profile->id, 5);

        $this->assertCount(5, $queue);
    }

    public function test_another_profiles_history_does_not_steer_this_one(): void
    {
        $theirs = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Someone Else',
            'is_owner' => false,
        ]);

        $hers = $this->track('Hers');

        MediaPlay::create([
            'media_item_id' => $hers->id,
            'user_id' => $this->user->id,
            'profile_id' => $theirs->id,
            'position_seconds' => 0,
        ]);

        foreach (range(1, 20) as $n) {
            $this->track("Filler {$n}");
        }

        $counts = $this->frequencies();

        $fillerAverage = collect($counts)
            ->filter(fn ($_, string $title) => str_starts_with($title, 'Filler'))
            ->avg() ?? 0;

        // Within noise of the others, rather than promoted.
        $this->assertLessThan($fillerAverage * 2, $counts['Hers'] ?? 0);
    }

    public function test_the_api_serves_a_smart_queue_when_asked(): void
    {
        $this->play($this->track('Loved'), times: 5);

        foreach (range(1, 10) as $n) {
            $this->track("Filler {$n}");
        }

        \Laravel\Sanctum\Sanctum::actingAs($this->user, ['profile:'.$this->profile->id]);
        app(\App\Services\CurrentProfile::class)->switchTo($this->profile->id);

        $this->getJson(route('api.library.shuffle', ['smart' => 1, 'limit' => 5]))
            ->assertOk()
            ->assertJsonPath('smart', true)
            ->assertJsonCount(5, 'items');
    }

    public function test_the_api_still_serves_a_plain_shuffle_by_default(): void
    {
        foreach (range(1, 10) as $n) {
            $this->track("Track {$n}");
        }

        \Laravel\Sanctum\Sanctum::actingAs($this->user, ['profile:'.$this->profile->id]);
        app(\App\Services\CurrentProfile::class)->switchTo($this->profile->id);

        $this->getJson(route('api.library.shuffle', ['limit' => 5]))
            ->assertOk()
            ->assertJsonPath('smart', false)
            ->assertJsonCount(5, 'items');
    }

    public function test_a_track_with_no_file_is_never_queued(): void
    {
        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Ghost',
            'file_path' => null,
            'owned' => false,
        ]);

        $this->track('Real');

        $queue = app(SmartShuffle::class)->queue($this->profile->id, 10);

        $this->assertSame(['Real'], $queue->pluck('title')->all());
    }
}
