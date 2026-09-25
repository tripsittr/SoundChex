<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shuffle that leans towards what this profile actually listens to (S-289).
 *
 * Ordinary shuffle is uniform: every track equally likely, which on a library
 * of thousands means mostly tracks you have never chosen. Smart shuffle
 * weights the draw by what the profile plays, while keeping enough randomness
 * that it still feels like shuffle rather than a top-40 of your own library.
 *
 * ## What it ranks on, and why not more
 *
 * Play count and recency, and nothing else. The obvious richer signals are not
 * usable on real data:
 *
 * - `completed` is set on 91 of 2,452 plays here. Ranking on it would rank on
 *   an accident of when the flag happened to be written.
 * - `listened_seconds` is null on 93% of rows, so skip detection — the signal
 *   every article about this recommends — would be reading noise.
 * - `user_rating` is unset on every item in the library.
 *
 * So this uses the two signals that are reliably there. When the others fill
 * in, they can be added; ranking on them now would be ranking on nothing.
 */
class SmartShuffle
{
    /**
     * How much of the queue is drawn from favourites.
     *
     * The rest is uniform across everything else. Too high and it stops being
     * shuffle; too low and it is indistinguishable from the plain one. Two
     * thirds familiar, one third discovery.
     */
    private const FAMILIAR_SHARE = 0.65;

    /** Plays newer than this weigh more, in days. */
    private const RECENT_DAYS = 60;

    public function __construct(private ContentGate $gate) {}

    /**
     * A weighted queue for this profile.
     *
     * @return Collection<int, MediaItem>
     */
    public function queue(?int $profileId, int $limit = 200): Collection
    {
        $scores = $this->scores($profileId);

        // Nobody has played anything yet: there is nothing to be smart with,
        // and a weighted draw over an empty score set is just a slow uniform
        // one. Say so by falling back rather than pretending.
        if ($scores->isEmpty()) {
            return $this->random($limit);
        }

        $familiarCount = (int) round($limit * self::FAMILIAR_SHARE);

        $familiar = $this->drawFamiliar($scores, $familiarCount);
        $discovery = $this->random($limit - $familiar->count(), exclude: $familiar->pluck('id')->all());

        // Interleaved rather than concatenated: a queue with every favourite
        // first and every unfamiliar track after reads as two playlists stuck
        // together, which is exactly what shuffle should not feel like.
        return $this->interleave($familiar, $discovery);
    }

    /**
     * Each played item's weight, highest first.
     *
     * A play is worth more when there are more of them and when they are
     * recent — something played ten times last week outranks something played
     * ten times a year ago.
     *
     * @return Collection<int, object{media_item_id: int, weight: float}>
     */
    private function scores(?int $profileId): Collection
    {
        return DB::table('media_plays')
            ->when($profileId !== null, fn ($q) => $q->where('profile_id', $profileId))
            ->selectRaw('media_item_id, COUNT(*) as plays, MAX(created_at) as last_played')
            ->groupBy('media_item_id')
            ->get()
            ->map(function (object $row): object {
                $days = $this->daysSince((string) $row->last_played);

                // Recency as a gentle decay rather than a cliff: a track drops
                // down the order as it ages instead of vanishing on a birthday.
                $recency = 1 / (1 + ($days / self::RECENT_DAYS));

                $row->weight = $row->plays * (0.5 + $recency);

                return $row;
            })
            ->sortByDesc('weight')
            ->values();
    }

    /**
     * Draws from the scored items without replacement, weighted.
     *
     * Weighted rather than "take the top N": taking the top would play the
     * same queue every time, which is the opposite of shuffle. A favourite is
     * *likelier*, never certain.
     *
     * @param  Collection<int, object>  $scores
     * @return Collection<int, MediaItem>
     */
    private function drawFamiliar(Collection $scores, int $count): Collection
    {
        $pool = $scores->all();
        $chosen = [];

        while (count($chosen) < $count && $pool !== []) {
            $total = array_sum(array_map(fn (object $r): float => $r->weight, $pool));

            if ($total <= 0) {
                break;
            }

            $roll = mt_rand() / mt_getrandmax() * $total;
            $running = 0.0;

            foreach ($pool as $key => $row) {
                $running += $row->weight;

                if ($running >= $roll) {
                    $chosen[] = (int) $row->media_item_id;
                    unset($pool[$key]);

                    break;
                }
            }
        }

        if ($chosen === []) {
            return collect();
        }

        // Fetched in one query, then put back into the drawn order: whereIn
        // returns rows in whatever order the database likes, which would undo
        // the weighting.
        $items = $this->playable()->whereIn('media_items.id', $chosen)->get()->keyBy('id');

        return collect($chosen)
            ->map(fn (int $id) => $items->get($id))
            ->filter()
            ->values();
    }

    /**
     * Uniformly random playable tracks, optionally excluding some.
     *
     * @param  array<int, int>  $exclude
     * @return Collection<int, MediaItem>
     */
    private function random(int $limit, array $exclude = []): Collection
    {
        if ($limit <= 0) {
            return collect();
        }

        return $this->playable()
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('media_items.id', $exclude))
            ->inRandomOrder()
            ->limit($limit)
            ->get();
    }

    /**
     * Alternates two lists, so favourites and discoveries are spread through
     * each other rather than stacked.
     *
     * @param  Collection<int, MediaItem>  $a
     * @param  Collection<int, MediaItem>  $b
     * @return Collection<int, MediaItem>
     */
    private function interleave(Collection $a, Collection $b): Collection
    {
        $out = collect();
        $left = $a->values();
        $right = $b->values();
        $max = max($left->count(), $right->count());

        for ($i = 0; $i < $max; $i++) {
            // Two familiar to one discovery, matching the share above, so the
            // spacing is even rather than front-loading the favourites.
            if ($item = $left->get($i * 2)) {
                $out->push($item);
            }

            if ($item = $left->get($i * 2 + 1)) {
                $out->push($item);
            }

            if ($item = $right->get($i)) {
                $out->push($item);
            }
        }

        return $out->unique('id')->values();
    }

    /** Music this profile may play, with what the payload needs. */
    private function playable()
    {
        return $this->gate
            ->apply(MediaItem::query())
            ->where('media_items.type', 'music')
            ->whereNotNull('media_items.file_path')
            ->with(['musicMetadata', 'plays']);
    }

    /** Whole days since a timestamp, never negative. */
    private function daysSince(string $timestamp): float
    {
        $then = strtotime($timestamp);

        if ($then === false) {
            return self::RECENT_DAYS;
        }

        return max(0, (time() - $then) / 86400);
    }
}
