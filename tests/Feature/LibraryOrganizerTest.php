<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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

    public function test_the_filename_does_not_repeat_the_artist(): void
    {
        // The artist is already the folder. Repeating it gives
        // "flipturn/Heavy Colors/03 Chicago - flipturn.mp3" — and the scanner
        // reads a filename back as a title when cataloguing, so a name written
        // that way becomes a title carrying its own artist. That is how 4,243
        // tracks came to print their artist twice.
        $item = $this->music('Chicago - flipturn', artist: 'flipturn', album: 'Heavy Colors', track: 3);

        $this->assertSame(
            'media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3',
            $this->organizer->targetPath($item),
        );
    }

    public function test_a_hyphen_that_is_part_of_the_title_survives(): void
    {
        // Only this track's own artist, only at the end. "Sing - Sing - Sing"
        // is a real title and must reach disk intact.
        $item = $this->music('Sing - Sing - Sing', artist: 'Benny Goodman', album: 'Live', track: 1);

        $this->assertSame(
            'media/library/Music/Benny Goodman/Live/01 Sing - Sing - Sing.mp3',
            $this->organizer->targetPath($item),
        );
    }

    public function test_another_artist_named_in_the_title_survives(): void
    {
        $item = $this->music('Gold - Sia', artist: 'Imagine Dragons', album: 'Night Visions', track: 9);

        $this->assertSame(
            'media/library/Music/Imagine Dragons/Night Visions/09 Gold - Sia.mp3',
            $this->organizer->targetPath($item),
        );
    }


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

    public function test_music_with_its_own_tags_passes_the_confidence_gate(): void
    {
        // Music's looser rule, and the reason for it: the artist and album come
        // from the file's own embedded tags, which are authoritative about the
        // file whatever an online source thinks. 'none' is the real unenriched
        // state — the column defaults to it and is not nullable, so this is
        // what an un-looked-up track actually has.
        //
        // This used to assert that *all* music was exempt, which is what the
        // rule was taken to mean and not what it says: MusicBrainz writes
        // `artist` too, so unconditional exemption filed music on API guesses
        // (#460). The tag is now required, which is what "its own tags" meant.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors');
        $item->forceFill([
            'match_confidence' => MatchConfidence::None,
            'enrichment_report' => ['tagged_artist' => true],
        ])->save();

        $this->assertTrue($this->organizer->canOrganize($item->fresh()));
    }

    public function test_music_with_only_a_guessed_artist_does_not_pass_the_gate(): void
    {
        // The same row without the tag behind it: the artist came from an API
        // text match, so the path would be built from a guess.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors');
        $item->forceFill(['match_confidence' => MatchConfidence::None])->save();

        $this->assertFalse($this->organizer->canOrganize($item->fresh()));
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

    public function test_a_case_only_rename_keeps_the_file(): void
    {
        // The bug this exists for (tracker #454). On a case-insensitive volume
        // -- the macOS and Windows default -- a target that differs from the
        // source only in case is a *different string* but the *same file*.
        //
        // organize() compared the two as strings, so it did not recognise the
        // file as already filed. isSameFile() then hashed both paths and got
        // equal hashes -- because they are one file -- and adoptExisting()
        // unlinked "the redundant copy", which was the only copy.
        //
        // Skipped on a case-sensitive volume, where the premise cannot arise.
        $disk = Storage::disk('local');
        $filed = 'media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3';
        $disk->put($filed, 'the only copy');

        $absolute = $disk->path($filed);
        $recased = dirname($absolute).'/03 CHICAGO.mp3';

        if (! file_exists($recased)) {
            $this->markTestSkipped('The test volume is case-sensitive, so a case-only collision cannot happen.');
        }

        // An item whose stored path is the other spelling of that same file.
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => 'Chicago',
            'file_path' => $recased,
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);
        $item->musicMetadata()->create([
            'artist' => 'flipturn',
            'album' => 'Heavy Colors',
            'track_number' => 3,
        ]);

        $this->organizer->organize($item->fresh());

        // The one thing that must never happen.
        $this->assertFileExists($absolute);
        $this->assertSame('the only copy', $disk->get($filed));
    }

    public function test_a_corrupted_cross_volume_copy_does_not_delete_the_original(): void
    {
        // #462. The copy fallback verified by SIZE only, and a copy interrupted
        // and resumed, or written to a failing disk, can be the right length
        // and the wrong bytes -- after which the original was deleted and the
        // library held a corrupt file as the only copy.
        //
        // Simulated by corrupting the copy in place, which is what a bad write
        // looks like from here: same length, different content.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', contents: 'the real audio');
        $source = $item->absoluteFilePath();

        $this->assertFalse(
            $this->invokeCopyIsFaithful($source, $this->corruptedCopyOf($source)),
            'A same-length, different-content copy must not be called faithful.',
        );
    }

    public function test_a_faithful_copy_is_recognised(): void
    {
        // The other half: a good copy must verify, or nothing would ever file
        // across volumes.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', contents: 'the real audio');
        $source = $item->absoluteFilePath();
        $copy = $source.'.copy';
        copy($source, $copy);

        $this->assertTrue($this->invokeCopyIsFaithful($source, $copy));
    }

    public function test_it_refuses_rather_than_overwriting_when_no_name_is_free(): void
    {
        // uniquePath() used to return the occupied path after 999 attempts,
        // handing the caller a path it would then overwrite. A thousand
        // same-named files is a real problem worth surfacing (#462).
        $disk = Storage::disk('local');
        $directory = 'media/library/Music/flipturn/Heavy Colors';

        $disk->put($directory.'/Chicago.mp3', 'occupied');

        for ($i = 2; $i < 1000; $i++) {
            $disk->put($directory."/Chicago ({$i}).mp3", 'occupied');
        }

        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', contents: 'the new one');
        $source = $item->absoluteFilePath();

        $this->assertNull($this->organizer->organize($item), 'Filing must be refused, not forced.');
        $this->assertFileExists($source, 'The item stays where it is.');
        $this->assertSame(
            'occupied',
            $disk->get($directory.'/Chicago.mp3'),
            'Nothing that was already filed may be overwritten.',
        );
    }

    /* ---------------------------------------------------- album artist -- */

    public function test_a_compilation_files_under_its_album_artist(): void
    {
        // Using the track artist scattered compilations: measured on this
        // library, 160 albums would spread across 429 folders, and the
        // Stranger Things soundtrack split into 14 folders for 14 tracks --
        // one per track (#468).
        $first = $this->compilationTrack('Running Up That Hill', 'Kate Bush', 1);
        $second = $this->compilationTrack('Master of Puppets', 'Metallica', 2);

        $this->assertSame(
            'media/library/Music/Various Artists/Stranger Things Soundtrack/01 Running Up That Hill.mp3',
            $this->organizer->targetPath($first),
        );
        $this->assertSame(
            'media/library/Music/Various Artists/Stranger Things Soundtrack/02 Master of Puppets.mp3',
            $this->organizer->targetPath($second),
            'Both tracks belong on one shelf, whoever performed them.',
        );
    }

    public function test_a_track_with_no_album_artist_still_files_under_its_own(): void
    {
        // Why most of the library is unaffected: a single artist's album has
        // no album-artist tag and needs none.
        $item = $this->music('Chicago', artist: 'flipturn', album: 'Heavy Colors', track: 3);

        $this->assertSame(
            'media/library/Music/flipturn/Heavy Colors/03 Chicago.mp3',
            $this->organizer->targetPath($item),
        );
    }

    private function compilationTrack(string $title, string $artist, int $track): MediaItem
    {
        $path = 'media/unsorted/'.str($title)->slug().'.mp3';
        Storage::disk('local')->put($path, 'audio');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Music,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);

        $item->musicMetadata()->create([
            'artist' => $artist,
            'album_artist' => 'Various Artists',
            'album' => 'Stranger Things Soundtrack',
            'track_number' => $track,
        ]);

        return $item->fresh();
    }

    /* --------------------------------------------------------- sidecars -- */

    public function test_subtitles_travel_with_the_film(): void
    {
        // Nothing moved them before, so filing a film orphaned its captions:
        // the player looks beside the video and they stayed in the inbox.
        $disk = Storage::disk('local');

        $item = $this->movie('Backrooms', year: 2026);
        $source = $item->absoluteFilePath();
        $stem = pathinfo($source, PATHINFO_FILENAME);

        file_put_contents(dirname($source).'/'.$stem.'.srt', 'WEBVTT');
        file_put_contents(dirname($source).'/'.$stem.'.en.srt', 'english');

        $target = $this->organizer->organize($item);

        $this->assertNotNull($target);

        $filedStem = pathinfo($disk->path($target), PATHINFO_FILENAME);
        $filedDir = dirname($disk->path($target));

        $this->assertFileExists($filedDir.'/'.$filedStem.'.srt');
        $this->assertFileExists(
            $filedDir.'/'.$filedStem.'.en.srt',
            'A language suffix must be kept, or the player stops matching it.',
        );
    }

    public function test_a_similarly_named_film_does_not_steal_a_sidecar(): void
    {
        // SubtitleImporter globs `base*.srt`, which lets Alien.mkv claim
        // Aliens.en.srt. Matching on the exact stem is what stops that, and
        // this is the test that would catch it coming back.
        $item = $this->movie('Alien', year: 1979);
        $source = $item->absoluteFilePath();
        $stem = pathinfo($source, PATHINFO_FILENAME);

        // A different film's subtitle, whose name merely starts the same way.
        $neighbour = dirname($source).'/'.$stem.'s.en.srt';
        file_put_contents($neighbour, 'belongs to Aliens');

        $this->organizer->organize($item);

        $this->assertFileExists($neighbour, "Another film's subtitle must be left where it is.");
    }

    public function test_an_existing_sidecar_at_the_target_is_not_overwritten(): void
    {
        // One already filed is more likely to be the right one than the stray
        // being carried in.
        $disk = Storage::disk('local');

        $item = $this->movie('Backrooms', year: 2026);
        $source = $item->absoluteFilePath();
        $stem = pathinfo($source, PATHINFO_FILENAME);
        file_put_contents(dirname($source).'/'.$stem.'.srt', 'the incoming one');

        $filed = 'media/library/Movies/Backrooms (2026)/Backrooms (2026).srt';
        $disk->put($filed, 'the one already there');

        $this->organizer->organize($item);

        $this->assertSame('the one already there', $disk->get($filed));
    }

    public function test_an_unrelated_file_in_the_folder_is_left_alone(): void
    {
        // Only the extensions that belong to a media file travel with it.
        $item = $this->movie('Backrooms', year: 2026);
        $source = $item->absoluteFilePath();

        $unrelated = dirname($source).'/notes.txt';
        file_put_contents($unrelated, 'mine');

        $this->organizer->organize($item);

        $this->assertFileExists($unrelated);
    }

    public function test_sidecar_moves_are_journalled(): void
    {
        // a5's note on #278: raw rename() left an asymmetry -- a crash between
        // the journalled media move and the sidecar moves would orphan a
        // subtitle in the old folder, present and logged but invisible to the
        // reconciler. Journalling them means the sweeper can finish or reverse
        // them like anything else.
        $item = $this->movie('Backrooms', year: 2026);
        $source = $item->absoluteFilePath();
        $stem = pathinfo($source, PATHINFO_FILENAME);

        file_put_contents(dirname($source).'/'.$stem.'.srt', 'WEBVTT');
        file_put_contents(dirname($source).'/'.$stem.'.en.srt', 'english');

        $this->organizer->organize($item);

        $sidecarMoves = \App\Models\FileMove::where('kind', \App\Enums\FileMoveKind::Sidecar)->get();

        $this->assertCount(2, $sidecarMoves, 'Both subtitles should be recorded.');
        $this->assertSame(
            1,
            $sidecarMoves->pluck('batch_id')->unique()->count(),
            'One batch, so an undo puts a film\'s captions back together.',
        );
    }

    public function test_reconciling_a_sidecar_does_not_repoint_the_item_at_it(): void
    {
        // The hazard journalling introduces, and why Sidecar is its own kind.
        // A journalled sidecar carries the media item's id -- that is what
        // relates a subtitle to its film -- and reconcile() repoints
        // `file_path` for any non-trash move with an item attached. Without
        // the exclusion the catalogue would end up aimed at a .srt.
        $item = $this->movie('Backrooms', year: 2026);
        $source = $item->absoluteFilePath();
        $stem = pathinfo($source, PATHINFO_FILENAME);
        file_put_contents(dirname($source).'/'.$stem.'.srt', 'WEBVTT');

        $this->organizer->organize($item);

        $filmPath = $item->fresh()->file_path;

        // Force the sidecar's row back to `started`, as a crash mid-move would.
        \App\Models\FileMove::where('kind', \App\Enums\FileMoveKind::Sidecar)
            ->update(['state' => \App\Enums\FileMoveState::Started->value]);

        app(\App\Services\FileMoveJournal::class)->reconcile();

        $this->assertSame(
            $filmPath,
            $item->fresh()->file_path,
            'The item must still point at the film, not at its subtitle.',
        );
        $this->assertStringEndsNotWith('.srt', (string) $item->fresh()->file_path);
    }

    /* ----------------------------------------------------- path safety -- */

    public function test_a_long_title_is_truncated_by_bytes_not_characters(): void
    {
        // The limit filesystems impose per path component is BYTES -- 255 on
        // ext4, APFS and NTFS -- and mb_substr counts characters. Measured
        // before the fix: a 120-character CJK title produced a 360-byte
        // segment, past the limit, and the move failed with an error that
        // named permissions rather than length.
        $segment = $this->invokeSegment(str_repeat('交響曲', 50));

        $this->assertLessThanOrEqual(255, strlen($segment), 'A path component must fit the filesystem limit.');
        $this->assertTrue(mb_check_encoding($segment, 'UTF-8'), 'Cutting must not split a character in half.');
    }

    public function test_a_short_title_is_left_exactly_as_it_is(): void
    {
        // Truncation must not touch anything that fits.
        $this->assertSame('Heavy Colors', $this->invokeSegment('Heavy Colors'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function reservedNames(): array
    {
        return [
            // Device names reserved since DOS. A file called NUL.mp3 cannot be
            // created on Windows at all -- the call fails rather than
            // producing a badly-named file.
            'CON' => ['CON', 'CON_'],
            'NUL' => ['NUL', 'NUL_'],
            'PRN' => ['PRN', 'PRN_'],
            'AUX' => ['AUX', 'AUX_'],
            'COM1' => ['COM1', 'COM1_'],
            'LPT1' => ['LPT1', 'LPT1_'],
            'lower case with an extension' => ['con.mp3', 'con_.mp3'],
            // And the false positives it must not catch.
            'Concrete is not CON' => ['Concrete', 'Concrete'],
            'Auxiliary is not AUX' => ['Auxiliary', 'Auxiliary'],
            'Communion is not COM1' => ['Communion', 'Communion'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedNames')]
    public function test_windows_reserved_names_are_escaped(string $given, string $expected): void
    {
        $this->assertSame($expected, $this->invokeSegment($given));
    }

    private function invokeSegment(string $value): string
    {
        $method = new \ReflectionMethod($this->organizer, 'segment');

        return (string) $method->invoke($this->organizer, $value);
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
    /** A copy with the same length and different bytes — a bad write. */
    private function corruptedCopyOf(string $source): string
    {
        $copy = $source.'.corrupt';
        $bytes = (string) file_get_contents($source);

        file_put_contents($copy, str_repeat('x', strlen($bytes)));

        return $copy;
    }

    private function invokeCopyIsFaithful(string $source, string $copy): bool
    {
        $method = new \ReflectionMethod(LibraryOrganizer::class, 'copyIsFaithful');

        return $method->invoke($this->organizer, $source, $copy);
    }

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
