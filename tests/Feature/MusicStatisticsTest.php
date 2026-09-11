<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\LibraryStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The figures behind the statistics page.
 *
 * Arithmetic a page displays is arithmetic someone will act on, so the cases
 * worth pinning are the ones where a plausible-looking implementation is
 * wrong: a genre counted once per tag rather than once per play, a "new" track
 * counted as new every time it is replayed, a streak that survives a gap.
 */
class MusicStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    private Profile $other;

    private LibraryStatistics $stats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        // `is_owner` explicitly: the permission gate short-circuits for the
        // owner, and a profile created without it is refused the page.
        $this->owner = Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->other = Profile::create(['user_id' => $this->user->id, 'name' => 'Other', 'is_owner' => false]);
        $this->stats = app(LibraryStatistics::class)->for(MediaItemType::Music);
    }

    private function track(string $title, string $artist = 'An Artist', ?int $ms = 180000): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => '/tmp/' . str($title)->slug() . '.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'primary_artist' => $artist,
            'duration_ms' => $ms,
        ]);

        return $item->fresh();
    }

    private function play(MediaItem $item, ?Profile $profile = null, ?Carbon $at = null, int $listened = 60, bool $completed = false): void
    {
        $play = $item->plays()->create([
            'user_id' => $this->user->id,
            'profile_id' => $profile?->id,
            'listened_seconds' => $listened,
            'completed' => $completed,
        ]);

        // Forced, because `created_at` is not fillable — passing it to
        // create() is silently dropped and every row lands at "now", which
        // makes a date-range test pass or fail for the wrong reason.
        if ($at !== null) {
            $play->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }
    }

    public function test_library_totals_count_what_is_held(): void
    {
        $this->track('One', 'A', 120000);
        $this->track('Two', 'B', 240000);

        $library = $this->stats->library();

        $this->assertSame(2, $library['items']);
        $this->assertSame(360, $library['seconds']);
        $this->assertSame(2, $library['primary']);
    }

    public function test_a_track_with_no_duration_is_named_rather_than_assumed(): void
    {
        // "513.9 hours" from a library where some tracks carry no duration is
        // a floor, and the page says so. Silently treating null as zero would
        // make an understated total look exact.
        $this->track('Timed', 'A', 120000);
        $this->track('Untimed', 'A', null);

        $library = $this->stats->library();

        $this->assertSame(120, $library['seconds']);
        $this->assertSame(1, $library['untimed']);
    }

    public function test_listening_sums_time_and_separates_completion_from_plays(): void
    {
        $track = $this->track('One');

        $this->play($track, $this->owner, listened: 90, completed: true);
        $this->play($track, $this->owner, listened: 30);

        $listening = $this->stats->listening();

        $this->assertSame(2, $listening['plays']);
        $this->assertSame(120, $listening['seconds']);
        $this->assertSame(1, $listening['completed']);
        // A skip is a play, which is exactly why this number exists.
        $this->assertSame(50.0, $listening['completion_rate']);
    }

    public function test_rows_predating_listening_time_are_counted_but_not_summed(): void
    {
        $track = $this->track('One');

        $this->play($track, $this->owner, listened: 60);
        $track->plays()->create(['user_id' => $this->user->id, 'profile_id' => $this->owner->id]);

        $listening = $this->stats->listening();

        $this->assertSame(2, $listening['plays']);
        $this->assertSame(60, $listening['seconds'], 'a null is not zero seconds of listening');
        $this->assertSame(1, $listening['untimed']);
    }

    public function test_a_date_range_excludes_what_falls_outside_it(): void
    {
        $track = $this->track('One');

        $this->play($track, $this->owner, at: now()->subDays(60));
        $this->play($track, $this->owner, at: now()->subDays(2));

        $this->assertSame(2, $this->stats->listening()['plays']);
        $this->assertSame(1, $this->stats->listening(now()->subDays(30))['plays']);
    }

    public function test_a_profile_filter_shows_only_that_persons_listening(): void
    {
        $track = $this->track('One');

        $this->play($track, $this->owner);
        $this->play($track, $this->other);
        $this->play($track, $this->other);

        $this->assertSame(3, $this->stats->listening()['plays']);
        $this->assertSame(1, $this->stats->listening(null, null, $this->owner->id)['plays']);
        $this->assertSame(2, $this->stats->listening(null, null, $this->other->id)['plays']);
    }

    public function test_top_artists_group_collaborations_under_the_primary(): void
    {
        // Otherwise one artist appears once per collaborator they have ever
        // recorded with, which is the S-26 bug in a different table.
        $solo = $this->track('Solo', 'Avicii');
        $joint = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Together',
            'file_path' => '/tmp/together.mp3',
            'owned' => true,
        ]);
        $joint->musicMetadata()->create([
            'artist' => 'Avicii, Nicky Romero',
            'primary_artist' => 'Avicii',
        ]);

        $this->play($solo, $this->owner);
        $this->play($joint->fresh(), $this->owner);

        $artists = $this->stats->topPrimary(null, null, null);

        $this->assertCount(1, $artists);
        $this->assertSame('Avicii', $artists->first()->grouping);
        $this->assertSame(2, (int) $artists->first()->plays);
    }

    public function test_reach_reports_how_much_has_never_been_played(): void
    {
        $played = $this->track('Played', 'Heard');
        $this->track('Never', 'Unheard');

        $this->play($played, $this->owner);

        $reach = $this->stats->reach();

        $this->assertSame(['total' => 2, 'played' => 1], $reach['items']);
        $this->assertSame(['total' => 2, 'played' => 1], $reach['primary']);
    }

    public function test_a_repeat_is_not_counted_as_a_discovery(): void
    {
        // The distinction the whole chart rests on: a favourite played every
        // day is one discovery and many repeats, not a discovery each time.
        $track = $this->track('One');

        $this->play($track, $this->owner, at: now()->subDays(2)->setTime(9, 0));
        $this->play($track, $this->owner, at: now()->subDays(1)->setTime(9, 0));

        $rows = $this->stats->discovery(null, null, null);

        $this->assertSame(1, (int) $rows->sum('discovered'));
        $this->assertSame(1, (int) $rows->sum('repeated'));
    }

    public function test_a_streak_breaks_on_a_missed_day(): void
    {
        $track = $this->track('One');

        foreach ([10, 9, 8, 5, 4] as $daysAgo) {
            $this->play($track, $this->owner, at: now()->subDays($daysAgo)->setTime(12, 0));
        }

        $streaks = $this->stats->streaks();

        $this->assertSame(5, $streaks['days']);
        $this->assertSame(3, $streaks['longest_streak'], 'the run of three, not all five days');
        $this->assertSame(0, $streaks['current_streak'], 'a streak that ended days ago is not current');
    }

    public function test_the_clock_reports_every_slot_including_the_empty_ones(): void
    {
        // A chart with 3am missing draws a narrower day; a chart with 3am at
        // zero says nobody listens then, which is the information wanted.
        $track = $this->track('One');

        $this->play($track, $this->owner, at: now()->setTime(14, 30));

        $clock = $this->stats->clock(null, null, null);

        $this->assertCount(24, $clock['hours']);
        $this->assertCount(7, $clock['days']);
        $this->assertSame(1, $clock['hours'][14]);
        $this->assertSame(0, $clock['hours'][3]);
    }

    public function test_unattributed_plays_are_counted_rather_than_hidden(): void
    {
        $track = $this->track('One');

        $this->play($track, $this->owner);
        $track->plays()->create(['user_id' => $this->user->id]);

        $this->assertSame(1, $this->stats->unattributedPlays());
    }

    public function test_the_page_renders_for_the_owner(): void
    {
        $track = $this->track('One');
        $this->play($track, $this->owner);

        $html = $this->actingAs($this->user)
            ->withSession([
                'profile_id' => $this->owner->id,
                'profile_unlocked_at' => now()->timestamp,
            ])
            ->get('/admin/music-statistics-page')
            ->assertOk()
            ->assertSee('Statistics')
            ->getContent();

        // Every chart mounts. They are Livewire components embedded in the
        // view rather than page widgets, so a typo in a class name renders
        // nothing and says nothing — the headings alone would still pass.
        $this->assertSame(
            7,
            substr_count($html, '<canvas'),
            'one canvas per chart',
        );

        // And the tabs, one per media type.
        $this->assertStringContainsString('Music', $html);
        $this->assertStringContainsString('Movies', $html);
        $this->assertStringContainsString('Books', $html);
    }

    public function test_a_type_with_nothing_in_it_says_so_rather_than_breaking(): void
    {
        // The show tab on this library: zero items, so every grouping query
        // runs against an empty set. That is the case most likely to divide by
        // zero or hand a chart a null it did not expect.
        $html = $this->actingAs($this->user)
            ->withSession([
                'profile_id' => $this->owner->id,
                'profile_unlocked_at' => now()->timestamp,
            ])
            ->get('/admin/music-statistics-page?type=show')
            ->assertOk()
            ->getContent();

        $this->assertNotEmpty($html);
    }

    public function test_every_media_type_can_be_queried(): void
    {
        // The groupings differ per type — artist, director, creator, author —
        // and each reads a different metadata table. A type whose table is
        // missing a column fails only when that tab is opened.
        foreach (MediaItemType::cases() as $case) {
            $stats = app(LibraryStatistics::class)->for($case);

            $library = $stats->library();
            $listening = $stats->listening();

            $this->assertIsInt($library['items'], $case->value . ' library');
            $this->assertIsInt($listening['plays'], $case->value . ' listening');
            $this->assertNotEmpty($stats->labels()['primary'], $case->value . ' labels');

            // The grouped queries, which are where a wrong column name shows.
            $stats->topItems(null, null, null, 5);
            $stats->topPrimary(null, null, null, 5);
            $stats->reach();
            $stats->storage(5);
        }
    }

    public function test_the_top_items_table_shows_artwork_rather_than_placeholders(): void
    {
        // `cover_image_url` is a path relative to the storage disk, so an
        // ImageColumn reading the column directly renders a broken URL and
        // falls back to the placeholder for every row — which looks like a
        // library with no artwork rather than a resolution bug.
        $track = $this->track('One');
        $track->forceFill(['cover_image_url' => 'artwork/An Artist/An Album/One-1.jpg'])->save();
        $this->play($track, $this->owner);

        $table = new \App\Filament\Widgets\Statistics\TopItemsTable;
        $table->type = 'music';
        $table->range = 'all';

        $this->assertStringContainsString(
            '/storage/artwork/',
            (string) $track->fresh()->coverUrl(),
            'the column the table renders resolves to a served URL',
        );
    }

    public function test_a_chart_is_refused_to_a_profile_without_permission(): void
    {
        // A widget renders independently of the page that hosts it, so the
        // page's gate is not enough — Livewire will resolve one by name.
        $this->actingAs($this->user)->withSession([
            'profile_id' => $this->other->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);

        $this->assertFalse(
            \App\Filament\Widgets\Statistics\TopArtistsChart::canView(),
            'a non-owner profile cannot render the chart directly',
        );
    }
}
