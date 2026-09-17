<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\ConversionFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Saying so when a file does not go where it was sent.
 *
 * These services move and delete real media, and every failure path returned
 * null in silence — a file that could not be filed simply stayed where it was,
 * with nothing anywhere saying why. On a server nobody is watching, that is
 * indistinguishable from the scan never having seen it.
 */
class FileOperationsAreLoggedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_says_so_when_a_conversion_cannot_be_filed(): void
    {
        $item = $this->convertedTrack();

        // The destination is taken by a directory, so the move cannot land.
        Storage::disk('local')->makeDirectory('media/library/Movies/Backrooms (2026)/Backrooms (2026).mp4');

        Log::shouldReceive('error')
            ->atLeast()->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'file')
                && ($context['item'] ?? null) === $item->id);

        Log::shouldReceive('warning')->zeroOrMoreTimes();

        app(ConversionFiler::class)->promote($item);
    }

    public function test_a_successful_filing_is_not_noisy(): void
    {
        // A log line for every ordinary success is how a log becomes something
        // nobody reads.
        Log::shouldReceive('error')->never();

        $result = app(ConversionFiler::class)->promote($this->convertedTrack());

        $this->assertNotNull($result);
    }

    private function convertedTrack(): MediaItem
    {
        Storage::disk('local')->put('media/unsorted/backrooms.mkv', 'original');
        Storage::disk('local')->put('media/converted/backrooms.mp4', 'playable');

        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Movie,
            'title' => 'Backrooms',
            'file_path' => Storage::disk('local')->path('media/unsorted/backrooms.mkv'),
            'owned' => true,
        ]);

        $item->movieMetadata()->create(['release_year' => 2026]);
        $item->forceFill(['converted_path' => 'media/converted/backrooms.mp4'])->save();

        return $item->fresh();
    }
}
