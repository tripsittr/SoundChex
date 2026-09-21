<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use App\Models\PageText;
use App\Models\Person;
use App\Models\SubtitleCue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One search across everything in the library.
 *
 * Titles are the obvious part. The rest is what a self-hosted library can do
 * and a streaming service cannot: search the dialogue of a film and jump to
 * the scene, or search the text of a book and open it at the page.
 *
 * Every query passes through ContentGate. A kids profile must not reach an
 * R-rated film through one line of its dialogue — that is exactly the hole a
 * partially-applied filter leaves.
 */
class SearchService
{
    /** Results per group before "show all". */
    private const GROUP_LIMIT = 8;

    /** Characters of context either side of a full-text hit. */
    private const SNIPPET_PADDING = 70;

    public function __construct(private ContentGate $gate) {}

    /**
     * @return array{
     *     query: string,
     *     total: int,
     *     groups: array<int, array{key: string, label: string, hint: string|null, results: Collection}>
     * }
     */
    public function search(string $term, int $perGroup = self::GROUP_LIMIT): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return ['query' => $term, 'total' => 0, 'groups' => []];
        }

        $groups = array_values(array_filter([
            $this->group('titles', 'Titles', null, $this->titles($term, $perGroup)),
            $this->group('people', 'Cast & Creators', null, $this->people($term, $perGroup)),
            $this->group('dialogue', 'Dialogue', 'Spoken lines from subtitles', $this->dialogue($term, $perGroup)),
            $this->group('pages', 'Inside Books', 'Text from book pages', $this->pages($term, $perGroup)),
            $this->group('tags', 'Genres & Tags', null, $this->tags($term, $perGroup)),
        ], fn (?array $group): bool => $group !== null));

        return [
            'query' => $term,
            'total' => array_sum(array_map(fn (array $g): int => $g['results']->count(), $groups)),
            'groups' => $groups,
        ];
    }

    /**
     * Titles, and the metadata that names a work: artist, album, author,
     * director, publisher.
     *
     * Ordered so an exact title beats a prefix, and a prefix beats a mention
     * buried in a field — searching "Dune" should not lead with a publisher
     * whose name contains it.
     */
    private function titles(string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $this->gate->apply(MediaItem::query())
            ->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhereHas('musicMetadata', fn (Builder $m) => $m
                    ->where('artist', 'like', $like)
                    ->orWhere('album', 'like', $like))
                ->orWhereHas('bookMetadata', fn (Builder $m) => $m
                    ->where('author', 'like', $like)
                    ->orWhere('publisher', 'like', $like)
                    ->orWhere('isbn_13', 'like', $like)
                    ->orWhere('isbn_10', 'like', $like))
                ->orWhereHas('movieMetadata', fn (Builder $m) => $m
                    ->where('director', 'like', $like)
                    ->orWhere('studio', 'like', $like))
                ->orWhereHas('showMetadata', fn (Builder $m) => $m
                    ->where('creator', 'like', $like)
                    ->orWhere('network', 'like', $like)))
            ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
            ->orderByRaw('CASE WHEN LOWER(title) = LOWER(?) THEN 0 WHEN LOWER(title) LIKE LOWER(?) THEN 1 ELSE 2 END', [
                $term,
                $this->escapeLike($term).'%',
            ])
            ->orderBy('title')
            ->limit($limit)
            ->get()
            ->map(fn (MediaItem $item): array => [
                'kind' => 'item',
                'item' => $item,
                'label' => $item->title,
                'detail' => $item->subtitle(),
                'url' => route('media.show', $item),
            ]);
    }

    /**
     * Cast, crew and authors, with the titles they appear in.
     */
    private function people(string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return Person::query()
            ->where('name', 'like', $like)
            ->withCount('mediaItems')
            // No HAVING: withCount is a subquery, not an aggregate, so SQLite
            // rejects it. People with no titles are filtered below anyway,
            // along with those whose titles are all gated.
            ->orderByDesc('media_items_count')
            ->limit($limit)
            ->get()
            ->map(function (Person $person): array {
                // Gated: a person's filmography must not expose a capped
                // title through the back door. The metadata relations are eager
                // loaded because the API flattens these items into its result and
                // serialises them in full — without them a track surfaced through
                // its artist would arrive with no artist and no file (unplayable).
                $items = $this->gate->apply($person->mediaItems()->getQuery())
                    ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
                    ->limit(6)
                    ->get();

                return [
                    'kind' => 'person',
                    'person' => $person,
                    'items' => $items,
                    'label' => $person->name,
                    'detail' => $items->pluck('title')->implode(' · '),
                ];
            })
            // A person whose every title is capped is not a result.
            ->filter(fn (array $row): bool => $row['items']->isNotEmpty())
            ->values();
    }

    /**
     * Spoken lines, with the moment they are said.
     */
    private function dialogue(string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        $allowed = $this->gate->apply(MediaItem::query())->select('id');

        return SubtitleCue::query()
            ->where('text', 'like', $like)
            ->whereIn('media_item_id', $allowed)
            // The full item, not id/title/type only: the API serialises these
            // flattened into its result, so a partial item would arrive with no
            // file (unplayable) and no metadata. `file_path` drives `playable`.
            ->with(['mediaItem', 'mediaItem.musicMetadata', 'mediaItem.movieMetadata',
                'mediaItem.showMetadata', 'mediaItem.bookMetadata'])
            ->orderBy('media_item_id')
            ->orderBy('start_seconds')
            // Fetched wide, then deduplicated: a film with both a standard and
            // an SDH track holds every line twice, and the same moment listed
            // twice is noise rather than a second result.
            ->limit($limit * 4)
            ->get()
            ->filter(fn (SubtitleCue $cue): bool => $cue->mediaItem !== null)
            ->unique(fn (SubtitleCue $cue): string => $cue->media_item_id.'@'.(int) $cue->start_seconds)
            ->take($limit)
            ->map(fn (SubtitleCue $cue): array => [
                'kind' => 'cue',
                'item' => $cue->mediaItem,
                'label' => $cue->mediaItem->title,
                'snippet' => $this->mark($cue->text, $term),
                'timestamp' => $cue->timestamp(),
                // Seeks straight to the line.
                'url' => route('media.watch', $cue->mediaItem).'?t='.(int) $cue->start_seconds,
            ])
            ->values();
    }

    /**
     * Text from inside books, with the page it appears on.
     */
    private function pages(string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        $allowed = $this->gate->apply(MediaItem::query())->select('id');

        return PageText::query()
            ->whereNotNull('text')
            ->where('text', 'like', $like)
            ->whereIn('media_item_id', $allowed)
            // The full item, for the same reason as the dialogue search above —
            // the API serialises these flattened into its result.
            ->with(['mediaItem', 'mediaItem.musicMetadata', 'mediaItem.movieMetadata',
                'mediaItem.showMetadata', 'mediaItem.bookMetadata'])
            ->orderBy('media_item_id')
            ->orderBy('page')
            ->limit($limit)
            ->get()
            ->filter(fn (PageText $row): bool => $row->mediaItem !== null)
            ->map(fn (PageText $row): array => [
                'kind' => 'page',
                'item' => $row->mediaItem,
                'label' => $row->mediaItem->title,
                'snippet' => $this->mark((string) $row->text, $term),
                'page' => $row->page,
                // Recognised text can be wrong; saying so is more useful than
                // presenting it as the publisher's.
                'ocr' => $row->status === 'complete',
                'url' => route('media.read', $row->mediaItem).'#page='.$row->page,
            ])
            ->values();
    }

    /**
     * Genres and tags, as a route into browsing rather than a result in
     * themselves.
     */
    private function tags(string $term, int $limit): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return $this->gate->apply(MediaItem::query())
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.value', 'like', $like)
            ->selectRaw('media_tags.value as tag, media_items.type as type, count(*) as total')
            ->groupBy('media_tags.value', 'media_items.type')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'kind' => 'tag',
                'label' => $row->tag,
                'detail' => $row->total.' '.str('title')->plural($row->total),
                'url' => route('media.browse', [$row->type, 'genre' => $row->tag]),
            ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     * @return array{key: string, label: string, hint: string|null, results: Collection}|null
     */
    private function group(string $key, string $label, ?string $hint, Collection $results): ?array
    {
        return $results->isEmpty()
            ? null
            : ['key' => $key, 'label' => $label, 'hint' => $hint, 'results' => $results];
    }

    /**
     * A readable fragment around the first match, with the term marked.
     *
     * Marked with control characters rather than HTML: the caller escapes the
     * snippet before turning these into elements, so a book or a subtitle
     * containing angle brackets cannot inject anything.
     */
    private function mark(string $text, string $term): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        $position = mb_stripos($text, $term);

        if ($position === false) {
            return mb_substr($text, 0, self::SNIPPET_PADDING * 2);
        }

        $start = max(0, $position - self::SNIPPET_PADDING);
        $snippet = mb_substr($text, $start, mb_strlen($term) + (self::SNIPPET_PADDING * 2));

        if ($start > 0) {
            $snippet = '…'.$snippet;
        }

        return preg_replace(
            '/('.preg_quote($term, '/').')/iu',
            "\x02$1\x03",
            $snippet,
        ) ?? $snippet;
    }

    /** Escapes LIKE wildcards so a query containing % doesn't match everything. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
