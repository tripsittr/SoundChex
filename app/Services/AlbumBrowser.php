<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Person;
use App\Services\MusicCredits;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Albums, derived from track metadata rather than stored as rows.
 *
 * There is no albums table: an album is whatever a set of tracks agree they
 * belong to. That is deliberate — the tags in the files are the source of
 * truth, so an album appears the moment its tracks are scanned and needs no
 * separate import step or reconciliation.
 *
 * The cost is that "album" is a string match, so two different records sharing
 * a name must be told apart by artist as well. Grouping on artist+album is what
 * stops every "Greatest Hits" in the library collapsing into one.
 */
class AlbumBrowser
{
    public function __construct(private ContentGate $gate) {}

    /**
     * One row per album, with its track count and a cover to show.
     *
     * @return LengthAwarePaginator<int, object>
     */
    public function paginate(int $perPage = 60): LengthAwarePaginator
    {
        return $this->baseQuery()
            // Group on the canonical album_key, not the raw album string, so
            // edition/punctuation variants collapse into one album (S-308). One
            // representative spelling is shown — MIN keeps it stable across pages.
            ->selectRaw('MIN(music_metadata.album) as album')
            ->selectRaw('music_metadata.artist as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->groupBy('music_metadata.album_key', 'music_metadata.artist')
            ->orderByRaw('LOWER(music_metadata.artist)')
            ->orderByRaw('LOWER(MIN(music_metadata.album))')
            // Tiebreakers on the exact group keys, so two albums do not swap
            // places between pages and duplicate.
            ->orderBy('music_metadata.artist')
            ->orderBy('music_metadata.album_key')
            ->paginate($perPage);
    }

    /**
     * Every artist in the library, with what they have.
     *
     * Grouped in SQL rather than by loading tracks and folding them, because
     * the artist list is a browse page and the library is thousands of rows.
     *
     * @return LengthAwarePaginator<int, object>
     */
    /**
     * The artist a track is browsed under.
     *
     * The credits in `media_item_person` are the truth; `primary_artist` is
     * the denormalised index into them, because grouping and pagination happen
     * in SQL. Falls back to the raw credit for anything enrichment has not
     * reached, so a new file is listed under what it says rather than missing.
     */
    private const PRIMARY = "COALESCE(NULLIF(music_metadata.primary_artist, ''), music_metadata.artist)";

    public function artists(int $perPage = 100): LengthAwarePaginator
    {
        return $this->gate->apply(MediaItem::query())
            ->join('music_metadata', 'music_metadata.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', MediaItemType::Music)
            ->whereNotNull('music_metadata.artist')
            ->where('music_metadata.artist', '!=', '')
            // Grouped on the primary rather than the raw credit, or one artist
            // appears once per collaborator they have ever recorded with:
            // "$uicideboy$", "$uicideboy$, Pouya", "$uicideboy$/Shakewell" and
            // so on, each a separate entry in the index.
            //
            // COALESCE rather than a plain column, so a track enrichment has
            // not reached yet still lists under what its file says instead of
            // vanishing from the index entirely.
            ->selectRaw(self::PRIMARY . ' as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('COUNT(DISTINCT music_metadata.album) as album_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->groupBy(DB::raw(self::PRIMARY))
            ->orderByRaw('LOWER(' . self::PRIMARY . ')')
            // Tiebreaker on the exact group key: two artists whose names differ
            // only in case must not trade pages and duplicate.
            ->orderByRaw(self::PRIMARY)
            ->paginate($perPage);
    }

    /**
     * Albums by one artist, for the artist view and "more from" rails.
     *
     * @return Collection<int, object>
     */
    public function forArtist(string $artist): Collection
    {
        return $this->baseQuery()
            ->selectRaw('MIN(music_metadata.album) as album')
            ->selectRaw(self::PRIMARY . ' as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->whereRaw(self::PRIMARY . ' = ?', [$artist])
            // Group on the canonical key so an artist's deluxe/remaster variants
            // list as one album (S-308).
            ->groupBy('music_metadata.album_key', DB::raw(self::PRIMARY))
            ->orderByRaw('LOWER(MIN(music_metadata.album))')
            ->get();
    }

    /**
     * Every track on an album, in playing order.
     *
     * Ordered by disc then track number, with the title as a tiebreak so an
     * album with no numbering at all still lists in a stable, sensible order
     * rather than by insertion.
     *
     * @return Collection<int, MediaItem>
     */
    public function tracks(string $artist, string $album): Collection
    {
        // Match on the canonical album key, so every edition/punctuation variant
        // of the album is listed together (S-308).
        $albumKey = app(\App\Services\Metadata\AlbumTitleNormalizer::class)->canonicalKey($album);

        return $this->gate->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            ->whereHas('musicMetadata', fn (Builder $q) => $q
                ->where('artist', $artist)
                ->where('album_key', $albumKey))
            // `plays` too: every one of these feeds playerPayload(), which
            // asks for a resume position and would query per track without it.
            ->with(['musicMetadata', 'plays'])
            ->get()
            // One comparator rather than sortBy()'s array form.
            //
            // That form compares each closure's value in turn, and a null from
            // discNumber() — which is what an untagged album gives — made it
            // reverse the pair instead of falling through to the track number.
            // Albums listed backwards as a result.
            //
            // Through the accessors, so an implausible tag sorts as absent
            // rather than throwing an album into an arbitrary order.
            ->sort(function (MediaItem $a, MediaItem $b): int {
                return [
                    $a->musicMetadata?->discNumber() ?? 1,
                    $a->musicMetadata?->trackNumber() ?? PHP_INT_MAX,
                    mb_strtolower($a->title ?? ''),
                ] <=> [
                    $b->musicMetadata?->discNumber() ?? 1,
                    $b->musicMetadata?->trackNumber() ?? PHP_INT_MAX,
                    mb_strtolower($b->title ?? ''),
                ];
            })
            ->values();
    }

    /**
     * Tracks by an artist that belong to no album.
     *
     * Singles and loose files would otherwise be unreachable from the artist
     * page, which is where someone would reasonably expect to find everything
     * by that name.
     *
     * @return Collection<int, MediaItem>
     */
    public function singlesForArtist(string $artist): Collection
    {
        return $this->gate->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            ->whereHas('musicMetadata', fn (Builder $q) => $q
                ->whereRaw(self::PRIMARY . ' = ?', [$artist])
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('album')
                    ->orWhere('album', '')))
            // `plays` too: every one of these feeds playerPayload(), which
            // asks for a resume position and would query per track without it.
            ->with(['musicMetadata', 'plays'])
            ->orderBy('title')
            ->get();
    }

    /**
     * Tracks this artist is credited on but did not lead.
     *
     * The collaborations. An artist page matching the credit string exactly
     * showed only the tracks where they recorded alone — $uicideboy$ had 342
     * of those and appears on 446, so 104 were invisible on their own page.
     *
     * Read from the credits rather than by matching the string again: that is
     * what the credits are for, and a LIKE on the artist column would match
     * "The Beatles" inside "The Beatles Tribute Band".
     *
     * @return Collection<int, MediaItem>
     */
    public function appearsOn(string $artist): Collection
    {
        $person = Person::where('name', $artist)->pluck('id');

        if ($person->isEmpty()) {
            return collect();
        }

        return $this->gate->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            // Credited on the track...
            ->whereHas('people', fn (Builder $q) => $q
                ->whereIn('people.id', $person)
                ->where('media_item_person.role', MusicCredits::FEATURED))
            // ...but not the one it is filed under, or the album grid above
            // would list it twice.
            ->whereHas('musicMetadata', fn (Builder $q) => $q
                ->whereRaw(self::PRIMARY . ' != ?', [$artist]))
            // `plays` too: every one of these feeds playerPayload(), which
            // asks for a resume position and would query per track without it.
            ->with(['musicMetadata', 'plays'])
            ->orderBy('title')
            ->get();
    }

    /**
     * Whether an album has more than one disc, so the listing can say so.
     */
    public function isMultiDisc(Collection $tracks): bool
    {
        return $tracks
            ->map(fn (MediaItem $item) => $item->musicMetadata?->discNumber() ?? 1)
            ->unique()
            ->count() > 1;
    }

    /**
     * Total running time in seconds, or null when nothing is tagged with one.
     */
    public function duration(Collection $tracks): ?int
    {
        $known = $tracks
            ->map(fn (MediaItem $item) => $item->musicMetadata?->duration_ms)
            ->filter();

        return $known->isEmpty() ? null : (int) round($known->sum() / 1000);
    }

    /**
     * The gate is applied here rather than at each call site, so an album a
     * profile may not see never appears in a listing or a count.
     */
    private function baseQuery(): Builder
    {
        return $this->gate->apply(MediaItem::query())
            ->join('music_metadata', 'music_metadata.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', MediaItemType::Music)
            ->whereNotNull('music_metadata.album')
            ->where('music_metadata.album', '!=', '');
    }
}
