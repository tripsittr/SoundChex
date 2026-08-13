<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\SubtitleCue;
use App\Models\User;
use App\Services\CurrentProfile;
use App\Services\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Search reaches into subtitles and book pages, which is the one place a
 * rating cap can leak: a capped profile finding an R-rated film through a
 * single line of its dialogue. The filter has to apply to every group, not
 * just titles.
 */
class SearchServiceTest extends TestCase
{
    use RefreshDatabase;

    private SearchService $search;

    private User $user;

    private Profile $owner;

    private Profile $kid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->search = app(SearchService::class);
        $this->user = User::factory()->create();

        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->kid = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Kid',
            'is_owner' => false,
            'max_rating' => 'PG',
        ]);

        $this->actingAs($this->user);
    }

    public function test_it_finds_a_title(): void
    {
        $this->movie('Backrooms', 'G');
        $this->as($this->owner);

        $this->assertContains('Backrooms', $this->titlesFound('Backrooms'));
    }

    public function test_a_one_character_query_returns_nothing(): void
    {
        // Otherwise the first keystroke scans every subtitle cue in the
        // library.
        $this->movie('Backrooms', 'G');
        $this->as($this->owner);

        $this->assertSame(0, $this->search->search('B')['total']);
    }

    public function test_a_capped_profile_does_not_find_a_higher_rated_title(): void
    {
        $this->movie('Backrooms', 'R');
        $this->as($this->kid);

        $this->assertNotContains('Backrooms', $this->titlesFound('Backrooms'));
    }

    public function test_a_capped_profile_cannot_reach_a_film_through_its_dialogue(): void
    {
        // The hole the service exists to close. A cap that filters titles but
        // not dialogue looks like it works while leaking the same film.
        $film = $this->movie('Backrooms', 'R');
        $this->cue($film, 'the yellow wallpaper stretches on forever');

        $this->as($this->kid);

        $results = $this->search->search('yellow wallpaper');

        $this->assertSame(0, $results['total']);
    }

    public function test_an_uncapped_profile_does_reach_it(): void
    {
        // Proves the previous test fails for the right reason — the cap, not
        // a search that never matched anything.
        $film = $this->movie('Backrooms', 'R');
        $this->cue($film, 'the yellow wallpaper stretches on forever');

        $this->as($this->owner);

        $this->assertGreaterThan(0, $this->search->search('yellow wallpaper')['total']);
    }

    public function test_the_same_line_indexed_twice_is_one_result(): void
    {
        // Dedup is keyed on item + start time, which is what a re-scan or a
        // second subtitle track produces: the same moment indexed twice. A
        // line genuinely spoken at five different times is five real hits and
        // must stay five — so this asserts the collapse, not a blanket cap.
        $film = $this->movie('Backrooms', 'G');

        $this->cue($film, 'the yellow wallpaper again', 30);
        $this->cue($film, 'the yellow wallpaper again', 30, language: 'es');

        $this->as($this->owner);

        $dialogue = collect($this->search->search('yellow wallpaper')['groups'])
            ->firstWhere('key', 'dialogue');

        $this->assertNotNull($dialogue);
        $this->assertSame(1, $dialogue['results']->count());
    }

    public function test_a_line_repeated_at_different_times_stays_separate(): void
    {
        $film = $this->movie('Backrooms', 'G');

        foreach (range(1, 3) as $n) {
            $this->cue($film, 'the yellow wallpaper again', $n * 30);
        }

        $this->as($this->owner);

        $dialogue = collect($this->search->search('yellow wallpaper')['groups'])
            ->firstWhere('key', 'dialogue');

        $this->assertSame(3, $dialogue['results']->count());
    }

    /* -------------------------------------------------------- helpers --- */

    private function as(Profile $profile): void
    {
        app(CurrentProfile::class)->switchTo($profile->id);
    }

    /** @return array<int, string> */
    private function titlesFound(string $term): array
    {
        $group = collect($this->search->search($term)['groups'])
            ->firstWhere('key', 'titles');

        return $group === null
            ? []
            // Results are display rows, not models: the title lives in
            // 'label'.
            : $group['results']->pluck('label')->all();
    }

    private function movie(string $title, ?string $rating): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => '/tmp/not-read-by-search.mkv',
            'owned' => true,
        ]);

        $item->movieMetadata()->create([
            'release_year' => 2026,
            'mpaa_rating' => $rating,
        ]);

        return $item->fresh();
    }

    private function cue(MediaItem $item, string $text, int $start = 10, string $language = 'en'): void
    {
        // Cues hang off a subtitle track, so the parent row has to exist.
        // The language is a parameter so a second track can index the same
        // moment, which is the case dedup exists for.
        $subtitle = $item->subtitles()->firstOrCreate(
            ['language' => $language],
            ['label' => $language, 'source' => 'test', 'path' => "subs/{$language}.vtt"],
        );

        SubtitleCue::create([
            'subtitle_id' => $subtitle->id,
            'media_item_id' => $item->id,
            'start_seconds' => $start,
            'end_seconds' => $start + 3,
            'text' => $text,
        ]);
    }
}
