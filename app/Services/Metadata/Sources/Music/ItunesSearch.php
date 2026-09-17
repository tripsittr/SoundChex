<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use Illuminate\Support\Facades\Http;

/**
 * iTunes Search API — no authentication required.
 * Provides high-res artwork, genre, and Apple catalog ID.
 */
class ItunesSearch implements MetadataSource
{
    public function name(): string
    {
        return 'iTunes Search';
    }
    public function priority(): int
    {
        return 5;
    }
    public function requiredSettings(): array
    {
        return [];
    } // no key needed

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music;
    }

    public function enrich(MediaItem $item): void
    {
        $meta = $item->musicMetadata;
        $query = implode(' ', array_filter([$meta?->artist, $meta?->album ?? $item->title]));

        if (empty($query)) {
            return;
        }

        $response = Http::get('https://itunes.apple.com/search', [
            'term'       => $query,
            'media'      => 'music',
            'entity'     => 'album',
            'limit'      => 1,
            'country'    => 'US',
        ]);

        if (! $response->ok()) {
            return;
        }

        $result = $response->json('results.0');

        if (empty($result)) {
            return;
        }

        // Only write cover if not already set by a higher-priority source
        if (empty($item->cover_image_url) && ! empty($result['artworkUrl100'])) {
            $item->cover_image_url = str_replace('100x100', '600x600', $result['artworkUrl100']);
            $item->saveQuietly();
        }

        if (! empty($result['primaryGenreName'])) {
            $item->tags()->firstOrCreate(
                ['type' => 'genre', 'value' => $result['primaryGenreName']],
                ['source' => MediaTagSource::Api->value],
            );
        }
    }
}
