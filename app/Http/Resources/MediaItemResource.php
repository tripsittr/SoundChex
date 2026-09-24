<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Resources;

use App\Models\MediaItem;
use App\Plugins\Registry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One catalogue entry, as the device stores it.
 *
 * Deliberately explicit rather than a serialised model. Two reasons:
 *
 *   - A model dump leaks whatever columns exist today and silently changes
 *     shape when one is added — including `file_path`, which is a path on the
 *     server's disk and has no business on a phone.
 *   - The device mirrors this into IndexedDB, so the shape is a storage
 *     format. It should change when someone decides to change it.
 *
 * Metadata is flattened by type: a track carries artist and album, a film
 * carries year and rating. The alternative is four nullable sub-objects on
 * every row, which triples the payload for no gain.
 */
class MediaItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MediaItem $item */
        $item = $this->resource;

        $payload = [
            'id' => $item->id,
            'type' => $item->type->value,
            'title' => $item->title,
            'parent_id' => $item->parent_id,
            'subtitle' => $item->subtitle(),
            'artwork' => $item->coverUrl(),
            // Whether it can be played at all: a wishlist row has no bytes
            // behind it, and a device that queues one would stall.
            'playable' => filled($item->file_path),
            'owned' => (bool) $item->owned,
            'wishlist' => (bool) $item->wishlist,
            'user_rating' => $item->user_rating,
            'updated_at' => $item->updated_at?->toIso8601String(),
            'meta' => $this->metadata($item),
        ];

        // The shape every client reads — web player, desktop and the native
        // apps all decode this. A plugin can add a key here and have it reach
        // all of them at once (S-317).
        //
        // Adding is safe; removing or retyping an existing key is not. A
        // client that expects `title` to be a string does not survive it
        // becoming null, and the native apps decode strictly.
        return app(Registry::class)->apply('api.item', $payload, $item);
    }

    /** @return array<string, mixed> */
    private function metadata(MediaItem $item): array
    {
        return match ($item->type->value) {
            'music' => array_filter([
                'artist' => $item->musicMetadata?->artist,
                // The headline artist, for grouping: a "Artist, Someone" track
                // belongs under "Artist", not as its own artist. Falls back to
                // the full credit when not derived. Clients group on this.
                'primary_artist' => $item->musicMetadata?->primary_artist
                    ?: $item->musicMetadata?->artist,
                'album' => $item->musicMetadata?->album,
                // The canonical album key, for grouping (S-308): edition and
                // punctuation variants of one album share it, so a client groups
                // on this instead of the raw album string and never shows the same
                // album twice. Falls back to the album for anything unkeyed.
                'album_key' => $item->musicMetadata?->album_key
                    ?: $item->musicMetadata?->album,
                // Through the accessor: most tags in a real library carry a
                // library-wide position rather than a track number, and
                // sending that would sort albums wrongly on the device too.
                'track_number' => $item->musicMetadata?->trackNumber(),
                'disc_number' => $item->musicMetadata?->discNumber(),
                'duration_ms' => $item->musicMetadata?->duration_ms,
                'release_year' => $item->musicMetadata?->release_year,
            ], fn ($value) => $value !== null),

            'movie' => array_filter([
                'release_year' => $item->movieMetadata?->release_year,
                'mpaa_rating' => $item->movieMetadata?->mpaa_rating,
                'runtime_minutes' => $item->movieMetadata?->runtime_minutes,
                'director' => $item->movieMetadata?->director,
            ], fn ($value) => $value !== null),

            'show' => array_filter([
                'season_number' => $item->showMetadata?->season_number,
                'episode_number' => $item->showMetadata?->episode_number,
                'episode_title' => $item->showMetadata?->episode_title,
                'content_rating' => $item->showMetadata?->content_rating,
            ], fn ($value) => $value !== null),

            'book' => array_filter([
                'author' => $item->bookMetadata?->author,
                'publisher' => $item->bookMetadata?->publisher,
                'pages' => $item->bookMetadata?->pages,
            ], fn ($value) => $value !== null),

            default => [],
        };
    }
}
