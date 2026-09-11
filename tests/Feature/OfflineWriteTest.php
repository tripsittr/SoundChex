<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Writes made offline and replayed later.
 *
 * A queued write arrives after the moment it describes, and may arrive twice —
 * a retry after a timeout is indistinguishable from a first attempt. So the
 * endpoints have to be safe to replay, and must not undo something newer.
 */
class OfflineWriteTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $profile;

    private MediaItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => '/tmp/film.mkv',
            'owned' => true,
        ]);
        $this->item->movieMetadata()->create(['release_year' => 2026]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->profile->id);
    }

    /* -------------------------------------------------------- progress -- */

    public function test_progress_is_saved(): void
    {
        $this->postJson(route('media.progress', $this->item), [
            'position' => 120,
            'duration' => 600,
        ])->assertOk();

        $this->assertSame(120, $this->item->plays()->first()->position_seconds);
    }

    public function test_a_stale_replay_does_not_undo_newer_progress(): void
    {
        // The case that matters: a position queued on a train, replayed after
        // the user has already watched further on another device. Applying it
        // would silently rewind them.
        $this->postJson(route('media.progress', $this->item), [
            'position' => 500,
            'duration' => 600,
        ])->assertOk();

        $this->postJson(route('media.progress', $this->item), [
            'position' => 60,
            'duration' => 600,
            'recorded_at' => now()->subHour()->toIso8601String(),
        ])->assertOk()->assertJson(['stale' => true]);

        $this->assertSame(500, $this->item->plays()->first()->position_seconds);
    }

    public function test_a_newer_queued_write_is_applied(): void
    {
        // Proves the guard is a comparison rather than a blanket refusal of
        // anything carrying a timestamp.
        $this->postJson(route('media.progress', $this->item), [
            'position' => 60,
            'duration' => 600,
        ])->assertOk();

        $this->postJson(route('media.progress', $this->item), [
            'position' => 300,
            'duration' => 600,
            'recorded_at' => now()->addMinute()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(300, $this->item->plays()->first()->position_seconds);
    }

    public function test_replaying_the_same_progress_is_harmless(): void
    {
        $payload = ['position' => 120, 'duration' => 600];

        $this->postJson(route('media.progress', $this->item), $payload)->assertOk();
        $this->postJson(route('media.progress', $this->item), $payload)->assertOk();

        // One row per session, not one per replay.
        $this->assertSame(1, $this->item->plays()->count());
        $this->assertSame(120, $this->item->plays()->first()->position_seconds);
    }

    /* -------------------------------------------- reading progress -- */

    /**
     * The reader had none of this.
     *
     * A failed save was swallowed with "losing one position update isn't worth
     * interrupting reading" — true of one update, wrong about a whole session
     * read offline, which is every update. The player had queued its writes for
     * a while; a book read on the same train lost its page.
     *
     * Queuing them means they arrive late, which needs the same staleness
     * guard the media endpoint already had.
     */
    private function book(): MediaItem
    {
        $book = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Book,
            'title' => 'A Novel',
            'file_path' => '/tmp/book.epub',
            'owned' => true,
        ]);

        $book->bookMetadata()->create(['author' => 'An Author']);

        return $book;
    }

    public function test_reading_progress_is_saved(): void
    {
        $book = $this->book();

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'epubcfi(/6/14!/4/2/14)',
            'percent' => 32,
        ])->assertOk();

        $this->assertSame(32, $book->readingProgress()->first()->percent);
    }

    public function test_a_stale_reading_replay_does_not_undo_newer_progress(): void
    {
        $book = $this->book();

        // Read to chapter nine on a tablet...
        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-9',
            'percent' => 80,
        ])->assertOk();

        // ...then the phone reconnects and replays the train's last page.
        // Applying it would put the reader back 60% of the book.
        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-3',
            'percent' => 20,
            'recorded_at' => now()->subHour()->toIso8601String(),
        ])->assertOk()->assertJson(['stale' => true]);

        $progress = $book->readingProgress()->first();

        $this->assertSame(80, $progress->percent);
        $this->assertSame('chapter-9', $progress->location);
    }

    public function test_a_newer_queued_reading_write_is_applied(): void
    {
        // The guard is a comparison, not a refusal of anything timestamped.
        $book = $this->book();

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-1',
            'percent' => 10,
        ])->assertOk();

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-5',
            'percent' => 55,
            'recorded_at' => now()->addMinute()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(55, $book->readingProgress()->first()->percent);
    }

    public function test_a_first_reading_write_is_never_stale(): void
    {
        // Nothing to be older than. A queued write for a book opened for the
        // first time offline must still land.
        $book = $this->book();

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-2',
            'percent' => 15,
            'recorded_at' => now()->subDay()->toIso8601String(),
        ])->assertOk();

        $this->assertSame(15, $book->readingProgress()->first()->percent);
    }

    public function test_replaying_the_same_reading_progress_is_harmless(): void
    {
        $book = $this->book();

        $payload = ['location' => 'chapter-4', 'percent' => 40];

        $this->postJson(route('media.read.progress', $book), $payload)->assertOk();
        $this->postJson(route('media.read.progress', $book), $payload)->assertOk();

        $this->assertSame(1, $book->readingProgress()->count());
        $this->assertSame(40, $book->readingProgress()->first()->percent);
    }

    public function test_two_profiles_keep_their_own_place_in_one_book(): void
    {
        // The household case, and the reason any of this is keyed on a profile.
        //
        // This was broken two ways at once, and neither looked like a bug from
        // the outside. `profile_id` was missing from the model's $fillable, so
        // every row was written with a null profile whoever was reading — one
        // shared place in every book, which reads as the app forgetting where
        // you were. And the unique index still said (media_item_id, user_id)
        // from before profiles existed, so once the first fault was fixed the
        // second reader's save was refused by the database with a 500.
        $book = $this->book();

        $other = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Someone Else',
        ]);

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-9',
            'percent' => 90,
        ])->assertOk();

        app(CurrentProfile::class)->switchTo($other->id);

        $this->postJson(route('media.read.progress', $book), [
            'location' => 'chapter-2',
            'percent' => 15,
        ])->assertOk();

        // Two rows, each with its own position — not one row overwritten.
        $this->assertSame(2, $book->readingProgress()->count());

        $this->assertSame(90, $book->readingProgress()->where('profile_id', $this->profile->id)->value('percent'));
        $this->assertSame(15, $book->readingProgress()->where('profile_id', $other->id)->value('percent'));
    }

    /* ------------------------------------------------------- watchlist -- */

    public function test_the_watchlist_still_toggles_without_a_stated_intent(): void
    {
        // The online button sends no intent and expects a toggle.
        $this->postJson(route('media.watchlist.toggle', $this->item))
            ->assertOk()
            ->assertJson(['inWatchlist' => true]);

        $this->postJson(route('media.watchlist.toggle', $this->item))
            ->assertOk()
            ->assertJson(['inWatchlist' => false]);
    }

    public function test_a_replayed_watchlist_write_does_not_flip_it_back(): void
    {
        // A toggle is not replayable: sent twice it returns to where it
        // started, so an offline write states the result it wants instead.
        $payload = ['in_watchlist' => true];

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => true]);

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => true, 'unchanged' => true]);

        $this->assertTrue(
            $this->profile->watchlist()->where('media_item_id', $this->item->id)->exists(),
        );
    }

    public function test_a_stated_removal_replays_safely_too(): void
    {
        $this->profile->watchlist()->attach($this->item->id);

        $payload = ['in_watchlist' => false];

        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)->assertOk();
        $this->postJson(route('media.watchlist.toggle', $this->item), $payload)
            ->assertOk()
            ->assertJson(['inWatchlist' => false]);

        $this->assertFalse(
            $this->profile->watchlist()->where('media_item_id', $this->item->id)->exists(),
        );
    }
}
