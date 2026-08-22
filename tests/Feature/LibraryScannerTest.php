<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryScanner;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_it_catalogues_a_new_file(): void
    {
        $this->file('Backrooms 2026 1080p WEB-DL.mkv');

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(1, $result['imported']);

        // The year is kept: it is what identifies the film to a metadata
        // source, and two films share a title often enough to matter.
        $this->assertSame('Backrooms 2026', MediaItem::first()->title);
    }

    public function test_it_strips_release_tags_from_the_title(): void
    {
        // The catalogue is what the user reads. "1080p x265-GROUP" is noise,
        // and it also poisons the metadata lookup.
        $this->file('Severance.S02E07.2160p.HDR.x265-NTb.mkv');

        $this->scanner->scan(enrich: false);

        $this->assertStringNotContainsString('2160p', MediaItem::first()->title);
        $this->assertStringNotContainsString('NTb', MediaItem::first()->title);
    }

    public function test_an_episode_is_classified_as_a_show_not_a_movie(): void
    {
        // Extension cannot separate the two — both are .mkv — so the filename
        // decides. Getting this wrong files episodes as unrelated films.
        $this->file('The.Bear.S01E02.1080p.mkv');

        $this->scanner->scan(enrich: false);

        $episode = MediaItem::where('type', MediaItemType::Show)->first();

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

        $parents = MediaItem::whereNull('parent_id')
            ->where('type', MediaItemType::Show)
            ->get();

        $this->assertCount(1, $parents);
        $this->assertCount(2, $parents->first()->episodes);
    }

    public function test_a_film_with_a_year_is_not_read_as_an_episode(): void
    {
        // "Blade Runner 2049" is not season 20 episode 49.
        $this->file('Blade Runner 2049.mkv');

        $this->scanner->scan(enrich: false);

        $this->assertSame(MediaItemType::Movie, MediaItem::first()->type);
    }

    public function test_it_seeds_a_book_author_from_the_filename(): void
    {
        // The one piece of evidence about a book that doesn't come from the
        // lookup being checked. The recognised shape is "Title by Author",
        // which is how ebook files are usually named.
        $this->file('The Hobbit by J.R.R. Tolkien.epub');

        $this->scanner->scan(enrich: false);

        $this->assertSame('J.R.R. Tolkien', MediaItem::first()->bookMetadata->author);
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
        $this->assertSame(0, MediaItem::count());
    }

    public function test_it_does_not_catalogue_the_same_file_twice(): void
    {
        $this->file('Backrooms 2026.mkv');

        $this->scanner->scan(enrich: false);
        $second = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, MediaItem::count());
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
        $this->assertSame(1, MediaItem::count());
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

        $this->assertSame(1, MediaItem::count());
    }

    public function test_a_dry_run_catalogues_nothing(): void
    {
        $this->file('Backrooms 2026.mkv');

        $result = $this->scanner->scan(dryRun: true, enrich: false);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, MediaItem::count());
    }

    public function test_scanning_no_folders_is_a_no_op(): void
    {
        config()->set('library.watch_folders', []);

        $result = $this->scanner->scan(enrich: false);

        $this->assertSame(0, $result['folders']);
        $this->assertSame(0, MediaItem::count());
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
        $path = $this->watched . '/' . $name;

        file_put_contents($path, $contents);

        return $path;
    }
}
