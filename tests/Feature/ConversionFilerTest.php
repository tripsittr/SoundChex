<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\ConversionFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The filer moves two real files per item — a conversion into the library and
 * an original into the archive — so the refusals matter more than the successes.
 *
 * The failure that prompted all this: a conversion lived in media/converted/,
 * a folder the scanner is told to skip, reachable only through a database
 * column. Rebuilding the catalogue lost the column, and the film became
 * unplayable while both files sat safely on disk.
 */
class ConversionFilerTest extends TestCase
{
    use RefreshDatabase;

    private ConversionFiler $filer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filer = app(ConversionFiler::class);
        $this->user = User::factory()->create();
    }

    public function test_files_the_conversion_and_archives_the_original(): void
    {
        $item = $this->converted('Backrooms', 2026);

        $result = $this->filer->promote($item);

        $this->assertNotNull($result);

        // The playable copy lands in the library proper, under the same
        // "Title (Year)" convention every other film follows.
        $this->assertSame('media/library/Movies/Backrooms (2026)/Backrooms (2026).mp4', $result['filed']);
        Storage::disk('local')->assertExists($result['filed']);

        // The original is kept, not deleted. It is the better master.
        Storage::disk('local')->assertExists($result['archived']);
        $this->assertStringContainsString('media/archive/', $result['archived']);
    }

    public function test_the_item_points_at_the_filed_conversion_afterwards(): void
    {
        $item = $this->converted('Backrooms', 2026);

        $result = $this->filer->promote($item);
        $item->refresh();

        // What plays is now an ordinary file in the library, found by a scan
        // rather than by a column that a rebuild can lose.
        $this->assertSame(Storage::disk('local')->path($result['filed']), $item->file_path);
        $this->assertNull($item->converted_path);
        $this->assertSame($result['archived'], $item->archived_path);
        $this->assertTrue($item->hasArchivedOriginal());
    }

    public function test_the_original_remains_reachable_after_filing(): void
    {
        $item = $this->converted('Backrooms', 2026);

        $this->filer->promote($item);

        // Archiving must not mean losing: the master is still addressable.
        $this->assertSame(
            Storage::disk('local')->path($item->fresh()->archived_path),
            $item->fresh()->originalPath(),
        );
    }

    public function test_a_dry_run_moves_nothing(): void
    {
        $item = $this->converted('Backrooms', 2026);
        $before = $item->file_path;

        $result = $this->filer->promote($item, dryRun: true);

        $this->assertNotNull($result);

        // It reports the same destinations it would use for real...
        $this->assertSame('media/library/Movies/Backrooms (2026)/Backrooms (2026).mp4', $result['filed']);

        // ...while leaving every byte and every column where it was.
        Storage::disk('local')->assertMissing($result['filed']);
        Storage::disk('local')->assertMissing($result['archived']);
        $this->assertSame($before, $item->fresh()->file_path);
        $this->assertNotNull($item->fresh()->converted_path);
    }

    public function test_refuses_an_item_with_no_conversion(): void
    {
        $item = $this->item('Backrooms', 'mkv');

        $this->assertNull($this->filer->promote($item));
        $this->assertNull($item->fresh()->archived_path);
    }

    public function test_refuses_when_the_conversion_is_missing_from_disk(): void
    {
        $item = $this->converted('Backrooms', 2026);
        Storage::disk('local')->delete($item->converted_path);

        $this->assertNull($this->filer->promote($item->fresh()));

        // The item must keep pointing at the file it still has.
        Storage::disk('local')->assertExists(
            str($item->fresh()->file_path)->after(Storage::disk('local')->path('') . '')->toString(),
        );
    }

    public function test_files_the_conversion_when_the_original_is_gone(): void
    {
        $item = $this->converted('Backrooms', 2026);

        // The original has vanished — deleted, moved by hand, on a drive that
        // is not mounted. The item now points at nothing.
        Storage::disk('local')->delete(
            str($item->file_path)->after(Storage::disk('local')->path('') . '')->toString(),
        );

        $result = $this->filer->promote($item->fresh());
        $item->refresh();

        // Refusing here would leave the item unplayable while a good file sat
        // in a folder the scanner skips. That is the bug this whole change
        // exists to fix, so the filer files it instead.
        $this->assertNotNull($result);
        $this->assertSame('media/library/Movies/Backrooms (2026)/Backrooms (2026).mp4', $result['filed']);
        Storage::disk('local')->assertExists($result['filed']);

        $this->assertNull($item->converted_path);
        $this->assertFileExists($item->file_path);

        // Nothing was archived, because there was nothing to archive.
        $this->assertNull($item->archived_path);
    }

    public function test_refuses_to_move_a_filed_item_into_the_flat_root(): void
    {
        // A film already in its proper folder, but with no year — so the
        // organizer can derive no structure and would fall back to the root.
        $item = $this->convertedAt(
            'media/library/Movies/Backrooms (2026)/Backrooms (2026).mkv',
            year: null,
        );
        $before = $item->file_path;

        $this->assertNull($this->filer->promote($item));

        // Nothing moved, and the item still points where it did. Filing must
        // never leave the library more disorganised than it found it.
        $this->assertSame($before, $item->fresh()->file_path);
        $this->assertFileExists($item->fresh()->file_path);
        $this->assertNotNull($item->fresh()->converted_path);
        Storage::disk('local')->assertMissing('media/library/Backrooms.mp4');
    }

    public function test_files_a_structured_item_once_the_year_is_known(): void
    {
        // The same item, with the year that was missing. Now the organizer has
        // a structure to file into, so the conversion is promoted normally.
        $item = $this->convertedAt(
            'media/library/Movies/Backrooms (2026)/Backrooms (2026).mkv',
            year: 2026,
        );

        $result = $this->filer->promote($item);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Movies/', $result['filed']);
        $this->assertStringEndsWith('.mp4', $result['filed']);
        Storage::disk('local')->assertExists($result['filed']);
    }

    /* -------------------------------------------------------- helpers ---- */

    private function convertedAt(string $path, ?int $year): MediaItem
    {
        Storage::disk('local')->put($path, 'original');

        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);

        $item->movieMetadata()->create(['release_year' => $year]);

        $converted = 'media/converted/backrooms.mp4';
        Storage::disk('local')->put($converted, 'playable');
        $item->forceFill(['converted_path' => $converted])->save();

        return $item->fresh();
    }

    private function converted(string $title, ?int $year): MediaItem
    {
        $item = $this->item($title, 'mkv');
        $item->movieMetadata()->create(['release_year' => $year]);

        $converted = 'media/converted/' . str($title)->slug() . '.mp4';
        Storage::disk('local')->put($converted, 'playable');
        $item->forceFill(['converted_path' => $converted])->save();

        return $item->fresh();
    }

    private function item(string $title, string $extension): MediaItem
    {
        $path = 'media/unsorted/' . str($title)->slug() . '.' . $extension;
        Storage::disk('local')->put($path, 'original');

        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => Storage::disk('local')->path($path),
            'match_confidence' => MatchConfidence::Exact,
            'owned' => true,
        ]);
    }
}
