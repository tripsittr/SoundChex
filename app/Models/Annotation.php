<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A highlight or margin note left by one reader on one book.
 *
 * Always scoped to a user — see the `forReader` scope, which every query
 * should go through so a stray `where('media_item_id', …)` can't leak one
 * person's notes into another's margin.
 */
class Annotation extends Model
{
    /** Highlighted passage with no attached text. */
    public const KIND_HIGHLIGHT = 'highlight';

    /** Highlighted passage the reader wrote something about. */
    public const KIND_NOTE = 'note';

    /**
     * Selectable highlight colours.
     *
     * Stored by key rather than hex so the palette can be restyled — or
     * theme-adapted for the night page — without rewriting stored rows.
     */
    public const COLORS = ['yellow', 'green', 'blue', 'pink'];

    protected $fillable = [
        'media_item_id',
        'user_id',
        'profile_id',
        'kind',
        'location',
        'page',
        'excerpt',
        'note',
        'color',
    ];

    protected $casts = [
        'location' => 'array',
        'page' => 'integer',
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
     * Restricts a query to one reader's annotations on one book.
     */
    public function scopeForReader(Builder $query, int $mediaItemId, int $userId): Builder
    {
        return $query
            ->where('media_item_id', $mediaItemId)
            ->where('user_id', $userId);
    }

    /**
     * Restricts a query to the profile currently reading.
     *
     * Falls back to the account for notes written before profiles existed, so
     * upgrading doesn't hide anyone's existing highlights.
     */
    public function scopeForViewer(Builder $query, int $mediaItemId): Builder
    {
        $profileId = app(\App\Services\CurrentProfile::class)->id();

        return $query
            ->where('media_item_id', $mediaItemId)
            ->where('user_id', (int) \Illuminate\Support\Facades\Auth::id())
            ->when($profileId, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('profile_id', $profileId)
                    ->orWhereNull('profile_id'),
            ));
    }

    /**
     * A note is a highlight that has text; kind is derived rather than trusted
     * from input, so the two can never disagree.
     */
    public function syncKind(): void
    {
        $this->kind = filled($this->note) ? self::KIND_NOTE : self::KIND_HIGHLIGHT;
    }
}
