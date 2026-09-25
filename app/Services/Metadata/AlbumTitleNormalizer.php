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
     * The words that mark a parenthetical as an *edition* rather than part of
     * the album's name — the tails that split one record into duplicates.
     *
     * Shared by the key builder and the display-spelling preference so the two
     * can never disagree about what counts as an edition: a qualifier stripped
     * for keying but not recognised for display would group two spellings and
     * then pick the edition one as the name to show.
     */
    private const EDITION_KEYWORDS = 'edition|deluxe|remaster|remastered|expanded|version|explicit|bonus|anniversary|mono|stereo|reissue|special|original|super';

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

        // Prefer the plain album name over an "(Deluxe)"/"(Remastered)" one: the
        // edition tail is metadata noise, not the album's name. If any variant has
        // no edition qualifier, choose only among those; the edition spellings can
        // never win the display even when they happen to be more common.
        $plain = array_filter(
            $variantCounts,
            fn (int $count, string $spelling): bool => ! $this->hasEditionQualifier($spelling),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($plain !== []) {
            $variantCounts = $plain;
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
            $key = $this->artistKey($row->artist)."\0".$this->canonicalKey((string) $row->album);
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
            $key = $this->canonicalKey($canonical);

            // Rewrite every row of this album (by artist + canonical key, so the
            // edition/punctuation variants are caught) whose spelling differs from
            // the canonical one. Filtered in PHP by the key, since it is not a
            // plain SQL expression.
            $rows = MusicMetadata::query()
                ->when(
                    filled($group['artist']),
                    fn ($q) => $q->whereRaw('LOWER(TRIM(artist)) = ?', [mb_strtolower(trim((string) $group['artist']))]),
                    fn ($q) => $q->where(fn ($q) => $q->whereNull('artist')->orWhere('artist', '')),
                )
                ->get(['id', 'album']);

            foreach ($rows as $row) {
                if (trim((string) $row->album) === $canonical
                    || $this->canonicalKey((string) $row->album) !== $key) {
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

        $key = $this->canonicalKey($album);

        $variants = MusicMetadata::query()
            ->when(
                filled($artist),
                fn ($q) => $q->whereRaw('LOWER(TRIM(artist)) = ?', [mb_strtolower(trim((string) $artist))]),
            )
            ->get(['album'])
            // Same album by canonical key — the edition/punctuation variants too,
            // not just the same casing.
            ->filter(fn (MusicMetadata $m): bool => $this->canonicalKey((string) $m->album) === $key)
            ->groupBy(fn (MusicMetadata $m): string => trim((string) $m->album))
            ->map->count()
            ->all();

        // Include the incoming spelling as one vote, so a genuinely new album
        // keeps its own casing.
        $variants[trim($album)] = ($variants[trim($album)] ?? 0) + 1;

        return $this->canonicalFor($variants);
    }

    /**
     * A canonical key that groups the *same* album written different ways, while
     * keeping genuinely different releases apart (S-307).
     *
     * Folds away the things that split one album into duplicates — case, smart vs
     * straight quotes, bracket style, and edition/version qualifiers ("(Deluxe)",
     * "(Remastered 2016)", "(U.S. Version)", "(30th Anniversary Edition)") — but
     * deliberately keeps numbered sequels and volumes ("(Part IV)", "(II)"),
     * which are distinct records, by only stripping qualifiers that carry an
     * edition keyword.
     */
    public function canonicalKey(string $album): string
    {
        $key = mb_strtolower(trim($album));

        // Smart quotes and dashes → their plain forms; bracket styles unified.
        $key = strtr($key, [
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-',
            '[' => '(', ']' => ')',
        ]);

        // Drop a parenthetical only when it names an edition/version — never a
        // bare "(II)" or "(Part IV)", which mark a different release.
        $key = preg_replace(
            '/\s*\([^)]*\b(' . self::EDITION_KEYWORDS . ')\b[^)]*\)/iu',
            '',
            $key,
        ) ?? $key;

        // Everything else that is pure punctuation or spacing is noise for keying.
        return $this->stripToKey($key);
    }

    /** Whether an album title carries an edition/version qualifier tail. */
    private function hasEditionQualifier(string $album): bool
    {
        return preg_match(
            '/[\(\[][^)\]]*\b(' . self::EDITION_KEYWORDS . ')\b[^)\]]*[\)\]]/iu',
            $album,
        ) === 1;
    }

    /** The artist half of a group key — case- and punctuation-insensitive. */
    private function artistKey(?string $artist): string
    {
        return $this->stripToKey(mb_strtolower(trim((string) $artist)));
    }

    /**
     * The tail both key builders share: drop all punctuation, collapse runs of
     * whitespace, trim. What is left is comparable across spellings.
     */
    private function stripToKey(string $value): string
    {
        $folded = $this->foldStylisedLetters($value);

        $stripped = preg_replace('/[[:punct:]]/u', '', $folded) ?? $folded;
        $stripped = trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);

        // A title that is *only* punctuation strips to nothing, and every such
        // album then shares the empty key: Ed Sheeran's "=" and "+" and
        // XXXTENTACION's "?" would collapse into one record. Keep the original
        // when there is no letter left to key on — it is a real title, just an
        // unusual one (S-383).
        return $stripped !== '' ? $stripped : mb_strtolower(trim($value));
    }

    /**
     * Turns letters written as symbols back into letters (S-383).
     *
     * A band that writes its name "$UICIDEBOY$" means an S, but punctuation
     * stripping deletes the character outright — so "DIRTIERNASTIER$UICIDE"
     * keyed as "dirtiernastieruicide" while "DirtierNastierSuicide" keyed as
     * "dirtiernastiersuicide", and the same album showed twice on the artist
     * page because the two keys could never meet.
     *
     * Currency symbols only, and deliberately nothing else. Digits were tried
     * and reverted: folding them turns "Blink-182" into "blinki82", "Sum 41"
     * into "sum ai" and "Album 3" into "album e", which would merge albums
     * that are genuinely different — a worse failure than the one being
     * fixed, because a wrong merge hides music while a missed one only
     * duplicates a tile. `!`→i and `@`→a are out for the same reason: a stray
     * exclamation mark is far commoner than leetspeak.
     */
    private function foldStylisedLetters(string $value): string
    {
        return strtr($value, [
            '$' => 's',
            '£' => 'l',
            '€' => 'e',
        ]);
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

        // On a tie, prefer the plainer title — the one without the "(Deluxe)" /
        // "(Remastered)" tail — which is usually the shorter string.
        if (mb_strlen($a) !== mb_strlen($b)) {
            return mb_strlen($a) < mb_strlen($b);
        }

        return strcmp($a, $b) < 0;
    }

    /** Small words conventional title case leaves lowercase (mid-title). */
    private const SMALL_WORDS = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into',
        'nor', 'of', 'on', 'or', 'the', 'to', 'up', 'with', 'over',
    ];
}
