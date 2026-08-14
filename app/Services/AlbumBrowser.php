<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
            ->selectRaw('music_metadata.album as album')
            ->selectRaw('music_metadata.artist as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->groupBy('music_metadata.album', 'music_metadata.artist')
            ->orderByRaw('LOWER(music_metadata.artist)')
            ->orderByRaw('LOWER(music_metadata.album)')
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
    public function artists(int $perPage = 100): LengthAwarePaginator
    {
        return $this->gate->apply(MediaItem::query())
            ->join('music_metadata', 'music_metadata.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', MediaItemType::Music)
            ->whereNotNull('music_metadata.artist')
            ->where('music_metadata.artist', '!=', '')
            ->selectRaw('music_metadata.artist as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('COUNT(DISTINCT music_metadata.album) as album_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->groupBy('music_metadata.artist')
            ->orderByRaw('LOWER(music_metadata.artist)')
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
            ->selectRaw('music_metadata.album as album')
            ->selectRaw('music_metadata.artist as artist')
            ->selectRaw('COUNT(*) as track_count')
            ->selectRaw('MIN(media_items.id) as sample_item_id')
            ->where('music_metadata.artist', $artist)
            ->groupBy('music_metadata.album', 'music_metadata.artist')
            ->orderByRaw('LOWER(music_metadata.album)')
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
        return $this->gate->apply(MediaItem::query())
            ->where('media_items.type', MediaItemType::Music)
            ->whereHas('musicMetadata', fn (Builder $q) => $q
                ->where('artist', $artist)
                ->where('album', $album))
            ->with('musicMetadata')
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
                ->where('artist', $artist)
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('album')
                    ->orWhere('album', '')))
            ->with('musicMetadata')
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
