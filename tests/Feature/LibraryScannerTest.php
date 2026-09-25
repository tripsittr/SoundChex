<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\MetadataVersion;
use App\Models\Scopes\ResolvedScope;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The scanner decides what enters the library and under what name, so a wrong
 * decision here propagates into filing, enrichment and the whole catalogue.
 *
 * The cases that matter most are the ones where it must *not* act: a file
 * still being copied, one already catalogued under a different path shape, and
 * a filename with no readable title.
 */
class LibraryScannerTest extends TestCase
{
    use RefreshDatabase;

    private LibraryScanner $scanner;

    private User $user;

    private string $watched;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->scanner = app(LibraryScanner::class);

        // A real directory on the faked disk. The scanner walks the filesystem
        // rather than the Storage abstraction, so it needs actual files —
        // faked here, never the real library.
        $this->watched = Storage::disk('local')->path('watched');
        @mkdir($this->watched, 0755, true);

        config()->set('library.watch_folders', [$this->watched]);

        // Storage sweeping would pull in the whole faked disk; these tests are
        // about the watch folder.
        app(SettingsService::class)->set('library_scan_storage', false);
        app(SettingsService::class)->set('library_settle_seconds', 0);
    }

    /* ------------------------------------------------------ cataloguing -- */

    public function test_it_records_an_intake_snapshot_of_the_arrival_state(): void
    {
        // The name the file arrived under, before the organiser renames it or
        // enrichment rewrites the title (S-21).
        file_put_contents($this->watched.'/1041. You are in Love with a Psycho - Kasabian.mp3', 'x');

        $this->scanner->scan();

        $item = MediaItem::unresolved()->firstOrFail();

        $intake = MetadataVersion::where('media_item_id', $item->id)
            ->where('reason', MetadataVersion::REASON_IMPORT)
            ->firstOrFail();

        $this->assertSame(
            '1041. You are in Love with a Psycho - Kasabian.mp3',
            $intake->snapshot['intake']['file_name'],
            'The intake snapshot must keep the original filename.',
        );
        $this->assertArrayHasKey('file_path', $intake->snapshot['intake']);
        $this->assertArrayHasKey('file_size', $intake->snapshot['intake']);
    }

    public function test_a_re_scan_does_not_add_a_second_intake_snapshot(): void
    {
        file_put_contents($this->watched.'/Backrooms.mkv', 'x');

        $this->scanner->scan();
        $this->scanner->scan();

        $item = MediaItem::unresolved()->firstOrFail();

        $this->assertSame(
            1,
            MetadataVersion::where('media_item_id', $item->id)
                ->where('reason', MetadataVersion::REASON_IMPORT)
                ->count(),
        );
    }

    public function test_scanning_twice_does_not_catalogue_the_same_file_twice(): void
    {
        // On Windows it did, every single time. The known list is keyed on
        // Storage::path() — `…\storage\app/private\media\…`, mixed separators —
        // and looked up with getRealPath()'s `…\media\…`. The two never matched
        // as strings, so every scan catalogued the whole library again. One
        // film in the real catalogue had nine rows, one per scan.
        file_put_contents($this->watched.'/Jackass Number Two.avi', 'x');

        $this->scanner->scan();
        $this->scanner->scan();
        $this->scanner->scan();

        $this->assertSame(
            1,
            MediaItem::unresolved()->where('title', 'like', '%Jackass%')->count(),
            'The same file was catalogued more than once.',
        );
    }

    public function test_a_path_stored_with_the_other_separator_still_counts_as_known(): void
    {
        // What the organiser leaves behind on Windows — S-86. A row stored
        // that way must still be recognised, or rescanning duplicates it.
        file_put_contents($this->watched.'/Backrooms.mkv', 'x');

        $this->scanner->scan();

        $item = MediaItem::unresolved()->where('title', 'like', '%Backrooms%')->firstOrFail();

        $item->forceFill([
            'file_path' => str_replace('/', '\\', (string) $item->file_path),
        ])->saveQuietly();

        $this->scanner->scan();

        $this->assertSame(1, MediaItem::unresolved()->where('title', 'like', '%Backrooms%')->count());
    }

    public function test_an_mp4_holding_only_audio_is_music_not_a_film(): void
    {
        // Three Spotify exports were catalogued as films because they had been
        // written to .mp4, which is configured as a film extension. The
        // extension is a guess about the container; the streams are the answer.
        file_put_contents($this->watched.'/1044. Lights Out - Royal Blood.mp4', 'x');

        Process::fake([
            '*' => Process::result(
                output: json_encode(['streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']]]),
            ),
        ]);

        $this->scanner->scan();

        $item = MediaItem::unresolved()->where('title', 'like', '%Lights Out%')->first();

        $this->assertNotNull($item);
        $this->assertSame(MediaItemType::Music, $item->type);
    }

    public function test_an_mp4_with_video_is_still_a_film(): void
    {
        // The other direction, and the one that would quietly empty the film
        // list if this were got wrong.
        file_put_contents($this->watched.'/Backrooms 2026.mp4', 'x');

        Process::fake([
            '*' => Process::result(
                output: json_encode(['streams' => [
                    ['codec_type' => 'video', 'codec_name' => 'h264'],
                    ['codec_type' => 'audio', 'codec_name' => 'aac'],
                ]]),
            ),
        ]);

        $this->scanner->scan();

        $item = MediaItem::unresolved()->where('title', 'like', '%Backrooms%')->first();

        $this->assertNotNull($item);
        $this->assertSame(MediaItemType::Movie, $item->type);
    }

    public function test_a_film_stays_a_film_when_ffprobe_cannot_answer(): void
    {
        // ffprobe is optional in this project. A probe that failed and was read
        // as "no video" would retype every film in the library the first time
        // it went missing.
        file_put_contents($this->watched.'/Elf 2003.mp4', 'x');

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'not found', exitCode: 1),
        ]);

        $this->scanner->scan();

        $item = MediaItem::unresolved()->where('title', 'like', '%Elf%')->first();

        $this->assertNotNull($item);
        $this->assertSame(MediaItemType::Movie, $item->type);
    }

    public function test_it_catalogues_a_new_file(): void
    {
        $this->file('Backrooms 2026 1080p WEB-DL.mkv');

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(1, $result['imported']);

        // The year is kept: it is what identifies the film to a metadata
        // source, and two films share a title often enough to matter.
        $this->assertSame('Backrooms 2026', MediaItem::unresolved()->first()->title);
    }

    public function test_it_strips_release_tags_from_the_title(): void
    {
        // The catalogue is what the user reads. "1080p x265-GROUP" is noise,
        // and it also poisons the metadata lookup.
        $this->file('Severance.S02E07.2160p.HDR.x265-NTb.mkv');

        $this->scanner->scan(enrich: false);

        $this->assertStringNotContainsString('2160p', MediaItem::unresolved()->first()->title);
        $this->assertStringNotContainsString('NTb', MediaItem::unresolved()->first()->title);
    }

    public function test_an_episode_is_classified_as_a_show_not_a_movie(): void
    {
        // Extension cannot separate the two — both are .mkv — so the filename
        // decides. Getting this wrong files episodes as unrelated films.
        $this->file('The.Bear.S01E02.1080p.mkv');

        $this->scanner->scan(enrich: false);

        $episode = MediaItem::unresolved()->where('type', MediaItemType::Show)->first();

        $this->assertNotNull($episode);
        $this->assertSame(1, $episode->showMetadata->season_number);
        $this->assertSame(2, $episode->showMetadata->episode_number);
    }

    public function test_episodes_hang_off_a_single_series_row(): void
    {
        // Otherwise a season is ten unrelated items in the grid.
        $this->file('The.Bear.S01E01.mkv');
        $this->file('The.Bear.S01E02.mkv');

        $this->scanner->scan(enrich: false);

        $parents = MediaItem::unresolved()->whereNull('parent_id')
            ->where('type', MediaItemType::Show)
            ->get();

        $this->assertCount(1, $parents);

        // Unscoped: the scanner catalogues episodes as `pending`, and the
        // library hides those until enrichment finishes (S-396). This test is
        // about how the scanner files them, not about what the library shows.
        $episodes = $parents->first()->episodes()
            ->withoutGlobalScope(ResolvedScope::class)
            ->get();

        $this->assertCount(2, $episodes);
    }

    public function test_a_film_with_a_year_is_not_read_as_an_episode(): void
    {
        // "Blade Runner 2049" is not season 20 episode 49.
        $this->file('Blade Runner 2049.mkv');

        $this->scanner->scan(enrich: false);

        $this->assertSame(MediaItemType::Movie, MediaItem::unresolved()->first()->type);
    }

    public function test_it_seeds_a_book_author_from_the_filename(): void
    {
        // The one piece of evidence about a book that doesn't come from the
        // lookup being checked. The recognised shape is "Title by Author",
        // which is how ebook files are usually named.
        $this->file('The Hobbit by J.R.R. Tolkien.epub');

        $this->scanner->scan(enrich: false);

        $this->assertSame('J.R.R. Tolkien', MediaItem::unresolved()->first()->bookMetadata->author);
    }

    /* --------------------------------------------------------- refusals -- */

    public function test_it_skips_a_file_that_is_still_being_copied(): void
    {
        // A half-written file would be catalogued with whatever partial tags
        // were readable, then never re-examined.
        app(SettingsService::class)->set('library_settle_seconds', 3600);

        $this->file('Backrooms 2026.mkv');

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['unsettled']);
        $this->assertSame(0, MediaItem::unresolved()->count());
    }

    public function test_it_does_not_catalogue_the_same_file_twice(): void
    {
        $this->file('Backrooms 2026.mkv');

        $this->scanner->scan(enrich: false);
        $second = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, MediaItem::unresolved()->count());
    }

    public function test_a_stored_relative_path_still_counts_as_known(): void
    {
        // Uploads store a disk-relative path while the importer stores an
        // absolute one. Comparing the raw strings lets one file in twice.
        $absolute = $this->file('Backrooms 2026.mkv');
        $relative = ltrim(str_replace(Storage::disk('local')->path(''), '', $absolute), '/');

        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => $relative,
            'owned' => true,
        ]);

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, MediaItem::unresolved()->count());
    }

    public function test_a_converted_copy_is_not_catalogued_as_its_own_film(): void
    {
        // A conversion written anywhere the scanner reaches would otherwise
        // become a second movie named after the output file.
        $converted = $this->file('Backrooms 2026.mp4');
        $relative = ltrim(str_replace(Storage::disk('local')->path(''), '', $converted), '/');

        MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => '/elsewhere/Backrooms 2026.mkv',
            'converted_path' => $relative,
            'owned' => true,
        ]);

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $result['imported']);
    }

    public function test_it_ignores_files_that_are_not_media(): void
    {
        $this->file('cover.jpg');
        $this->file('notes.txt');
        $this->file('Backrooms 2026.mkv');

        $this->scanner->scan(enrich: false);

        $this->assertSame(1, MediaItem::unresolved()->count());
    }

    public function test_a_dry_run_catalogues_nothing(): void
    {
        $this->file('Backrooms 2026.mkv');

        $result = $this->scanner->scan(dryRun: true, enrich: false);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, MediaItem::unresolved()->count());
    }

    public function test_scanning_no_folders_is_a_no_op(): void
    {
        config()->set('library.watch_folders', []);

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $result['folders']);
        $this->assertSame(0, MediaItem::unresolved()->count());
    }

    /* ------------------------------------------------------ enrichment --- */

    public function test_it_queues_enrichment_for_a_new_item(): void
    {
        $this->file('Backrooms 2026.mkv');

        $this->scanner->scan();

        Queue::assertPushed(EnrichMediaItemJob::class);
    }

    public function test_enrichment_can_be_suppressed(): void
    {
        // The scheduled scan enriches; a bulk import of a thousand files
        // should not queue a thousand lookups before the user has looked.
        $this->file('Backrooms 2026.mkv');

        $this->scanner->scan(enrich: false);

        Queue::assertNotPushed(EnrichMediaItemJob::class);
    }

    /* -------------------------------------------------------- helpers --- */

    /** Writes a real file into the watched folder and returns its path. */
    private function file(string $name, string $contents = 'media bytes'): string
    {
        $path = $this->watched.'/'.$name;

        file_put_contents($path, $contents);

        return $path;
    }
}
