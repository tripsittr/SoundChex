<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Models\MediaItem;
use App\Services\ArtistCredits;
use App\Services\Metadata\Contracts\MetadataSource;
use getID3;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Layer 1 — reads embedded ID3v2 / Vorbis / MP4 tags from the uploaded file.
 *
 * Runs before every network source so locally-known facts (duration, format,
 * sample rate, and any tags the file already carries) are established first.
 * Falls back to filename parsing when a file has no usable tags.
 */
class FileTagger implements MetadataSource
{
    public function name(): string
    {
        return 'File Tags (getID3)';
    }

    public function priority(): int
    {
        return 1;
    }

    public function requiredSettings(): array
    {
        return [];
    } // reads local files only

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Music
            && $item->hasReadableFile();
    }

    public function enrich(MediaItem $item): void
    {
        $path = $item->absoluteFilePath();

        if ($path === null) {
            return;
        }

        $info = (new getID3)->analyze($path);

        $values = array_merge(
            $this->technicalFields($info),
            $this->taggedFields($info),
        );

        // Only fall back to the filename for fields the tags didn't supply.
        if (blank($values['artist'] ?? null) || blank($values['title'] ?? null)) {
            $values = array_merge($this->parseFilename($item), $values);
        }

        $this->writeMetadata($item, $values);
        $this->writeTitle($item, $values);
        $this->writeGenreTags($item, $info);
        $this->writeCoverArt($item, $info, $values);
    }

    /**
     * Format, duration, sample rate, bit depth — always trustworthy, since
     * they're derived from the audio stream rather than user-editable tags.
     *
     * @return array<string, mixed>
     */
    private function technicalFields(array $info): array
    {
        return array_filter([
            'format' => $info['fileformat'] ?? null,
            'duration_ms' => isset($info['playtime_seconds'])
                ? (int) round($info['playtime_seconds'] * 1000)
                : null,
            'sample_rate' => isset($info['audio']['sample_rate'])
                ? (int) $info['audio']['sample_rate']
                : null,
            'bit_depth' => isset($info['audio']['bits_per_sample'])
                ? (int) $info['audio']['bits_per_sample']
                : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Fields read from the file's tag block.
     *
     * @return array<string, mixed>
     */
    private function taggedFields(array $info): array
    {
        $tags = $this->normalizedTags($info);

        $first = fn (string $key): ?string => filled($tags[$key][0] ?? null)
            ? trim((string) $tags[$key][0])
            : null;

        $bpm = $first('bpm');
        $year = $first('year') ?? $first('date') ?? $first('creation_date');

        [$key, $scale] = $this->parseKeyTag($first('initial_key') ?? $first('key'));

        return array_filter([
            'artist' => static::stripIndexPrefix($first('artist') ?? $first('album_artist')),
            'album' => $first('album'),
            'title' => $first('title'),
            'label' => $first('publisher') ?? $first('label'),
            'isrc' => $first('isrc'),
            'track_number' => $this->leadingInt($first('track_number') ?? $first('track')),
            'disc_number' => $this->leadingInt($first('part_of_a_set') ?? $first('discnumber')),
            'release_year' => $this->extractYear($year),
            'bpm' => is_numeric($bpm) ? round((float) $bpm, 1) : null,
            'key' => $key,
            'scale' => $scale,
            'musicbrainz_recording_id' => $first('musicbrainz_recordingid'),
            'musicbrainz_release_id' => $first('musicbrainz_albumid'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Flattens getID3's tag output into one lookup keyed by field name.
     *
     * getID3 reports textual tags under `tags[<format>]` (id3v2, vorbiscomment,
     * quicktime, …) and binary/derived values under `comments`. Merging both
     * means callers don't care which container a file actually used. Earlier
     * formats win, so ID3v2 takes precedence over a stale ID3v1 block.
     *
     * @return array<string, array<int, mixed>>
     */
    private function normalizedTags(array $info): array
    {
        $merged = [];

        foreach (($info['tags'] ?? []) as $fields) {
            foreach ($fields as $key => $values) {
                $merged[strtolower($key)] ??= (array) $values;
            }
        }

        foreach (($info['comments'] ?? []) as $key => $values) {
            $merged[strtolower($key)] ??= (array) $values;
        }

        return $merged;
    }

    /**
     * Strips a playlist-index prefix that a bad exporter jammed into the artist
     * tag — "7373. Queer" for a track whose artist is really Garbage. The number
     * is a position in someone's export, not part of a name.
     *
     * Guarded to `\d{2,}\.` — a multi-digit run followed by a dot — so it never
     * touches a legitimate number-band. Those read "38 Special", "21 Savage",
     * "3 Doors Down": a number then a *space*, never a dot. Requiring the dot,
     * and leaving anything that isn't left with a real name, keeps them safe.
     *
     * Static and public so the backfill command cleans existing rows with the
     * exact same rule rather than a second copy that could drift.
     */
    public static function stripIndexPrefix(?string $artist): ?string
    {
        if ($artist === null) {
            return null;
        }

        $cleaned = preg_replace('/^\d{2,}\.\s+/', '', trim($artist));

        // Only accept the strip if something real is left; a value that was
        // *only* an index ("7373.") is not a name and is better left for a
        // metadata source to replace than blanked here.
        return ($cleaned !== null && $cleaned !== '') ? $cleaned : $artist;
    }

    /**
     * Applies the configured filename patterns when tags are absent.
     *
     * @return array<string, mixed>
     */
    private function parseFilename(MediaItem $item): array
    {
        $name = pathinfo($item->file_path, PATHINFO_FILENAME);

        // Uploaded files get a hashed prefix; strip it so patterns see the
        // original name the user's file actually had.
        $name = str_replace('_', ' ', $name);

        foreach (config('metadata_rules.filename_patterns', []) as $pattern) {
            if (! preg_match($pattern, $name, $m)) {
                continue;
            }

            $scaleMap = config('metadata_rules.scale_map', []);
            $scaleRaw = isset($m['scale']) ? strtolower($m['scale']) : null;

            return array_filter([
                'artist' => isset($m['artist']) ? trim($m['artist']) : null,
                'album' => isset($m['album']) ? trim($m['album']) : null,
                'title' => isset($m['title']) ? trim($m['title']) : null,
                'track_number' => isset($m['track_number']) ? (int) $m['track_number'] : null,
                'release_year' => isset($m['release_year']) ? (int) $m['release_year'] : null,
                'bpm' => isset($m['bpm']) ? (float) $m['bpm'] : null,
                'key' => isset($m['key']) ? strtoupper($m['key']) : null,
                'scale' => $scaleRaw ? ($scaleMap[$scaleRaw] ?? null) : null,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return [];
    }

    /**
     * Writes metadata without clobbering anything already present. The pipeline
     * runs this source first, so an existing value means either a prior run or
     * a manual user edit — both of which outrank file tags.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeMetadata(MediaItem $item, array $values): void
    {
        $meta = $item->musicMetadata ?: $item->musicMetadata()->make();

        // `title` lives on media_items, not music_metadata — writeTitle() owns it.
        unset($values['title']);

        foreach ($values as $field => $value) {
            if (blank($meta->{$field})) {
                $meta->{$field} = $value;
            }
        }

        // Derive the primary (headline) artist from the credit, so a track by
        // "Artist, Someone" files under "Artist" rather than as its own artist.
        // The credit tag itself is kept intact; this is a separate column used
        // for grouping and browsing.
        if (blank($meta->primary_artist) && filled($meta->artist)) {
            $meta->primary_artist = app(ArtistCredits::class)->primary($meta->artist);
        }

        $meta->media_item_id = $item->id;
        $meta->save();

        $item->setRelation('musicMetadata', $meta);
    }

    /**
     * Uploads land with a placeholder title (the filename). Once tags give us a
     * real track title, promote it — but never overwrite a title the user typed.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeTitle(MediaItem $item, array $values): void
    {
        $tagTitle = $values['title'] ?? null;

        if (blank($tagTitle)) {
            return;
        }

        if (blank($item->title) || $this->looksLikeAFilename($item, $tagTitle)) {
            $item->title = $tagTitle;
            $item->saveQuietly();
        }
    }

    /**
     * Whether the stored title came from the filename rather than from a person.
     *
     * The old test compared the title against the current filename, which held
     * only until LibraryOrganizer renamed the file — and it renames before this
     * runs. "Gold" became "2136 Gold - Imagine Dragons.mp3" on disk while the
     * title stayed "Gold - Imagine Dragons", the equality failed, and the tag
     * title was never promoted. 4,221 tracks, 72% of the library, displaying
     * their artist twice: once in the title and once beneath it.
     *
     * Recognised by shape instead, and deliberately narrowly. A title that is
     * the tag title plus a separator and the artist is a filename convention;
     * nobody types that. Anything else is left alone, because overwriting a
     * title the user set by hand is a worse failure than leaving a clumsy one.
     */
    private function looksLikeAFilename(MediaItem $item, string $tagTitle): bool
    {
        $title = trim($item->title ?? '');

        if ($title === '' || $title === $tagTitle) {
            return false;
        }

        // Still literally the filename: enrichment reached it before anything
        // renamed the file.
        if ($title === pathinfo((string) $item->file_path, PATHINFO_FILENAME)) {
            return true;
        }

        $artist = trim((string) $item->musicMetadata?->artist);

        if ($artist === '') {
            return false;
        }

        // The filer writes "<track> <title> - <artist>", and the scanner read
        // that back as a title. Both forms are checked, so a row catalogued
        // before the track number was added is recognised too.
        $candidates = [$title];

        if (preg_match('/^\d+\s+(.+)$/u', $title, $matches)) {
            $candidates[] = trim($matches[1]);
        }

        foreach ($candidates as $candidate) {
            foreach ([' - ', ' — ', ' – ', '_-_'] as $separator) {
                if ($candidate === $tagTitle.$separator.$artist) {
                    return true;
                }
            }
        }

        return false;
    }

    private function writeGenreTags(MediaItem $item, array $info): void
    {
        $genres = $this->normalizedTags($info)['genre'] ?? [];
        $aliases = config('metadata_rules.genre_aliases', []);

        foreach ($genres as $genre) {
            foreach ($this->splitMultiValue((string) $genre) as $value) {
                $canonical = $aliases[strtolower($value)] ?? $value;

                $this->addTagUnlessManual($item, 'genre', $canonical);
            }
        }
    }

    /**
     * Extracts embedded cover art.
     *
     * Filed under artwork/{artist}/{album}/{song} on the public disk so the
     * folder tree mirrors the library and each track's art sits with it. The
     * public disk matters: covers are referenced by URL from the browser,
     * unlike audio, which is proxied through an authenticated route.
     *
     * @param  array<string, mixed>  $values  Fields resolved from tags this run,
     *                                        since the metadata row may not be written yet.
     */
    private function writeCoverArt(MediaItem $item, array $info, array $values = []): void
    {
        if (filled($item->cover_image_url)) {
            return;
        }

        $picture = $this->normalizedTags($info)['picture'][0] ?? null;

        if (empty($picture['data'])) {
            return;
        }

        $extension = match ($picture['image_mime'] ?? '') {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $artist = $values['artist'] ?? $item->musicMetadata?->artist;
        $album = $values['album'] ?? $item->musicMetadata?->album;
        $song = $values['title'] ?? $item->title;

        $path = implode('/', [
            'artwork',
            $this->pathSegment($artist, 'Unknown Artist'),
            $this->pathSegment($album, 'Unknown Album'),
            // Id suffix keeps two identically-named tracks from colliding.
            $this->pathSegment($song, 'Untitled').'-'.$item->id.'.'.$extension,
        ]);

        Storage::disk('public')->put($path, $picture['data']);

        // Store the path, not a URL. A baked-in absolute URL would pin the
        // library to one hostname, so the same rows would break when reached
        // by LAN IP or through a tunnel. MediaItem::coverUrl() resolves this
        // against whatever host the request came in on.
        $item->cover_image_url = $path;

        $item->saveQuietly();
    }

    /**
     * Turns a metadata value into a safe single path segment.
     *
     * Slashes, colons, and leading dots would otherwise create stray nesting
     * or hidden directories on disk.
     */
    private function pathSegment(?string $value, string $fallback): string
    {
        $clean = trim(preg_replace('/[\/\\\\:*?"<>|]+/', '-', (string) $value));
        $clean = ltrim($clean, '.');

        return $clean !== '' ? Str::limit($clean, 80, '') : $fallback;
    }

    /**
     * Adds a tag unless the user has already set one manually for that value —
     * manual tags are never overwritten or duplicated by the pipeline.
     */
    private function addTagUnlessManual(MediaItem $item, string $type, string $value): void
    {
        $exists = $item->tags()
            ->where('type', $type)
            ->where('value', $value)
            ->exists();

        if ($exists) {
            return;
        }

        $item->tags()->create([
            'type' => $type,
            'value' => $value,
            'source' => MediaTagSource::File,
        ]);
    }

    /** @return array<int, string> */
    private function splitMultiValue(string $value): array
    {
        $separators = config('metadata_rules.multi_value_separators', []);
        $parts = [$value];

        foreach ($separators as $separator) {
            $parts = array_merge(...array_map(
                fn (string $part) => explode($separator, $part),
                $parts,
            ));
        }

        return array_values(array_filter(array_map('trim', $parts), 'filled'));
    }

    /**
     * ID3 TKEY carries values like "Am", "C#m", "F# minor", or "8A".
     * Splits that into a note plus a scale.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseKeyTag(?string $raw): array
    {
        if (blank($raw)) {
            return [null, null];
        }

        if (! preg_match('/^([A-G][#b]?)\s*(maj|major|min|minor|m)?$/i', trim($raw), $m)) {
            return [null, null];
        }

        $scaleMap = config('metadata_rules.scale_map', []);
        $scaleRaw = isset($m[2]) ? strtolower($m[2]) : null;

        return [
            strtoupper($m[1]),
            $scaleRaw ? ($scaleMap[$scaleRaw] ?? null) : null,
        ];
    }

    /** Track tags are often "3/12" — take the leading number. */
    private function leadingInt(?string $raw): ?int
    {
        if (blank($raw) || ! preg_match('/(\d+)/', $raw, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** Dates arrive as "1997", "1997-04-22", or a full timestamp. */
    private function extractYear(?string $raw): ?int
    {
        if (blank($raw) || ! preg_match('/(\d{4})/', $raw, $m)) {
            return null;
        }

        $year = (int) $m[1];

        return ($year >= 1000 && $year <= 2999) ? $year : null;
    }
}
