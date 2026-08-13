<?php

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\DuplicateDetector;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * This is the only code in the project that deletes a user's file, so the
 * tests are weighted almost entirely toward the cases where it must refuse.
 * A wrongly-deleted track cannot be recovered from a catalogue row.
 */
class DuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(DuplicateDetector::class);
        $this->user = User::factory()->create();
    }

    /* ------------------------------------------------------ detection --- */

    public function test_it_flags_a_byte_identical_copy(): void
    {
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $found = $this->detector->check($copy);

        $this->assertNotNull($found);
        $this->assertSame($original->id, $found->id);
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);
    }

    public function test_it_ignores_files_that_merely_look_similar(): void
    {
        // A remaster, a different bitrate, or a re-encode is a different file.
        // Detection is exact-hash only precisely so a confirmed duplicate is
        // safe to delete.
        $this->item('original.mp3', 'one recording');
        $reencode = $this->item('remaster.mp3', 'a different recording');

        $this->assertNull($this->detector->check($reencode));
        $this->assertNull($reencode->fresh()->duplicate_status);
    }

    public function test_it_does_not_flag_across_media_types(): void
    {
        // A cover image and an audio file could in principle collide; merging
        // across types would be wrong whatever the bytes say.
        $this->item('track.mp3', 'same bytes', MediaItemType::Music);
        $book = $this->item('book.epub', 'same bytes', MediaItemType::Book);

        $this->assertNull($this->detector->check($book));
    }

    public function test_a_decision_already_made_is_never_reopened(): void
    {
        // Re-flagging a pair the user chose to keep would refill the review
        // list forever.
        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->detector->keepBoth($copy);

        $this->assertNull($this->detector->check($copy->fresh()));
        $this->assertSame(DuplicateStatus::Kept, $copy->fresh()->duplicate_status);
    }

    /* -------------------------------------------------------- merging --- */

    public function test_merging_deletes_the_copy_and_keeps_the_original(): void
    {
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertTrue($this->detector->merge($copy->fresh()));
        $this->assertFileDoesNotExist($copyPath);
        $this->assertFileExists($original->absoluteFilePath());
        $this->assertSame(DuplicateStatus::Merged, $copy->fresh()->duplicate_status);
    }

    public function test_the_merged_row_points_at_the_surviving_file(): void
    {
        // The row is kept rather than deleted because playback history and
        // ratings hang off it.
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->detector->check($copy);
        $this->detector->merge($copy->fresh());

        $this->assertSame($original->fresh()->file_path, $copy->fresh()->file_path);
    }

    public function test_it_refuses_to_delete_a_file_that_changed_since_detection(): void
    {
        // The whole reason merge() re-hashes: a hash recorded days ago says
        // nothing about the file as it is now.
        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        Storage::disk('local')->put('media/unsorted/copy.mp3', 'edited since it was flagged');

        $this->assertFalse($this->detector->merge($copy->fresh()));
        $this->assertFileExists($copyPath);

        // Un-flagged so it is judged again rather than staying wrongly marked.
        $this->assertNull($copy->fresh()->duplicate_status);
    }

    public function test_it_refuses_when_the_original_is_gone(): void
    {
        // Without a surviving original there is nothing to fall back to, so
        // deleting the copy would lose the content outright.
        $original = $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        Storage::disk('local')->delete('media/unsorted/original.mp3');

        $this->assertFalse($this->detector->merge($copy->fresh()));
        $this->assertFileExists($copyPath);
    }

    public function test_two_rows_sharing_one_file_delete_nothing(): void
    {
        // A re-import can catalogue the same path twice. There is no redundant
        // copy on disk, only a redundant row.
        $shared = 'media/unsorted/shared.mp3';
        Storage::disk('local')->put($shared, 'one file, two rows');
        $path = Storage::disk('local')->path($shared);

        $first = $this->row('Shared', $path);
        $second = $this->row('Shared', $path);

        $this->detector->check($first);
        $this->detector->check($second);

        $this->assertTrue($this->detector->merge($second->fresh()));
        $this->assertFileExists($path);
        $this->assertSame(DuplicateStatus::Merged, $second->fresh()->duplicate_status);
    }

    /* -------------------------------------------------------- settings -- */

    public function test_detection_can_be_switched_off(): void
    {
        app(SettingsService::class)->set('library_detect_duplicates', false);

        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');

        $this->assertNull($this->detector->check($copy));
    }

    public function test_review_is_the_default_so_nothing_is_deleted_unattended(): void
    {
        // The one feature that deletes files must do nothing until told to.
        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertFileExists($copyPath);
        $this->assertSame(DuplicateStatus::Pending, $copy->fresh()->duplicate_status);
    }

    public function test_auto_mode_deletes_on_detection(): void
    {
        app(SettingsService::class)->set('library_duplicate_action', 'auto');

        $this->item('original.mp3', 'identical bytes');
        $copy = $this->item('copy.mp3', 'identical bytes');
        $copyPath = $copy->absoluteFilePath();

        $this->detector->check($copy);

        $this->assertFileDoesNotExist($copyPath);
        $this->assertSame(DuplicateStatus::Merged, $copy->fresh()->duplicate_status);
    }

    /* -------------------------------------------------------- helpers --- */

    /**
     * A catalogued file that has already been hashed.
     *
     * The lookup matches on a stored content_hash, which is only written when
     * an item is itself checked — so an "original" that was never scanned is
     * invisible to detection. The scanner hashes everything it catalogues, and
     * these fixtures mirror that.
     */
    private function item(string $filename, string $contents, MediaItemType $type = MediaItemType::Music): MediaItem
    {
        $path = 'media/unsorted/' . $filename;
        Storage::disk('local')->put($path, $contents);

        $item = $this->row(pathinfo($filename, PATHINFO_FILENAME), Storage::disk('local')->path($path), $type);

        $this->detector->ensureHashed($item);

        return $item->fresh();
    }

    private function row(string $title, string $path, MediaItemType $type = MediaItemType::Music): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'title' => $title,
            'file_path' => $path,
            'owned' => true,
        ]);
    }
}
