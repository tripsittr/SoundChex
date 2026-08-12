<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One past state of an item's metadata.
 *
 * Immutable by intention: a version records what was true at a moment, so
 * nothing here is ever edited. Restoring writes the old values back onto the
 * item and records a *new* version, rather than deleting the ones since.
 */
class MetadataVersion extends Model
{
    public const REASON_ENRICHMENT = 'enrichment';
    public const REASON_MANUAL = 'manual';
    public const REASON_RESTORE = 'restore';
    public const REASON_IMPORT = 'import';

    protected $fillable = [
        'media_item_id',
        'snapshot',
        'reason',
        'source',
        'user_id',
        'changed_fields',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'changed_fields' => 'array',
    ];

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A one-line summary for the timeline.
     */
    public function summary(): string
    {
        $count = count($this->changed_fields ?? []);

        if ($count === 0) {
            return 'No field changes';
        }

        $names = collect($this->changed_fields)
            ->take(3)
            ->map(fn (string $field): string => static::humanize($field))
            ->implode(', ');

        return $count > 3
            ? $names . ' and ' . ($count - 3) . ' more'
            : $names;
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_ENRICHMENT => $this->source
                ? 'Enriched from ' . $this->source
                : 'Enriched',
            self::REASON_MANUAL => 'Edited by hand',
            self::REASON_RESTORE => 'Restored from an earlier version',
            self::REASON_IMPORT => 'Imported',
            default => ucfirst((string) $this->reason),
        };
    }

    /**
     * "movie.release_year" → "Release year".
     */
    public static function humanize(string $field): string
    {
        $field = str_contains($field, '.')
            ? substr($field, strpos($field, '.') + 1)
            : $field;

        return ucfirst(str_replace('_', ' ', $field));
    }
}
