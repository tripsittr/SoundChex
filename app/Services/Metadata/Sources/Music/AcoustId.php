<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Layer 2 — AcoustID audio fingerprinting.
 *
 * Identifies files that carry no useful tags by listening to the audio itself:
 * `fpcalc` (Chromaprint) produces a fingerprint, AcoustID maps that to a
 * MusicBrainz Recording ID, and MusicBrainz (layer 3) expands it into full
 * metadata on the next pass.
 *
 * Requires the `fpcalc` binary. Install with `brew install chromaprint`
 * (macOS) or `apt install libchromaprint-tools` (Debian/Ubuntu).
 */
class AcoustId implements MetadataSource
{
    public function __construct(private SettingsService $settings) {}

    public function name(): string
    {
        return 'AcoustID (fingerprint)';
    }

    public function priority(): int
    {
        return 2;
    }

    public function requiredSettings(): array
    {
        return ['acoustid_api_key' => 'AcoustID Application API Key'];
    }

    public function supports(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Music) {
            return false;
        }

        // Fingerprinting is comparatively expensive, and only worth running
        // when tags left us without an identity to work from.
        if (filled($item->musicMetadata?->musicbrainz_recording_id)) {
            return false;
        }

        if (filled($item->musicMetadata?->artist) && filled($item->musicMetadata?->album)) {
            return false;
        }

        return $item->hasReadableFile()
            && filled($this->settings->get('acoustid_api_key'))
            && $this->fpcalcAvailable();
    }

    public function enrich(MediaItem $item): void
    {
        $path = $item->absoluteFilePath();

        if ($path === null) {
            return;
        }

        $fingerprint = $this->fingerprint($path);

        if ($fingerprint === null) {
            return;
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->retry(2, 1000, throw: false)
            ->get('https://api.acoustid.org/v2/lookup', [
                'client' => $this->settings->get('acoustid_api_key'),
                'fingerprint' => $fingerprint['fingerprint'],
                'duration' => $fingerprint['duration'],
                'meta' => 'recordings+releasegroups',
                'format' => 'json',
            ]);

        if (! $response->successful() || $response->json('status') !== 'ok') {
            return;
        }

        $best = $this->bestResult($response->json('results', []));

        if ($best === null) {
            return;
        }

        $this->writeIdentity($item, $best);

        // An acoustic fingerprint identifies the actual recording, so this is an
        // Exact match. Recorded here so the library reflects that the track was
        // identified. Never downgrades an Exact match a prior source pinned.
        if ($item->match_confidence !== MatchConfidence::Exact) {
            $item->forceFill([
                'match_confidence' => MatchConfidence::Exact,
                'matched_by' => $this->name(),
            ])->saveQuietly();
        }
    }

    /**
     * Runs Chromaprint over the file.
     *
     * @return array{fingerprint: string, duration: int}|null
     */
    private function fingerprint(string $path): ?array
    {
        $result = Process::timeout(60)->run(['fpcalc', '-json', $path]);

        if (! $result->successful()) {
            return null;
        }

        $data = json_decode($result->output(), true);

        if (empty($data['fingerprint']) || empty($data['duration'])) {
            return null;
        }

        return [
            'fingerprint' => $data['fingerprint'],
            'duration' => (int) round($data['duration']),
        ];
    }

    /**
     * AcoustID returns candidates with a 0–1 confidence score. Anything below
     * ~0.5 is more likely a false positive than a match, and writing a wrong
     * MBID here would poison every downstream source.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    private function bestResult(array $results): ?array
    {
        return collect($results)
            ->filter(fn (array $r) => ($r['score'] ?? 0) >= 0.5 && ! empty($r['recordings']))
            ->sortByDesc('score')
            ->first();
    }

    /**
     * Writes only the identifiers. Titles and albums are deliberately left to
     * MusicBrainz, which resolves them from the MBID with far better data.
     *
     * @param  array<string, mixed>  $result
     */
    private function writeIdentity(MediaItem $item, array $result): void
    {
        $meta = $item->musicMetadata;

        if (! $meta) {
            return;
        }

        $recording = $result['recordings'][0] ?? [];

        $values = array_filter([
            'acoustid' => $result['id'] ?? null,
            'musicbrainz_recording_id' => $recording['id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $dirty = false;

        foreach ($values as $field => $value) {
            if (blank($meta->{$field})) {
                $meta->{$field} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $meta->saveQuietly();
            $item->setRelation('musicMetadata', $meta);
        }
    }

    /**
     * Cached because supports() runs for every music item in a batch and the
     * binary's presence can't change mid-request.
     */
    private function fpcalcAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $available = Process::run(['which', 'fpcalc'])->successful();
        }

        return $available;
    }
}
