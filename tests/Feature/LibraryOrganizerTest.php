<?php

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryOrganizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The organizer moves the user's actual files, so these test the refusals at
 * least as hard as the successes. A file filed under the wrong author is worse
 * than one left in the inbox: it looks correct.
 */
class LibraryOrganizerTest extends TestCase
{
    use RefreshDatabase;

    private LibraryOrganizer $organizer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizer = app(LibraryOrganizer::class);
        $this->user = User::factory()->create();
    }

    /* --------------------------------------------------------- paths ---- */

    public function test_music_files_under_artist_and_album(): void
    {
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', track: 3);

        $this->assertSame(
            'media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3',
            $this->organizer->targetPath($item),
        );
    }

    public function test_music_without_an_album_files_under_singles(): void
    {
        // Plenty of tracks never appeared on an album. Holding them in the
        // inbox forever would mean they were never filed at all.
        $item = $this->music('Loner', artist: 'Nilüfer Yanya', album: null);

        $this->assertStringContainsString(
            'Music/Nilüfer Yanya/Singles/',
            (string) $this->organizer->targetPath($item),
        );
    }

    public function test_movies_file_as_title_and_year(): void
    {
        // The convention Plex, Jellyfin and Emby all expect, so the same tree
        // stays readable by other tools.
        $item = $this->movie('Backrooms', year: 2026);

        $this->assertSame(
            'media/library/Movies/Backrooms (2026)/Backrooms (2026).mkv',
            $this->organizer->targetPath($item),
        );
    }

    public function test_books_file_under_author(): void
    {
        $item = $this->book('The Hobbit', author: 'J.R.R. Tolkien');

        $this->assertSame(
            'media/library/Books/J.R.R. Tolkien/The Hobbit.epub',
            $this->organizer->targetPath($item),
        );
    }

    public function test_episodes_file_into_zero_padded_season_folders(): void
    {
        // Unpadded numbers sort lexically, which interleaves S01E10 before
        // S01E02 in every file browser.
        $item = $this->episode('The Bear', season: 1, episode: 2, episodeTitle: 'Hands');

        $this->assertSame(
            'media/library/TV/The Bear/Season 01/The Bear - S01E02 - Hands.mkv',
            $this->organizer->targetPath($item),
        );
    }

    /* ------------------------------------------------------ refusals ---- */

    public function test_it_refuses_to_move_a_fuzzy_match(): void
    {
        // The confidence gate is the whole reason a wrong lookup mislabels a
        // row rather than refiling someone's book under a stranger's name.
        $item = $this->book('The Hobbit', author: 'J.R.R. Tolkien');
        $item->forceFill(['match_confidence' => MatchConfidence::Fuzzy])->save();

        $this->assertFalse($this->organizer->canOrganize($item->fresh()));
        $this->assertNull($this->organizer->organize($item->fresh()));
    }

    public function test_music_is_exempt_from_the_confidence_gate(): void
    {
        // Its artist and album come from the file's own embedded tags, which
        // are authoritative about the file whatever an online source thinks.
        // 'none' is the real unenriched state — the column defaults to it and
        // is not nullable, so this is what an un-looked-up track actually has.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors');
        $item->forceFill(['match_confidence' => MatchConfidence::None])->save();

        $this->assertTrue($this->organizer->canOrganize($item->fresh()));
    }

    public function test_a_movie_without_a_year_stays_put(): void
    {
        $item = $this->movie('Backrooms', year: null);

        $this->assertFalse($this->organizer->canOrganize($item));
        $this->assertNull($this->organizer->targetPath($item));
    }

    public function test_a_book_without_an_author_stays_put(): void
    {
        $item = $this->book('Unknown Work', author: null);

        $this->assertFalse($this->organizer->canOrganize($item));
    }

    public function test_an_episode_without_numbering_stays_put(): void
    {
        $item = $this->episode('The Bear', season: null, episode: null);

        $this->assertFalse($this->organizer->canOrganize($item));
    }

    /* -------------------------------------------------------- moving ---- */

    public function test_it_moves_the_file_and_updates_the_record(): void
    {
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', track: 3);
        $source = $item->absoluteFilePath();

        $target = $this->organizer->organize($item);

        $this->assertSame('media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3', $target);
        $this->assertFileExists(Storage::disk('local')->path($target));
        $this->assertFileDoesNotExist($source);
        $this->assertSame($target, $item->fresh()->file_path);
    }

    public function test_a_dry_run_moves_nothing(): void
    {
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors');
        $source = $item->absoluteFilePath();

        $this->organizer->organize($item, dryRun: true);

        $this->assertFileExists($source);
    }

    public function test_it_never_overwrites_a_different_recording(): void
    {
        // Two takes can share a name. Clobbering one would destroy a file the
        // user cannot get back.
        $existing = 'media/library/Music/flipturn/Heavy Colors/Chicago.mp3';
        Storage::disk('local')->put($existing, 'a different recording entirely');

        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', contents: 'the new one');

        $target = $this->organizer->organize($item);

        $this->assertNotSame($existing, $target);
        $this->assertSame(
            'a different recording entirely',
            Storage::disk('local')->get($existing),
        );
    }

    public function test_an_identical_file_is_adopted_rather_than_duplicated(): void
    {
        // Byte-identical means there is nothing to keep. Suffixing it would
        // leave a redundant copy forever and re-offer it on every run.
        $existing = 'media/library/Music/flipturn/Heavy Colors/Chicago.mp3';
        Storage::disk('local')->put($existing, 'same bytes');

        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', contents: 'same bytes');
        $source = $item->absoluteFilePath();

        $target = $this->organizer->organize($item);

        $this->assertSame($existing, $target);
        $this->assertFileDoesNotExist($source);
    }

    public function test_a_filed_item_reports_itself_as_already_filed(): void
    {
        // organize() returns null both for "already filed" and "failed", so
        // callers need this to tell a no-op from a problem.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', track: 3);
        $this->organizer->organize($item);

        $this->assertTrue($this->organizer->isAlreadyFiled($item->fresh()));
    }

    /* -------------------------------------------------------- helpers --- */

    private function music(string $title, ?string $artist, ?string $album = null, ?int $track = null, string $contents = 'audio'): MediaItem
    {
        $item = $this->item($title, MediaItemType::Music, 'mp3', $contents);
        $item->musicMetadata()->create([
            'artist' => $artist,
            'album' => $album,
            'track_number' => $track,
        ]);

        return $item->fresh();
    }

    private function movie(string $title, ?int $year): MediaItem
    {
        $item = $this->item($title, MediaItemType::Movie, 'mkv');
        $item->movieMetadata()->create(['release_year' => $year]);

        return $item->fresh();
    }

    private function book(string $title, ?string $author): MediaItem
    {
        $item = $this->item($title, MediaItemType::Book, 'epub');
        $item->bookMetadata()->create(['author' => $author]);

        return $item->fresh();
    }

    private function episode(string $series, ?int $season, ?int $episode, ?string $episodeTitle = null): MediaItem
    {
        $parent = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Show,
            'title' => $series,
            'owned' => true,
        ]);
        $parent->showMetadata()->create([]);

        $item = $this->item($episodeTitle ?? $series, MediaItemType::Show, 'mkv');
        $item->forceFill(['parent_id' => $parent->id])->save();
        $item->showMetadata()->create([
            'season_number' => $season,
            'episode_number' => $episode,
            'episode_title' => $episodeTitle,
        ]);

        return $item->fresh();
    }

    /**
     * A catalogued item with a real file on the faked disk.
     *
     * Exact confidence by default so tests read as being about paths; the
     * gate itself is covered by its own cases above.
     */
    private function item(string $title, MediaItemType $type, string $extension, string $contents = 'x'): MediaItem
    {
        $path = 'media/unsorted/' . str($title)->slug() . '.' . $extension;
        Storage::disk('local')->put($path, $contents);

        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);
    }
}
