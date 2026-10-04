<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Services\Metadata\LookupCache;
use Tests\TestCase;

/**
 * Asking a provider the same question once.
 *
 * Measured on a real library mid-enrichment: 5,454 queued music lookups, 2,132
 * of them distinct. 61% were repeats, one album twelve times over. The ceiling
 * is not ours to raise either — MusicBrainz allows anonymous clients roughly a
 * request a second, so more workers spend the same allowance faster rather than
 * going faster. Asking less is the only lever.
 */
class MetadataLookupCacheTest extends TestCase
{
    public function test_the_same_query_is_only_asked_once(): void
    {
        $calls = 0;

        $fetch = function () use (&$calls): array {
            $calls++;

            return ['recordings' => [['title' => 'A-Punk']]];
        };

        $cache = app(LookupCache::class);

        $first = $cache->remember('MusicBrainz', ['query' => 'a-punk'], $fetch);
        $second = $cache->remember('MusicBrainz', ['query' => 'a-punk'], $fetch);

        $this->assertSame(1, $calls, 'The second lookup should have come from the cache.');
        $this->assertSame($first, $second);
    }

    /** The order the query was written in must not make a second copy. */
    public function test_the_key_does_not_depend_on_parameter_order(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls): array {
            $calls++;

            return ['ok' => true];
        };

        $cache = app(LookupCache::class);

        $cache->remember('MusicBrainz', ['a' => 1, 'b' => 2], $fetch);
        $cache->remember('MusicBrainz', ['b' => 2, 'a' => 1], $fetch);

        $this->assertSame(1, $calls);
    }

    /** Two providers asking the same thing keep their own answers. */
    public function test_providers_do_not_share_answers(): void
    {
        $cache = app(LookupCache::class);

        $cache->remember('MusicBrainz', ['query' => 'x'], fn (): array => ['from' => 'mb']);
        $itunes = $cache->remember('iTunes', ['query' => 'x'], fn (): array => ['from' => 'itunes']);

        $this->assertSame(['from' => 'itunes'], $itunes);
    }

    /**
     * "Nothing found" is an answer and is remembered.
     *
     * This is most of the win: the tracks nothing can identify are exactly the
     * ones that repeat, and re-asking twelve times gets the same nothing.
     */
    public function test_an_empty_answer_is_remembered(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls): array {
            $calls++;

            return [];
        };

        $cache = app(LookupCache::class);

        $this->assertSame([], $cache->remember('MusicBrainz', ['query' => 'unknown'], $fetch));
        $this->assertSame([], $cache->remember('MusicBrainz', ['query' => 'unknown'], $fetch));

        $this->assertSame(1, $calls);
    }

    /**
     * A failed request is not an answer.
     *
     * If one timeout or rate-limit were cached, a single bad minute would
     * poison a week of lookups for every track in it.
     */
    public function test_a_failed_request_is_never_remembered(): void
    {
        $calls = 0;

        $failing = function () use (&$calls): ?array {
            $calls++;

            return null;
        };

        $cache = app(LookupCache::class);

        $this->assertNull($cache->remember('MusicBrainz', ['query' => 'flaky'], $failing));
        $this->assertNull($cache->remember('MusicBrainz', ['query' => 'flaky'], $failing));

        $this->assertSame(2, $calls, 'A failure must be retried, not cached.');
    }

    /** And once it succeeds, that answer is the one kept. */
    public function test_a_success_after_a_failure_is_remembered(): void
    {
        $cache = app(LookupCache::class);

        $this->assertNull($cache->remember('MusicBrainz', ['query' => 'later'], fn (): ?array => null));

        $calls = 0;
        $succeeding = function () use (&$calls): array {
            $calls++;

            return ['title' => 'Sun'];
        };

        $cache->remember('MusicBrainz', ['query' => 'later'], $succeeding);
        $cache->remember('MusicBrainz', ['query' => 'later'], $succeeding);

        $this->assertSame(1, $calls);
    }
}
