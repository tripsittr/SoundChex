<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\Metadata\Sources\Music\MusicBrainz;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MusicBrainz fills blanks, and only with something (S-355).
 *
 * `fillBlank()` writes a value only where the field is empty. It used to test
 * the field alone, unlike the same method on the OpenLibrary and TMDB sources,
 * so an empty value could be written over a blank field: no better than before,
 * but recorded as though a source had answered.
 *
 * It was safe only because both call sites strip empties before calling. That
 * is safety at the call site rather than in the method, and a third caller
 * would not inherit it — which is what these pin down.
 */
class MusicBrainzFillBlankTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_a_blank_field(): void
    {
        $item = $this->track();

        $this->fillBlank($item, ['label' => 'Sub Pop']);

        $this->assertSame('Sub Pop', $item->fresh()->musicMetadata->label);
    }

    public function test_it_does_not_overwrite_a_field_that_already_has_a_value(): void
    {
        $item = $this->track();
        $item->musicMetadata->update(['label' => 'Matador']);

        $this->fillBlank($item, ['label' => 'Sub Pop']);

        $this->assertSame('Matador', $item->fresh()->musicMetadata->label, 'an existing value wins');
    }

    public function test_it_does_not_write_an_empty_value_over_a_blank_field(): void
    {
        $item = $this->track();

        $this->fillBlank($item, ['label' => '', 'publisher' => null]);

        $meta = $item->fresh()->musicMetadata;

        $this->assertNull($meta->label, 'an empty answer is not an answer');
    }

    /** @param array<string, mixed> $values */
    private function fillBlank(MediaItem $item, array $values): void
    {
        $method = new \ReflectionMethod(MusicBrainz::class, 'fillBlank');
        $method->setAccessible(true);
        $method->invoke(app(MusicBrainz::class), $item, $values);
    }

    private function track(): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => User::factory()->create()->id,
            'type' => MediaItemType::Music,
            'title' => 'A Song',
            'file_path' => 'media/a-song.mp3',
            'owned' => true,
        ]);

        $item->musicMetadata()->create(['artist' => 'An Artist']);

        return $item->fresh();
    }
}
