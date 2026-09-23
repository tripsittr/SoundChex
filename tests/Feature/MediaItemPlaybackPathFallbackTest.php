<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaItemPlaybackPathFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function writeFile(string $relative, string $bytes = 'audio bytes'): string
    {
        $absolute = Storage::path($relative);
        @mkdir(dirname($absolute), 0775, true);
        file_put_contents($absolute, $bytes);
        $this->createdFiles[] = $absolute;

        return $absolute;
    }

    public function test_a_missing_original_falls_back_to_a_readable_duplicate(): void
    {
        $user = User::factory()->create();

        $original = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => 'media/library/Music/Bakar/Hell N Back/01 Hell N Back.mp3',
        ]);

        $duplicatePath = 'media/library/Music/Bakar/Halo/02 Hell N Back.mp3';
        $this->writeFile($duplicatePath);

        $duplicate = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => $duplicatePath,
        ]);
        $duplicate->forceFill(['duplicate_of_id' => $original->id])->saveQuietly();

        $this->assertSame(Storage::path($duplicatePath), $duplicate->playbackPath());
        $this->assertSame(Storage::path($duplicatePath), $original->fresh()->playbackPath());
    }

    public function test_a_missing_duplicate_falls_back_to_a_readable_original(): void
    {
        $user = User::factory()->create();

        $originalPath = 'media/library/Music/Bakar/Halo/02 Hell N Back.mp3';
        $this->writeFile($originalPath);

        $original = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => $originalPath,
        ]);

        $duplicate = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => 'media/library/Music/Bakar/Hell N Back/01 Hell N Back.mp3',
        ]);
        $duplicate->forceFill(['duplicate_of_id' => $original->id])->saveQuietly();

        $this->assertSame(Storage::path($originalPath), $duplicate->fresh()->playbackPath());
    }

    public function test_a_stale_original_is_repointed_to_a_live_linked_file(): void
    {
        $user = User::factory()->create();

        $stalePath = 'media/library/Music/Bakar/Hell N Back/01 Hell N Back.mp3';
        $livePath = 'media/library/Music/Bakar/Halo/02 Hell N Back.mp3';
        $this->writeFile($livePath);

        $original = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => $stalePath,
        ]);

        $duplicate = MediaItem::create([
            'user_id' => $user->id,
            'type' => MediaItemType::Music,
            'title' => 'Hell N Back',
            'file_path' => $livePath,
        ]);
        $duplicate->forceFill(['duplicate_of_id' => $original->id])->saveQuietly();

        $this->assertSame(Storage::path($livePath), $original->fresh()->playbackPath());
        $this->assertSame($livePath, $original->fresh()->file_path);
    }
}
