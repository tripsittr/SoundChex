<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\MediaProbe;
use App\Models\ShowMetadata;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An episode has to say what it is an episode *of*.
 *
 * A Continue Watching card could show "But at Last Came a Knock" with nothing
 * saying it was Shameless. The episode row carries its own number and title;
 * the series name lives on the parent row, and that shelf fetches episodes
 * without their series.
 */
class EpisodeIdentityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function series(string $title): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Show,
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    private function episode(MediaItem $series, int $season, int $number, string $title): MediaItem
    {
        $episode = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Show,
            'parent_id' => $series->id,
            'file_path' => 'shows/'.str($title)->slug().'.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        ShowMetadata::create([
            'media_item_id' => $episode->id,
            'season_number' => $season,
            'episode_number' => $number,
            'episode_title' => $title,
        ]);

        return $episode->fresh();
    }

    public function test_an_episode_names_its_series(): void
    {
        $series = $this->series('Shameless (U.S.)');
        $episode = $this->episode($series, 1, 9, 'But at Last Came a Knock');

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => null,
            'position_seconds' => 900,
            'completed' => false,
        ]);

        $this->actingAs($this->user);

        MediaPlay::where('media_item_id', $episode->id)
            ->update(['profile_id' => app(CurrentProfile::class)->id()]);

        $response = $this->getJson('/api/v1/library/continue');

        $response->assertOk();

        $card = collect($response->json('watching'))
            ->firstWhere('id', $episode->id);

        $this->assertNotNull($card, 'The episode should be on the shelf.');
        $this->assertSame('Shameless (U.S.)', $card['meta']['series_title'] ?? null);
        $this->assertSame(1, $card['meta']['season_number'] ?? null);
        $this->assertSame(9, $card['meta']['episode_number'] ?? null);
        $this->assertSame('But at Last Came a Knock', $card['meta']['episode_title'] ?? null);
    }

    /**
     * An episode row carries what it needs to look like an episode row.
     *
     * A number and a title is a file listing. The streaming apps show a
     * still, a duration and a sentence — the last two come from here. The
     * runtime is measured from the file by the probe rather than repeated
     * from a metadata source, because that is the number matching what
     * actually plays; `show_metadata` has no runtime column at all.
     */
    public function test_an_episode_carries_its_synopsis_and_runtime(): void
    {
        $series = $this->series('Shameless (U.S.)');
        $episode = $this->episode($series, 1, 1, 'Pilot');

        $episode->forceFill([
            'notes' => 'Meet the fabulously dysfunctional Gallagher family.',
        ])->saveQuietly();

        MediaProbe::create([
            'media_item_id' => $episode->id,
            'duration_ms' => 57 * 60 * 1000,
            'probed_at' => now(),
        ]);

        $this->actingAs($this->user);

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => 900,
            'completed' => false,
        ]);

        $card = collect($this->getJson('/api/v1/library/continue')->json('watching'))
            ->firstWhere('id', $episode->id);

        $this->assertSame(
            'Meet the fabulously dysfunctional Gallagher family.',
            $card['meta']['overview'] ?? null,
        );
        $this->assertSame(57, $card['meta']['runtime_minutes'] ?? null);
    }

    /**
     * The series name must not cost a query per card.
     *
     * The relation is loaded lazily by default, so serialising twenty cards
     * would be twenty extra round trips on the one shelf that is fetched on
     * every appearance of the home screen.
     */
    public function test_naming_the_series_does_not_cost_a_query_per_card(): void
    {
        $series = $this->series('Shameless (U.S.)');

        $this->actingAs($this->user);
        $profileId = app(CurrentProfile::class)->id();

        foreach (range(1, 6) as $n) {
            $episode = $this->episode($series, 1, $n, "Episode {$n}");

            MediaPlay::create([
                'media_item_id' => $episode->id,
                'user_id' => $this->user->id,
                'profile_id' => $profileId,
                'position_seconds' => 600 + $n,
                'completed' => false,
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $response = $this->getJson('/api/v1/library/continue');

        $response->assertOk();

        $named = collect($response->json('watching'))
            ->filter(fn ($card) => ($card['meta']['series_title'] ?? null) === 'Shameless (U.S.)')
            ->count();

        $this->assertSame(6, $named, 'Every episode should name its series.');

        // Measured, not guessed: this endpoint runs **7** queries with the
        // eager load and **12** without it -- exactly the six extra the six
        // episodes cost, one each. The threshold sits between the two, so the
        // test fails the moment the series name goes back to being resolved
        // per card. A looser bound passed either way and proved nothing.
        $this->assertLessThanOrEqual(
            8,
            $queries,
            'Resolving the series name should be one eager load, not one query per card.',
        );
    }
}
