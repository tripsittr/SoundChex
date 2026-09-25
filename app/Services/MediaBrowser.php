<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Assembles the rows shown on the media center browse pages.
 *
 * SoundChex is self-hosted — one server, one library — so these queries are
 * scoped only by media type. Every signed-in user sees the same catalog.
 */
class MediaBrowser
{
    public function __construct(private ContentGate $gate) {}

    /** Rails look sparse below this and are hidden instead. */
    private const MIN_ROW_ITEMS = 1;

    private const ROW_LIMIT = 20;

    /**
     * The newest arrivals of a type, on their own.
     *
     * Split out from `rowsForType()` because the home page wants this rail and
     * nothing else. Asking for the whole browse page and keeping the first row
     * ran eight queries per type and threw seven away — and each of those
     * carried a full eager-load batch behind it, which is most of what the home
     * page used to cost.
     *
     * @param  MediaItemType|array<int, MediaItemType>  $type
     * @return Collection<int, MediaItem>
     */
    public function recentlyAdded(MediaItemType|array $type, int $limit = self::ROW_LIMIT): Collection
    {
        return $this->base($type)->latest()->limit($limit)->get();
    }

    /**
     * The rails for a single media type's browse page.
     *
     * @return array<int, array{key: string, title: string, items: Collection<int, MediaItem>}>
     */
    public function rowsForType(MediaItemType|array $type): array
    {
        $rows = [
            [
                'key' => 'recent',
                'title' => 'Recently Added',
                'items' => $this->recentlyAdded($type),
            ],
            [
                'key' => 'top-rated',
                'title' => 'Your Highest Rated',
                'items' => $this->base($type)
                    ->whereNotNull('user_rating')
                    ->orderByDesc('user_rating')
                    ->limit(self::ROW_LIMIT)
                    ->get(),
            ],
            [
                'key' => 'wishlist',
                'title' => 'Wishlist',
                'items' => $this->base($type)->where('wishlist', true)->limit(self::ROW_LIMIT)->get(),
            ],
        ];

        foreach ($this->genreRows($type) as $genreRow) {
            $rows[] = $genreRow;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row) => $row['items']->count() >= self::MIN_ROW_ITEMS,
        ));
    }

    /**
     * One rail per popular genre, so a large library browses by mood rather
     * than by an undifferentiated wall of everything.
     *
     * @return array<int, array{key: string, title: string, items: Collection<int, MediaItem>}>
     */
    private function genreRows(MediaItemType|array $type, int $maxGenres = 4): array
    {
        $types = is_array($type) ? $type : [$type];

        $genres = MediaItem::query()
            ->whereIn('media_items.type', array_map(fn (MediaItemType $t) => $t->value, $types))
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.type', 'genre')
            ->selectRaw('media_tags.value as genre, count(*) as total')
            ->groupBy('media_tags.value')
            ->orderByDesc('total')
            ->limit($maxGenres)
            ->pluck('genre');

        return $genres->map(fn (string $genre) => [
            'key' => 'genre-'.str($genre)->slug(),
            'title' => $genre,
            'items' => $this->base($type)
                ->whereHas('tags', fn (Builder $q) => $q->where('type', 'genre')->where('value', $genre))
                ->limit(self::ROW_LIMIT)
                ->get(),
        ])->all();
    }

    /**
     * Books this user has started but not finished, most recently read first.
     *
     * Scoped to the signed-in user because the library is shared — two people
     * reading the same book keep separate places.
     *
     * @return Collection<int, MediaItem>
     */
    public function continueReading(int $limit = 20): Collection
    {
        $viewer = $this->viewerScope();

        if ($viewer === null) {
            return MediaItem::query()->whereRaw('1 = 0')->get();
        }

        return $this->gate->apply(MediaItem::query())
            ->where('type', MediaItemType::Book)
            ->whereHas('readingProgress', fn (Builder $q) => $q
                ->where($viewer['column'], $viewer['id'])
                ->where('finished', false)
                // 0% means "opened but never turned a page", which isn't
                // meaningfully in progress.
                ->where('percent', '>', 0))
            ->with(['bookMetadata', 'tags'])
            ->join('reading_progress', 'reading_progress.media_item_id', '=', 'media_items.id')
            ->where('reading_progress.'.$viewer['column'], $viewer['id'])
            ->orderByDesc('reading_progress.updated_at')
            ->select('media_items.*')
            ->limit($limit)
            ->get();
    }

    /**
     * The services a library actually has content from, for "Browse by
     * Service".
     *
     * Combines both notions: where a copy came from (`source_service`) and
     * where a title streams now (`media_availability`). A service with nothing
     * behind it is omitted rather than shown as an empty tile.
     *
     * @param  array<int, MediaItemType>  $types
     * @return array<int, array{slug: string, name: string, color: string, count: int}>
     */
    public function servicesFor(array $types): array
    {
        $typeValues = array_map(fn (MediaItemType $type) => $type->value, $types);

        $owned = MediaItem::query()
            ->whereIn('type', $typeValues)
            ->whereNotNull('source_service')
            ->selectRaw('source_service as slug, count(*) as total')
            ->groupBy('source_service')
            ->pluck('total', 'slug')
            ->all();

        $streaming = MediaItem::query()
            ->whereIn('media_items.type', $typeValues)
            ->join('media_availability', 'media_availability.media_item_id', '=', 'media_items.id')
            ->where('media_availability.region', strtoupper((string) config('providers.region', 'US')))
            ->selectRaw('media_availability.provider_slug as slug, count(distinct media_items.id) as total')
            ->groupBy('media_availability.provider_slug')
            ->pluck('total', 'slug')
            ->all();

        $services = [];

        foreach ((array) config('providers.services', []) as $slug => $service) {
            // An item can be both owned from and streamable on a service, so
            // the larger figure is the honest count rather than their sum.
            $count = max($owned[$slug] ?? 0, $streaming[$slug] ?? 0);

            if ($count === 0) {
                continue;
            }

            $services[] = [
                'slug' => $slug,
                'name' => $service['name'],
                'color' => $service['color'],
                'count' => $count,
            ];
        }

        usort($services, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $services;
    }

    /**
     * Films and shows this user started but didn't finish, most recent first.
     *
     * A play row only counts as "in progress" once it has a real position:
     * opening something and closing it immediately shouldn't fill the rail.
     *
     * @return Collection<int, MediaItem>
     */
    public function continueWatching(int $limit = 20): Collection
    {
        $viewer = $this->viewerScope();

        if ($viewer === null) {
            return MediaItem::query()->whereRaw('1 = 0')->get();
        }

        return $this->gate->apply(MediaItem::query())
            ->whereIn('type', [MediaItemType::Movie, MediaItemType::Show])
            ->whereNotNull('file_path')
            ->join('media_plays', 'media_plays.media_item_id', '=', 'media_items.id')
            ->where('media_plays.'.$viewer['column'], $viewer['id'])
            ->where('media_plays.completed', false)
            // A minute in is enough to mean the film was actually started.
            ->where('media_plays.position_seconds', '>', 60)
            ->with(['movieMetadata', 'showMetadata', 'tags'])
            ->orderByDesc('media_plays.updated_at')
            ->select('media_items.*')
            // One row per item even if several play sessions exist.
            ->distinct()
            ->limit($limit)
            ->get();
    }

    /**
     * The item featured in the hero banner. Prefers something with artwork —
     * a hero with no backdrop is the one place a missing image really shows.
     */
    public function hero(MediaItemType|array $type): ?MediaItem
    {
        // One query, not two. Ordering by "has artwork" first gives the same
        // answer as trying the filtered query and falling back — and when
        // artwork exists, which is the normal case, the fallback query used to
        // run its whole eager-load batch only to be thrown away.
        return $this->base($type)
            ->orderByRaw('cover_image_url is null')
            ->latest()
            ->first();
    }

    /**
     * Paginated grid for "view all" browsing, with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function grid(MediaItemType|array $type, array $filters = [], int $perPage = 48)
    {
        $query = $this->base($type);

        if (filled($filters['search'] ?? null)) {
            $term = $filters['search'];

            // A combined page passes several types, so this checks membership
            // rather than identity — `$type === Music` is false for an array
            // and would silently skip the artist/album search.
            $searchesMusic = in_array(
                MediaItemType::Music,
                is_array($type) ? $type : [$type],
                true,
            );

            $query->where(function (Builder $q) use ($term, $searchesMusic): void {
                $q->where('title', 'like', "%{$term}%");

                // Artist/album live on the type-specific metadata table.
                if ($searchesMusic) {
                    $q->orWhereHas('musicMetadata', fn (Builder $m) => $m
                        ->where('artist', 'like', "%{$term}%")
                        ->orWhere('album', 'like', "%{$term}%"));
                }
            });
        }

        if (filled($filters['service'] ?? null)) {
            $service = $filters['service'];

            // Either sense of "on Netflix" counts: a copy that came from the
            // service, or a title streamable there now.
            $query->where(fn (Builder $q) => $q
                ->where('source_service', $service)
                ->orWhereHas('availability', fn (Builder $a) => $a
                    ->where('provider_slug', $service)
                    ->where('region', strtoupper((string) config('providers.region', 'US')))));
        }

        if (filled($filters['genre'] ?? null)) {
            $query->whereHas('tags', fn (Builder $q) => $q
                ->where('type', 'genre')
                ->where('value', $filters['genre']));
        }

        if (($filters['owned'] ?? null) === '1') {
            $query->where('owned', true);
        }

        if (($filters['wishlist'] ?? null) === '1') {
            $query->where('wishlist', true);
        }

        // `latest()` alone is not a stable order. 1,124 tracks here share one
        // `created_at` — a whole import lands in the same second — and a
        // paginator over a non-unique sort returns tied rows in whatever order
        // the engine pleases, which differs between the query for page 1 and
        // the query for page 2. The result is songs repeating across pages and
        // others never appearing. `id` is the tiebreaker: unique, so the total
        // order is deterministic and every row falls on exactly one page.
        return $query
            ->latest()
            ->orderByDesc('media_items.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Every genre present for a type, for the filter dropdown.
     *
     * @return array<int, string>
     */
    public function genresFor(MediaItemType|array $type): array
    {
        $types = is_array($type) ? $type : [$type];

        return $this->gate->apply(MediaItem::query())
            ->whereIn('media_items.type', array_map(fn (MediaItemType $t) => $t->value, $types))
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.type', 'genre')
            ->distinct()
            ->orderBy('media_tags.value')
            ->pluck('media_tags.value')
            ->all();
    }

    /** Per-type counts for the nav. */
    public function counts(): array
    {
        return MediaItem::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->all();
    }

    /**
     * Cross-type search for the nav search box.
     *
     * @return Collection<int, MediaItem>
     */
    public function search(string $term, int $limit = 24): Collection
    {
        return $this->gate->apply(MediaItem::query())
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$term}%")
                ->orWhereHas('musicMetadata', fn (Builder $m) => $m
                    ->where('artist', 'like', "%{$term}%")
                    ->orWhere('album', 'like', "%{$term}%")))
            ->with('musicMetadata')
            ->limit($limit)
            ->get();
    }

    /**
     * Base query — the single place type scoping and eager loading is applied.
     *
     * Accepts one type or several, so the combined Watch page (movies and
     * shows together) works through the same path as a single-type page.
     *
     * @param  MediaItemType|array<int, MediaItemType>  $type
     * @return Builder<MediaItem>
     */
    private function base(MediaItemType|array $type): Builder
    {
        $types = is_array($type) ? $type : [$type];

        return $this->gate->apply(MediaItem::query())
            ->whereIn('type', array_map(fn (MediaItemType $t) => $t->value, $types))
            // Only the metadata tables the requested types can actually have.
            // Loading all four unconditionally meant a music query also asked
            // `movie_metadata`, `show_metadata` and `book_metadata` for rows it
            // knew were not there — three round-trips per batch, every batch,
            // always empty. A combined query (the Watch page passes movies and
            // shows together) still gets both, because $types says so.
            //
            // `plays` because every music row builds a player payload, and
            // that asks for a resume position — one query per row without it.
            ->with([...self::metadataRelations($types), 'tags', 'plays']);
    }

    /**
     * The metadata relations belonging to a set of types.
     *
     * Keyed off the enum rather than a name convention so a new media type is a
     * compile-time-ish error here rather than a silently missing subtitle.
     *
     * @param  array<int, MediaItemType>  $types
     * @return array<int, string>
     */
    private static function metadataRelations(array $types): array
    {
        $relations = [];

        foreach ($types as $type) {
            $relations[] = match ($type) {
                MediaItemType::Music => 'musicMetadata',
                MediaItemType::Movie => 'movieMetadata',
                MediaItemType::Show => 'showMetadata',
                MediaItemType::Book => 'bookMetadata',
            };
        }

        return array_values(array_unique($relations));
    }

    /**
     * Which column and id identify the viewer.
     *
     * Profiles own history now, but rows written before profiles existed are
     * keyed only to the account — falling back to user_id keeps those visible
     * rather than emptying everyone's Continue Watching on upgrade.
     *
     * @return array{column: string, id: int}|null
     */
    private function viewerScope(): ?array
    {
        $profileId = app(CurrentProfile::class)->id();

        if ($profileId !== null) {
            return ['column' => 'profile_id', 'id' => $profileId];
        }

        $userId = Auth::id();

        return $userId === null ? null : ['column' => 'user_id', 'id' => $userId];
    }
}
