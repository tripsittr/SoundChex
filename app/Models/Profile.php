<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One person's view of the library.
 *
 * Profiles carry taste and position — history, resume points, watchlist,
 * highlights — but no permissions. Switching between them is a convenience,
 * not a security boundary, exactly as every streaming service treats it.
 * Anything that must be enforced lives on the User.
 */
class Profile extends Model
{
    /**
     * Certifications in ascending order of restriction.
     *
     * A profile capped at PG-13 sees everything up to and including it. Titles
     * with no rating at all are shown — most music and books have none, and
     * hiding them would empty a kids profile entirely.
     */
    public const RATING_ORDER = ['G', 'TV-Y', 'TV-G', 'PG', 'TV-PG', 'PG-13', 'TV-14', 'R', 'TV-MA', 'NC-17'];

    /** Palette offered when creating a profile. */
    public const COLORS = [
        '#6366f1', '#ec4899', '#f59e0b', '#10b981',
        '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'color',
        'avatar_path',
        'is_kids',
        'max_rating',
        'is_default',
        'sort_order',
        'last_used_at',
    ];

    protected $casts = [
        'is_kids' => 'boolean',
        'is_default' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(MediaPlay::class);
    }

    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }

    public function watchlist(): BelongsToMany
    {
        return $this->belongsToMany(MediaItem::class, 'watchlist_items')
            ->withTimestamps()
            ->orderByPivot('created_at', 'desc');
    }

    /** First letter, shown when there's no photo. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name), 0, 1)) ?: '?';
    }

    /**
     * URL of the uploaded photo, or null to fall back to the initial.
     *
     * Avatars live on the public disk rather than the private one: they're
     * decoration, they're requested on every page, and routing each through
     * the app would cost a PHP request per tile for no benefit.
     */
    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        return Storage::disk('public')->exists($this->avatar_path)
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    public function hasAvatar(): bool
    {
        return $this->avatarUrl() !== null;
    }

    /**
     * Whether this profile may see a title with the given certification.
     *
     * An unrated title is allowed: most music and books carry no rating, and
     * excluding them would leave a kids profile with almost nothing.
     */
    public function allowsRating(?string $rating): bool
    {
        if ($this->max_rating === null || blank($rating)) {
            return true;
        }

        $cap = array_search($this->max_rating, self::RATING_ORDER, true);
        $actual = array_search($rating, self::RATING_ORDER, true);

        // An unfamiliar certification is treated as allowed rather than
        // guessed at — a wrong guess either hides a children's film or shows
        // an adult one, and the first is merely annoying.
        if ($cap === false || $actual === false) {
            return true;
        }

        return $actual <= $cap;
    }
}
