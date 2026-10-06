<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\MediaProbe;
use App\Models\Profile;
use App\Models\ShowMetadata;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The web episode list, in the shape the streaming apps use.
 *
 * It was a file listing: "E01" and a truncated title, with every season
 * stacked at once. A sixty-episode series is unusable that way, and an episode
 * title alone says nothing about whether you have seen it.
 *
 * These assert the four things that make a row an episode row — still,
 * number and title, duration, synopsis — plus the season picker and the
 * progress bar.
 */
class EpisodeListLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function series(string $title = 'Shameless (U.S.)'): MediaItem
    {
        return MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Show,
            'processing_status' => ProcessingStatus::Complete,
        ]);
    }

    /**
     * Season and episode numbers come out of the *filename* on this page, not
     * a column — `seasonsOf()` runs `EpisodeParser` over the basename — so the
     * path has to carry the marker for the grouping to work.
     */
    private function episode(
        MediaItem $series,
        int $season,
        int $number,
        string $title,
        ?string $overview = null,
        ?int $minutes = null,
    ): MediaItem {
        $episode = MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => $title,
            'type' => MediaItemType::Show,
            'parent_id' => $series->id,
            // The series name has to precede the marker: `EpisodeParser`
            // reads the name from what comes *before* S01E01 and returns null
            // when there is nothing there, so a path starting at the marker
            // parses as no episode at all.
            'file_path' => sprintf('shows/%s S%02dE%02d %s.mkv', $series->title, $season, $number, $title),
            'notes' => $overview,
            'processing_status' => ProcessingStatus::Complete,
        ]);

        ShowMetadata::create([
            'media_item_id' => $episode->id,
            'season_number' => $season,
            'episode_number' => $number,
            'episode_title' => $title,
        ]);

        if ($minutes !== null) {
            MediaProbe::create([
                'media_item_id' => $episode->id,
                'duration_ms' => $minutes * 60 * 1000,
                'probed_at' => now(),
            ]);
        }

        return $episode->fresh();
    }

    private function page(MediaItem $series)
    {
        return $this->actingAs($this->user)->get(route('media.show', $series));
    }

    public function test_an_episode_row_shows_its_synopsis(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 1, 'Pilot', 'Meet the Gallagher family.');

        $this->page($series)
            ->assertOk()
            ->assertSee('Meet the Gallagher family.', escape: false);
    }

    public function test_an_episode_row_shows_its_runtime(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 1, 'Pilot', minutes: 57);

        $this->page($series)->assertOk()->assertSee('57m');
    }

    /**
     * Over an hour reads as "1h 2m", not "62m" — the unit every episode list
     * uses once a runtime passes the hour.
     */
    public function test_a_long_episode_reads_in_hours(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 1, 'Feature Length', minutes: 62);

        $this->page($series)->assertOk()->assertSee('1h 2m');
    }

    public function test_the_number_and_title_read_as_one_line(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 3, 'Aunt Ginger');

        $this->page($series)->assertOk()->assertSee('3. Aunt Ginger');
    }

    /**
     * A series with more than one season gets a picker, and only one season
     * is on screen — the whole point of having one.
     */
    public function test_several_seasons_get_a_picker(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 1, 'Pilot');
        $this->episode($series, 2, 1, 'Summertime');

        $response = $this->page($series)->assertOk();

        $response->assertSee('Season 1');
        $response->assertSee('Season 2');
        $response->assertSee('sc-season-0', escape: false);
        $response->assertSee('sc-season-1', escape: false);
    }

    /**
     * One season needs no picker: a control with a single option is furniture.
     */
    public function test_a_single_season_gets_no_picker(): void
    {
        $series = $this->series();
        $this->episode($series, 1, 1, 'Pilot');
        $this->episode($series, 1, 2, 'Frank the Plank');

        $html = $this->page($series)->assertOk()->getContent();

        $this->assertSame(
            0,
            substr_count((string) $html, 'for="sc-season-'),
            'A one-season series should render no season chips.',
        );
    }

    /* --------------------------------------------- the continue shelf --- */

    /**
     * A Continue Watching card names the **series**, then the episode.
     *
     * It read "But at Last Came a Knock" with nothing saying it was Shameless
     * — an episode title alone identifies almost nothing.
     *
     * This also guards the eager loading. The poster reads `parent` and
     * `showMetadata` only when they are already loaded, because reading them
     * lazily is a query per tile (the home-page cost guard catches that). The
     * flip side is that a shelf which forgets to load them silently renders
     * the old, useless label — so the rendering is asserted, not the loading.
     */
    public function test_a_continue_card_names_the_series_and_episode(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, 1, 9, 'But at Last Came a Knock');

        $this->actingAs($this->user);

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => 900,
            'completed' => false,
        ]);

        $response = $this->get('/app/watch')->assertOk();

        $response->assertSee('Shameless (U.S.)', escape: false);
        $response->assertSee('S1:E9 But at Last Came a Knock', escape: false);
    }

    /**
     * An episode whose filename carries no marker lands under "Other", and
     * that label has to be drawn even though it is the only season.
     *
     * The single-season rule hides the picker, and "Other" was only ever
     * rendered as a chip — so suppressing it left such an episode under no
     * heading at all. Caught by `TelevisionViewTest` when this layout landed.
     */
    public function test_an_unparseable_episode_keeps_its_other_heading(): void
    {
        $series = $this->series();

        MediaItem::unresolved()->create([
            'user_id' => $this->user->id,
            'title' => 'Bonus Feature',
            'type' => MediaItemType::Show,
            'parent_id' => $series->id,
            'file_path' => 'shows/some bonus feature.mkv',
            'processing_status' => ProcessingStatus::Complete,
        ]);

        $this->page($series)->assertOk()->assertSee('Other');
    }

    /**
     * The resume bar, which is the answer to "where was I" without opening
     * anything.
     */
    public function test_a_part_watched_episode_shows_a_progress_bar(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, 1, 1, 'Pilot', minutes: 60);

        $this->actingAs($this->user);

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => 15 * 60,
            'completed' => false,
        ]);

        // A quarter of an hour into an hour.
        $this->get(route('media.show', $series))->assertOk()->assertSee('width: 25%', escape: false);
    }

    /**
     * Someone else's position is not yours. Two people share a login, and a
     * bar drawn from the unfiltered set would show one person's progress on
     * the other's screen.
     */
    public function test_another_profiles_progress_is_not_drawn(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, 1, 1, 'Pilot', minutes: 60);

        // Signed in *first*, so the account's own default profile exists
        // before the second one is made. Creating it the other way round
        // makes "Someone Else" the default -- the viewer and the other person
        // become the same profile, and the test passes while proving nothing.
        $this->actingAs($this->user);
        $mine = app(CurrentProfile::class)->id();

        // A real second profile, not an invented id: `media_plays.profile_id`
        // is a foreign key.
        $theirs = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Someone Else',
        ]);

        $this->assertNotSame($mine, $theirs->id, 'The two profiles must differ.');

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => $theirs->id,
            'position_seconds' => 30 * 60,
            'completed' => false,
        ]);

        $this->get(route('media.show', $series))
            ->assertOk()
            ->assertDontSee('width: 50%', escape: false);
    }

    /**
     * A finished episode is not "in progress", and a full bar on it would say
     * the opposite of what it means.
     */
    public function test_a_finished_episode_shows_no_progress_bar(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, 1, 1, 'Pilot', minutes: 60);

        $this->actingAs($this->user);

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => 59 * 60,
            'completed' => true,
        ]);

        $this->get(route('media.show', $series))->assertOk()->assertDontSee('width: 98%', escape: false);
    }

    /**
     * No runtime means no bar, even with a position: a fraction of an unknown
     * duration is a guess presented as fact.
     */
    public function test_no_runtime_means_no_progress_bar(): void
    {
        $series = $this->series();
        $episode = $this->episode($series, 1, 1, 'Pilot');

        $this->actingAs($this->user);

        MediaPlay::create([
            'media_item_id' => $episode->id,
            'user_id' => $this->user->id,
            'profile_id' => app(CurrentProfile::class)->id(),
            'position_seconds' => 900,
            'completed' => false,
        ]);

        $html = (string) $this->get(route('media.show', $series))->assertOk()->getContent();

        // Matched inside the episode panel only. A bare `bg-accent" style=
        // "width` also matches the now-playing bar, which is always on the
        // page at `width: 0` -- so the first version of this failed against
        // correct output.
        $panel = substr($html, (int) strpos($html, 'data-season-panel'));
        $panel = substr($panel, 0, (int) strpos($panel, '</ul>'));

        $this->assertStringNotContainsString('bg-accent', $panel);
    }
}
