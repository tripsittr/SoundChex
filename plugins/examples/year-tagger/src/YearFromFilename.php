<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\YearTagger;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;

/**
 * Fills a film or show's release year from a four-digit year in its filename,
 * when nothing else supplied one.
 *
 * A plugin's metadata source is the same `MetadataSource` the built-in ones
 * implement — this is what lets it join the pipeline as an equal. It writes only
 * a blank field, so it corrects gaps rather than overriding a real provider.
 */
class YearFromFilename implements MetadataSource
{
    public function name(): string
    {
        return 'Year from filename';
    }

    /** High, so it runs after the real providers and only fills what they left. */
    public function priority(): int
    {
        return 900;
    }

    /** Keyless. */
    public function requiredSettings(): array
    {
        return [];
    }

    public function supports(MediaItem $item): bool
    {
        if (! in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            return false;
        }

        // Nothing to do if the year is already known.
        return blank($this->metadataFor($item)?->release_year);
    }

    public function enrich(MediaItem $item): void
    {
        $meta = $this->metadataFor($item);

        if ($meta === null || filled($meta->release_year)) {
            return;
        }

        $year = $this->yearIn((string) $item->file_path)
            ?? $this->yearIn((string) $item->title);

        if ($year !== null) {
            $meta->forceFill(['release_year' => $year])->save();
        }
    }

    /** The movie or show metadata row, whichever this item carries. */
    private function metadataFor(MediaItem $item): ?object
    {
        return $item->type === MediaItemType::Movie
            ? $item->movieMetadata
            : $item->showMetadata;
    }

    /**
     * A plausible release year (1900–this year + 1) found in a string, or null.
     *
     * Bounded so a resolution ("1080p") or a stray number is not mistaken for a
     * year, and preferring one in parentheses — "Film (2018) 1080p" — the shape
     * a release name almost always uses.
     */
    private function yearIn(string $text): ?int
    {
        $max = (int) date('Y') + 1;

        if (preg_match('/\((\d{4})\)/', $text, $m) && $this->plausible((int) $m[1], $max)) {
            return (int) $m[1];
        }

        if (preg_match_all('/(?<!\d)(\d{4})(?!\d)/', $text, $all)) {
            foreach ($all[1] as $candidate) {
                if ($this->plausible((int) $candidate, $max)) {
                    return (int) $candidate;
                }
            }
        }

        return null;
    }

    private function plausible(int $year, int $max): bool
    {
        return $year >= 1900 && $year <= $max;
    }
}
