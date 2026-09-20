<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use App\Models\MetadataVersion;
use Illuminate\Support\Facades\Auth;

/**
 * Snapshots, compares, and restores an item's metadata.
 *
 * Providers rewrite their own records, so a re-enrichment can quietly replace
 * a correct value with a worse one — or lose a detail the provider dropped.
 * A snapshot before every run means the previous state is always recoverable.
 *
 * Works across all four media types by reading whichever metadata row the item
 * has, rather than knowing about each in turn.
 */
class MetadataHistory
{
    /**
     * Fields on the item itself worth versioning.
     *
     * File paths and processing state are deliberately absent: they describe
     * where the file is and what the pipeline is doing, not what the item is.
     * Restoring an old file_path would point the row at a file that has since
     * been moved.
     */
    private const ITEM_FIELDS = [
        'title',
        'cover_image_url',
        'notes',
        'user_rating',
        'owned',
        'wishlist',
        'source_service',
        'match_confidence',
        'matched_by',
        'external_id',
        'external_source',
    ];

    /**
     * Records the state a file arrived in, before anything renames or enriches
     * it (S-21).
     *
     * The regular snapshot deliberately omits the file path — restoring an old
     * one would point the row at a moved file. But the *arrival* path and name
     * are exactly what is otherwise lost the moment the organiser files the
     * track and enrichment rewrites the title: S-44 had to guess an original
     * filename from the shape of a title because the real one was gone. This
     * keeps it, under an `intake` section alongside the normal snapshot, as the
     * one `import` version that is never a diff — it is the baseline.
     *
     * Idempotent: a file re-catalogued (a re-scan of the same path) does not get
     * a second intake row.
     */
    public function recordIntake(MediaItem $item, ?string $contentHash = null): ?MetadataVersion
    {
        $alreadyRecorded = MetadataVersion::where('media_item_id', $item->id)
            ->where('reason', MetadataVersion::REASON_IMPORT)
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        $snapshot = $this->snapshot($item);

        // The file's own arrival facts — the part the normal snapshot omits on
        // purpose, and the part that has nowhere else to live once the file is
        // renamed and re-tagged.
        $snapshot['intake'] = [
            'file_path' => $item->file_path,
            'file_name' => basename((string) $item->file_path),
            'file_size' => $item->file_size,
            'content_hash' => $contentHash ?? $item->content_hash,
            'arrived_at' => now()->toIso8601String(),
        ];

        return MetadataVersion::create([
            'media_item_id' => $item->id,
            'snapshot' => $snapshot,
            'reason' => MetadataVersion::REASON_IMPORT,
            'source' => null,
            'user_id' => Auth::id(),
            'changed_fields' => array_keys($this->flatten($snapshot)),
        ]);
    }

    /**
     * Captures the item's current state.
     *
     * Returns null when nothing has changed since the last version — a
     * re-enrichment that found the same data shouldn't add a row saying so.
     */
    public function capture(
        MediaItem $item,
        string $reason = MetadataVersion::REASON_ENRICHMENT,
        ?string $source = null,
    ): ?MetadataVersion {
        $snapshot = $this->snapshot($item);

        $previous = $this->latest($item);

        // The first snapshot is always kept: it's the baseline everything
        // later is compared against.
        if ($previous !== null) {
            $changed = $this->changedFields($previous->snapshot, $snapshot);

            if ($changed === []) {
                return null;
            }
        } else {
            $changed = array_keys($this->flatten($snapshot));
        }

        return MetadataVersion::create([
            'media_item_id' => $item->id,
            'snapshot' => $snapshot,
            'reason' => $reason,
            'source' => $source,
            // Console and queue runs have no authenticated user, which is
            // itself informative — it means the pipeline did it.
            'user_id' => Auth::id(),
            'changed_fields' => $changed,
        ]);
    }

