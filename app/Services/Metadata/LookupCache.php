<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers a metadata provider's answer, because libraries repeat themselves.
 *
 * Measured on a real library mid-enrichment: of 5,454 queued music lookups,
 * only 2,132 were distinct — 61% were the same artist and title asked again.
 * `a-punk|vampire weekend` was queued twelve times. Every one of those was a
 * fresh round trip.
 *
 * That matters more here than it would elsewhere, because the ceiling is not
 * ours to raise. MusicBrainz rate-limits anonymous clients to roughly one
 * request a second, so the queue cannot be made faster by running more workers
 * — that just spends the same allowance quicker and risks being blocked.
 * Asking fewer times is the only honest speed-up.
 *
 * A failed request is never cached. Only an answer is: including an empty one,
 * because "MusicBrainz does not know this track" is a real answer and repeating
 * the question twelve times gets the same nothing.
 */
class LookupCache
{
    /**
     * Long enough to cover a whole library sweep, short enough that a re-fetch
     * next week asks again. Providers do correct their records — a retitled
     * release, an entry merged into another — and "refetch" should be able to
     * see that rather than being handed last month's answer for a month.
     */
    private const TTL_DAYS = 7;

    /**
     * @param  string  $source  The provider's name, so two of them asking the
     *                          same question keep separate answers.
     * @param  array<string, mixed>  $query  Everything that identifies the
     *                                       request. Anything left out here is
     *                                       a cache collision.
     * @param  Closure(): ?array<mixed>  $fetch  Returns the provider's answer,
     *                                           or null when the request itself
     *                                           failed.
     * @return array<mixed>|null
     */
    public function remember(string $source, array $query, Closure $fetch): ?array
    {
        $key = $this->key($source, $query);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $answer = $fetch();

        // null means the request failed — a timeout, a 503, a rate limit. That
        // is not an answer and must not be remembered as one, or one bad minute
        // poisons the next week of lookups.
        if ($answer === null) {
            return null;
        }

        Cache::put($key, $answer, now()->addDays(self::TTL_DAYS));

        return $answer;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function key(string $source, array $query): string
    {
        // Sorted, so the same query written in a different order is the same
        // key rather than a second copy of the same answer.
        ksort($query);

        return 'metadata-lookup:'.$source.':'.hash('xxh128', (string) json_encode($query));
    }
}
