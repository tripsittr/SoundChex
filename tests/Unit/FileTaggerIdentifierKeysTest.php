<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Unit;

use App\Services\Metadata\Sources\Music\FileTagger;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Identifier tags are read whichever spelling the tagger used (part of #489).
 *
 * The audit's cause 3 for the unmatched library was that `FileTagger` reads
 * `musicbrainz_recordingid` while Picard writes `MUSICBRAINZ_TRACKID`. On this
 * library that is **not** the cause of the headline number — 6,050 of 8,314
 * rows do carry a recording id, so some spelling is being read (#489) — but it
 * is still a real gap for files tagged by a tool whose spelling differs.
 *
 * `normalizedTags()` lowercases keys and does **not** strip spaces or
 * underscores, so `MUSICBRAINZ_TRACKID`, `MusicBrainz Track Id` and
 * `musicbrainz_recordingid` are three distinct keys. Reading one silently
 * misses the others, which is a failure with no symptom: the file simply
 * arrives unidentified.
 *
 * These drive `taggedFields()` with synthetic getID3 output rather than real
 * files, because the Mac has no media on it — the library's files live
 * elsewhere. **a5 should confirm against real Picard-tagged files**, which is
 * the only way to know which spellings actually occur.
 */
class FileTaggerIdentifierKeysTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function recordingIdKeys(): array
    {
        return [
            // What the code already read.
            'musicbrainz_recordingid' => ['musicbrainz_recordingid'],
            // Vorbis comments, as Picard writes them.
            'MUSICBRAINZ_TRACKID' => ['musicbrainz_trackid'],
            // An ID3v2 TXXX frame description, lowercased but still spaced.
            'MusicBrainz Track Id' => ['musicbrainz track id'],
            'MusicBrainz Recording Id' => ['musicbrainz recording id'],
        ];
    }

    #[DataProvider('recordingIdKeys')]
    public function test_a_recording_id_is_read_under_any_documented_spelling(string $key): void
    {
        $fields = $this->taggedFields([$key => ['badf0c46-e52b-4534-b59b-0aea31d32d61']]);

        $this->assertSame(
            'badf0c46-e52b-4534-b59b-0aea31d32d61',
            $fields['musicbrainz_recording_id'] ?? null,
            "A recording id written as \"{$key}\" was not read.",
        );
    }

    public function test_the_most_specific_spelling_wins(): void
    {
        // A file can carry both: Picard's own "track id" alongside a recording
        // id written by something else. The recording id is the one that names
        // the performance, so it takes precedence.
        $fields = $this->taggedFields([
            'musicbrainz_trackid' => ['11111111-1111-1111-1111-111111111111'],
            'musicbrainz_recordingid' => ['badf0c46-e52b-4534-b59b-0aea31d32d61'],
        ]);

        $this->assertSame('badf0c46-e52b-4534-b59b-0aea31d32d61', $fields['musicbrainz_recording_id']);
    }

    public function test_an_acoustid_is_read(): void
    {
        // Picard writes this and nothing read it, so a fingerprinted file
        // arrived with its AcoustID on disk and none in the database.
        $fields = $this->taggedFields(['acoustid_id' => ['e2a1b0c4-1111-2222-3333-444455556666']]);

        $this->assertSame('e2a1b0c4-1111-2222-3333-444455556666', $fields['acoustid'] ?? null);
    }

    public function test_a_release_id_is_read_under_either_spelling(): void
    {
        foreach (['musicbrainz_albumid', 'musicbrainz album id'] as $key) {
            $fields = $this->taggedFields([$key => ['77777777-8888-9999-aaaa-bbbbbbbbbbbb']]);

            $this->assertSame(
                '77777777-8888-9999-aaaa-bbbbbbbbbbbb',
                $fields['musicbrainz_release_id'] ?? null,
                "A release id written as \"{$key}\" was not read.",
            );
        }
    }

    public function test_absent_identifiers_are_simply_absent(): void
    {
        // array_filter drops empties, so a file with no identifiers must not
        // produce null entries that would overwrite something better later.
        $fields = $this->taggedFields(['artist' => ['Someone'], 'title' => ['A song']]);

        $this->assertArrayNotHasKey('musicbrainz_recording_id', $fields);
        $this->assertArrayNotHasKey('acoustid', $fields);
    }

    public function test_an_empty_identifier_tag_is_ignored(): void
    {
        // A tagger that writes the key with no value must not store an empty
        // string, which would then look like an identifier the code could
        // resolve by and waste a lookup on nothing.
        $fields = $this->taggedFields([
            'musicbrainz_recordingid' => [''],
            'musicbrainz_trackid' => ['badf0c46-e52b-4534-b59b-0aea31d32d61'],
        ]);

        $this->assertSame(
            'badf0c46-e52b-4534-b59b-0aea31d32d61',
            $fields['musicbrainz_recording_id'],
            'An empty value under the preferred key must fall through to the next spelling.',
        );
    }

    /**
     * Runs `taggedFields()` over tags shaped the way getID3 reports them.
     *
     * getID3 nests textual tags under `tags[<container>]`, and
     * `normalizedTags()` lowercases the keys — so the fixture uses the
     * lowercased form, which is what the lookup actually sees.
     *
     * @param  array<string, array<int, string>>  $tags
     * @return array<string, mixed>
     */
    private function taggedFields(array $tags): array
    {
        $method = new ReflectionMethod(FileTagger::class, 'taggedFields');

        return $method->invoke(app(FileTagger::class), ['tags' => ['id3v2' => $tags]]);
    }
}
