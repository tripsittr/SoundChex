<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Models\MusicMetadata;
use Illuminate\Support\Collection;

/**
 * Collapses albums that differ only by capitalization (S-303).
 *
 * Different metadata sources title-case small words differently — "Cage The
 * Elephant" vs "Cage the Elephant", "Have A Nice Day" vs "Have a Nice Day" — so
 * one album ends up split into several. This picks, for each album (per artist,
 * case-insensitively), the spelling that appears most often in the library and
 * rewrites the others to match, so the album collapses into one.
 *
 * "Most common wins" rather than a fixed title-case rule on purpose: the
 * library's own prevailing spelling is the most likely correct one, and it keeps
 * deliberate stylings (all-caps album names, "iPod"-style casing) that a
 * mechanical title-caser would flatten.
 */
class AlbumTitleNormalizer
{
    /**
     * The canonical spelling for one album, given how a set of variants are
     * distributed. Returns the most common spelling; ties break toward the one
     * with more lowercase small-words (the more conventional title case), then
     * alphabetically, so the choice is deterministic.
     *
     * @param  array<string, int>  $variantCounts  spelling => how many tracks use it
     */
    public function canonicalFor(array $variantCounts): ?string
    {
        if ($variantCounts === []) {
            return null;
        }

        $best = null;
        $bestCount = -1;

        foreach ($variantCounts as $spelling => $count) {
            $spelling = (string) $spelling;

            if ($count > $bestCount
                || ($count === $bestCount && $this->prefers($spelling, (string) $best))) {
                $best = $spelling;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * Every album (per artist) that has more than one capitalization in the
     * library, with each spelling's track count, keyed by a case-insensitive
     * "artist\u{0}album" group key.
     *
     * @return Collection<string, array{artist: ?string, album: string, variants: array<string, int>, canonical: string}>
     */
    public function groupsNeedingNormalization(): Collection
    {
        $rows = MusicMetadata::query()
            ->whereNotNull('album')
            ->where('album', '!=', '')
            ->get(['id', 'artist', 'album']);

        $groups = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row->artist))."\0".mb_strtolower(trim((string) $row->album));
            $groups[$key]['artist'] = $row->artist;
            $groups[$key]['variants'][trim((string) $row->album)] =
                ($groups[$key]['variants'][trim((string) $row->album)] ?? 0) + 1;
        }

        return collect($groups)
            ->filter(fn (array $g): bool => count($g['variants']) > 1)
            ->map(function (array $g): array {
                $canonical = $this->canonicalFor($g['variants']);

                return [
                    'artist' => $g['artist'],
                    'album' => $canonical,
                    'variants' => $g['variants'],
                    'canonical' => $canonical,
                ];
            });
    }

    /**
     * Rewrites every album to its library-canonical spelling, collapsing the
     * capitalization duplicates. Returns the number of rows changed.
     */
    public function normalizeLibrary(): int
    {
        $changed = 0;

        foreach ($this->groupsNeedingNormalization() as $group) {
            $canonical = $group['canonical'];

            // Pull the group's rows once, then rewrite only the ones whose exact
            // (case-sensitive) album spelling differs from the canonical. A SQL
            // `LOWER(album) = ?` update would also match the canonical rows —
            // some collations are case-insensitive — and rewrite the whole group.
            $rows = MusicMetadata::query()
                ->whereRaw('LOWER(TRIM(album)) = ?', [mb_strtolower($canonical)])
                ->when(
                    filled($group['artist']),
                    fn ($q) => $q->whereRaw('LOWER(TRIM(artist)) = ?', [mb_strtolower(trim((string) $group['artist']))]),
                    fn ($q) => $q->where(fn ($q) => $q->whereNull('artist')->orWhere('artist', '')),
                )
                ->get(['id', 'album']);

            foreach ($rows as $row) {
                if (trim((string) $row->album) === $canonical) {
                    continue;
                }

                $row->forceFill(['album' => $canonical])->saveQuietly();
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * The canonical spelling to store for a single album, consulting the library
     * so a freshly-enriched track adopts the spelling already prevailing rather
     * than adding a new casing. Falls back to the given spelling when the album is
     * new to the library.
     */
    public function canonicalForAlbum(?string $artist, ?string $album): ?string
    {
        if (blank($album)) {
            return $album;
        }

        $variants = MusicMetadata::query()
            ->whereRaw('LOWER(TRIM(album)) = ?', [mb_strtolower(trim($album))])
            ->when(
                filled($artist),
                fn ($q) => $q->whereRaw('LOWER(TRIM(artist)) = ?', [mb_strtolower(trim((string) $artist))]),
            )
            ->get(['album'])
            ->groupBy(fn (MusicMetadata $m): string => trim((string) $m->album))
            ->map->count()
            ->all();

        // Include the incoming spelling as one vote, so a genuinely new album
        // keeps its own casing.
        $variants[trim($album)] = ($variants[trim($album)] ?? 0) + 1;

        return $this->canonicalFor($variants);
    }

    /**
     * Whether spelling $a is preferred over $b as a tie-breaker: the more
     * conventionally title-cased (more lowercase small connecting words) wins,
     * then the alphabetically earlier, so ties are resolved the same way twice.
     */
    private function prefers(string $a, string $b): bool
    {
        if ($b === '') {
            return true;
        }

        $score = fn (string $s): int => count(array_filter(
            preg_split('/\s+/', $s) ?: [],
            fn (string $w): bool => in_array(mb_strtolower($w), self::SMALL_WORDS, true) && $w === mb_strtolower($w),
        ));

        $sa = $score($a);
        $sb = $score($b);

        if ($sa !== $sb) {
            return $sa > $sb;
        }

        return strcmp($a, $b) < 0;
    }

    /** Small words conventional title case leaves lowercase (mid-title). */
    private const SMALL_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into',
        'nor', 'of', 'on', 'or', 'the', 'to', 'up', 'with', 'over',
    ];
}