    /**
     * Everything worth preserving about an item.
     *
     * @return array<string, mixed>
     */
    public function snapshot(MediaItem $item): array
    {
        $snapshot = ['item' => []];

        foreach (self::ITEM_FIELDS as $field) {
            $value = $item->{$field};

            // Enums don't survive a JSON round trip as objects.
            $snapshot['item'][$field] = $value instanceof \BackedEnum
                ? $value->value
                : $value;
        }

        $metadata = $item->metadata()->first();

        if ($metadata !== null) {
            $attributes = $metadata->getAttributes();

            // Keys and timestamps describe the row, not the metadata.
            unset(
                $attributes['id'],
                $attributes['media_item_id'],
                $attributes['created_at'],
                $attributes['updated_at'],
            );

            $snapshot['metadata'] = $attributes;
        }

        $snapshot['tags'] = $item->tags()
            ->orderBy('type')
            ->orderBy('value')
            ->get(['type', 'value', 'source'])
            ->map(fn ($tag): array => [
                'type' => $tag->type,
                'value' => $tag->value,
                'source' => $tag->source instanceof \BackedEnum ? $tag->source->value : $tag->source,
            ])
            ->all();

        $snapshot['people'] = $item->people()
            ->orderBy('media_item_person.sort_order')
            ->get(['people.id', 'people.name'])
            ->map(fn ($person): array => [
                'name' => $person->name,
                'role' => $person->pivot->role,
                'character' => $person->pivot->character,
            ])
            ->all();

        return $snapshot;
    }

    public function latest(MediaItem $item): ?MetadataVersion
    {
        return MetadataVersion::where('media_item_id', $item->id)
            ->latest('id')
            ->first();
    }

    /**
     * Field-by-field comparison between two snapshots.
     *
     * @return array<int, string> Dot-notation paths that differ.
     */
    public function changedFields(array $before, array $after): array
    {
        $flatBefore = $this->flatten($before);
        $flatAfter = $this->flatten($after);

        $keys = array_unique([...array_keys($flatBefore), ...array_keys($flatAfter)]);

        $changed = [];

        foreach ($keys as $key) {
            $old = $flatBefore[$key] ?? null;
            $new = $flatAfter[$key] ?? null;

            // Loose-ish comparison: a value that round-tripped through JSON
            // may come back as "5" where it went in as 5, and reporting that
            // as a change would fill the timeline with noise.
            if ($this->normalize($old) !== $this->normalize($new)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * A readable diff between two versions.
     *
     * @return array<int, array{field: string, label: string, old: mixed, new: mixed}>
     */
    public function diff(array $before, array $after): array
    {
        $flatBefore = $this->flatten($before);
        $flatAfter = $this->flatten($after);

        return collect($this->changedFields($before, $after))
            ->map(fn (string $field): array => [
                'field' => $field,
                'label' => MetadataVersion::humanize($field),
                'old' => $flatBefore[$field] ?? null,
                'new' => $flatAfter[$field] ?? null,
            ])
            ->all();
    }

    /**
     * Writes a past version's values back onto the item.
     *
     * The current state is snapshotted first, so restoring is itself
     * reversible — nothing is ever lost by trying one.
     */
    public function restore(MediaItem $item, MetadataVersion $version): void
    {
        $this->capture($item, MetadataVersion::REASON_MANUAL, 'before restore');

        $snapshot = $version->snapshot;

        foreach ($snapshot['item'] ?? [] as $field => $value) {
            if (in_array($field, self::ITEM_FIELDS, true)) {
                $item->{$field} = $value;
            }
        }

        $item->saveQuietly();

        $metadata = $item->metadata()->first();

        if ($metadata !== null && filled($snapshot['metadata'] ?? null)) {
            foreach ($snapshot['metadata'] as $field => $value) {
                $metadata->{$field} = $value;
            }

            $metadata->saveQuietly();
        }

        // Tags and credits are replaced wholesale: restoring a subset would
        // leave a mix of two versions, which is neither of them.
        if (isset($snapshot['tags'])) {
            $item->tags()->delete();

            foreach ($snapshot['tags'] as $tag) {
                $item->tags()->create($tag);
            }
        }

        $this->capture($item, MetadataVersion::REASON_RESTORE, 'version #'.$version->id);
    }

    /**
     * Flattens nested arrays to dot notation for comparison.
     *
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            // Tags and people are compared as a whole rather than per index:
            // adding one at the front would otherwise report every following
            // position as changed.
            if (is_array($value) && array_is_list($value)) {
                $flat[$path] = json_encode($value);

                continue;
            }

            if (is_array($value)) {
                $flat = [...$flat, ...$this->flatten($value, $path)];

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * Reduces a value to something comparable across a JSON round trip.
     */
    private function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            // "5" and 5 and 5.0 are the same value stored three ways.
            return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }
}
