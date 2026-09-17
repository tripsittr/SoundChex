<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Subtitles;

use App\Models\MediaItem;
use App\Models\Subtitle;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Subtitle search and download via OpenSubtitles.
 *
 * Needs a free API key from opensubtitles.com (Account → API consumers),
 * entered under Settings → Metadata Sources. Downloads are rate-limited per
 * account, so this is only ever triggered deliberately — never as part of a
 * library scan.
 *
 * Matching is by the service's own file hash where possible, which pins the
 * result to this exact release and gets the timing right. Title search is the
 * fallback and is much more likely to be out of sync.
 */
class OpenSubtitles
{
    public function __construct(
        private SettingsService $settings,
        private SubtitleConverter $converter,
    ) {}

    /**
     * Shown on the settings page, which lists every source by name.
     *
     * This isn't a MetadataSource — captions aren't part of the enrichment
     * pipeline — but it's listed alongside them so its API key is entered in
     * the same place as every other.
     */
    public function name(): string
    {
        return 'OpenSubtitles';
    }

    public function requiredSettings(): array
    {
        return ['opensubtitles_api_key' => 'OpenSubtitles API Key'];
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * Finds candidate subtitles for a title.
     *
     * @param array<int, string> $languages
     * @return array<int, array<string, mixed>>
     */
    public function search(MediaItem $item, array $languages = ['en']): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $query = ['languages' => implode(',', $languages)];

        $path = $item->absoluteFilePath();

        // The hash pins the match to this exact file, which is what makes the
        // timing line up. Without it the result is a guess based on the title.
        if ($path !== null && is_file($path)) {
            $hash = $this->fileHash($path);

            if ($hash !== null) {
                $query['moviehash'] = $hash;
            }
        }

        if (filled($item->movieMetadata?->imdb_id)) {
            $query['imdb_id'] = ltrim((string) $item->movieMetadata->imdb_id, 't');
        } elseif (filled($item->movieMetadata?->tmdb_id)) {
            $query['tmdb_id'] = $item->movieMetadata->tmdb_id;
        } else {
            $query['query'] = $item->title;
        }

        $response = $this->request()->get($this->url('/subtitles'), $query);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->map(function (array $row): array {
                $attributes = $row['attributes'] ?? [];
                $file = $attributes['files'][0] ?? [];

                return [
                    'file_id' => $file['file_id'] ?? null,
                    'name' => $file['file_name'] ?? ($attributes['release'] ?? 'Unknown'),
                    'language' => strtolower((string) ($attributes['language'] ?? 'en')),
                    'release' => $attributes['release'] ?? null,
                    // A hash match is on this exact file; anything else was
                    // matched by title and may drift out of sync.
                    'hash_match' => (bool) ($attributes['moviehash_match'] ?? false),
                    'downloads' => (int) ($attributes['download_count'] ?? 0),
                    'rating' => (float) ($attributes['ratings'] ?? 0),
                    'hearing_impaired' => (bool) ($attributes['hearing_impaired'] ?? false),
                    'forced' => (bool) ($attributes['foreign_parts_only'] ?? false),
                    'uploader' => $attributes['uploader']['name'] ?? null,
                ];
            })
            ->filter(fn (array $row): bool => $row['file_id'] !== null)
            // A hash match beats everything; popularity breaks the rest.
            ->sortByDesc(fn (array $row): array => [$row['hash_match'] ? 1 : 0, $row['downloads']])
            ->values()
            ->all();
    }

    /**
     * Downloads one result and stores it as a playable track.
     */
    public function download(MediaItem $item, int $fileId, array $meta = []): ?Subtitle
    {
        if (! $this->isConfigured()) {
            return null;
        }

        // The API hands back a time-limited URL rather than the file itself.
        $response = $this->request()->post($this->url('/download'), ['file_id' => $fileId]);

        if (! $response->successful()) {
            return null;
        }

        $link = $response->json('link');

        if (blank($link)) {
            return null;
        }

        $file = Http::timeout(60)->get($link);

        if (! $file->successful()) {
            return null;
        }

        $content = $file->body();

        if (trim($content) === '') {
            return null;
        }

        $vtt = $this->converter->isVtt($content)
            ? $this->converter->ensureVttHeader($content)
            : $this->converter->srtToVtt($content);

        $cues = $this->converter->countCues($vtt);

        // An empty track would appear in the picker and show nothing.
        if ($cues === 0) {
            return null;
        }

        $language = strtolower((string) ($meta['language'] ?? 'en'));

        $relative = trim((string) config('subtitles.path', 'media/subtitles'), '/')
            . '/' . $item->id
            . '/' . $language . '-online-' . $fileId . '.vtt';

        Storage::put($relative, $vtt);

        $subtitle = Subtitle::updateOrCreate(
            [
                'media_item_id' => $item->id,
                'language' => $language,
                'source' => Subtitle::SOURCE_ONLINE,
                'origin' => (string) $fileId,
            ],
            [
                'label' => $this->languageName($language),
                'path' => $relative,
                'forced' => (bool) ($meta['forced'] ?? false),
                'sdh' => (bool) ($meta['hearing_impaired'] ?? false),
                'cue_count' => $cues,
            ],
        );

        $subtitle->indexCues();

        return $subtitle;
    }

    /**
     * OpenSubtitles' own hash: file size, plus 64KiB from each end as 64-bit
     * little-endian chunks. Documented by the service; it identifies a release
     * without reading the whole file.
     */
    public function fileHash(string $path): ?string
    {
        $size = @filesize($path);

        // Smaller than the two chunks the algorithm reads.
        if ($size === false || $size < 131072) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $hash = $size;

            $hash = $this->hashChunk($handle, $hash);

            fseek($handle, max(0, $size - 65536), SEEK_SET);

            $hash = $this->hashChunk($handle, $hash);
        } finally {
            fclose($handle);
        }

        return sprintf('%016x', $hash);
    }

    /**
     * Adds 8192 little-endian 64-bit words, wrapping as the algorithm expects.
     */
    private function hashChunk($handle, int $hash): int
    {
        for ($i = 0; $i < 8192; $i++) {
            $buffer = fread($handle, 8);

            if ($buffer === false || strlen($buffer) < 8) {
                break;
            }

            $parts = unpack('V2', $buffer);

            // PHP ints are signed 64-bit, so this overflows exactly the way
            // the reference implementation's unsigned arithmetic wraps.
            $hash += $parts[1] + ($parts[2] << 32);
            $hash &= -1;
        }

        return $hash;
    }

    private function request()
    {
        return Http::withHeaders([
            'Api-Key' => (string) $this->apiKey(),
            // The service rejects requests without a descriptive agent.
            'User-Agent' => (string) config('subtitles.user_agent', 'SoundChex v1.0'),
            'Accept' => 'application/json',
        ])->timeout(20);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('subtitles.opensubtitles_base'), '/') . $path;
    }

    private function apiKey(): ?string
    {
        return $this->settings->get('opensubtitles_api_key');
    }

    private function languageName(string $code): string
    {
        $names = [
            'en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German',
            'it' => 'Italian', 'pt' => 'Portuguese', 'ru' => 'Russian', 'ja' => 'Japanese',
            'ko' => 'Korean', 'zh' => 'Chinese', 'nl' => 'Dutch', 'sv' => 'Swedish',
            'pl' => 'Polish', 'tr' => 'Turkish', 'ar' => 'Arabic', 'hi' => 'Hindi',
        ];

        return $names[$code] ?? strtoupper($code);
    }
}
