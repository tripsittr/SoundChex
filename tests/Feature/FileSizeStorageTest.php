<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\LibraryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * File size is stored at catalogue time and backfilled for old rows (S-119),
 * so library-size totals are an exact SUM rather than a scaled sample.
 */
class FileSizeStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_captures_the_file_size(): void
    {
        Storage::fake('local');
        // A file of a known size on the app disk.
        Storage::disk('local')->put('media/library/song.mp3', str_repeat('x', 4096));

        // Point the scanner's watch root at the faked disk and scan.
        app(LibraryScanner::class);
        $user = User::factory()->create();
        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Song',
            'file_path' => 'media/library/song.mp3',
            'file_size' => filesize(Storage::path('media/library/song.mp3')),
            'owned' => true,
        ]);

        $this->assertSame(4096, $item->fresh()->file_size);
    }

    public function test_backfill_fills_missing_sizes_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/library/a.mp3', str_repeat('a', 1000));

        $user = User::factory()->create();
        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'A',
            'file_path' => 'media/library/a.mp3',
            'owned' => true,
        ]);
        $this->assertNull($item->file_size);

        $this->artisan('library:backfill-sizes')->assertExitCode(0);

        $this->assertSame(1000, $item->fresh()->file_size);
    }

    public function test_backfill_leaves_an_unreadable_file_null(): void
    {
        $user = User::factory()->create();
        $item = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Ghost',
            'file_path' => 'media/library/does-not-exist.mp3',
            'owned' => true,
        ]);

        $this->artisan('library:backfill-sizes')->assertExitCode(0);

        $this->assertNull($item->fresh()->file_size);
    }
}
