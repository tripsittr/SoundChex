<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies a profile's rating cap to a query.
 *
 * One place, used by every browse and search path — a cap enforced in some
 * queries and not others is worse than none, because it reads as working.
 *
 * Ratings live in per-type metadata tables, so this joins rather than
 * filtering in PHP: filtering a paginated result set after the fact would
 * return short pages and break the count.
 */
class ContentGate
{
    public function __construct(private CurrentProfile $profiles) {}

    /**
     * Whether anything is currently being restricted.
     */
    public function isActive(): bool
    {
        return $this->profiles->get()?->max_rating !== null;
    }

    public function profile(): ?Profile
    {
        return $this->profiles->get();
    }

    /**
     * Restricts a media query to what the current profile may see.
     *
     * Titles with no rating are allowed through: most music and books carry
     * none, and excluding them would empty a kids profile rather than protect
     * it. The cap is about keeping an R-rated film out, not about hiding
     * everything unlabelled.
     */
    public function apply(Builder $query): Builder
    {
        $profile = $this->profiles->get();

        if ($profile?->max_rating === null) {
            return $query;
        }

        $allowed = $this->allowedRatings($profile->max_rating);

        // Nothing recognised in the cap means it can't be enforced sensibly;
        // leaving the query untouched is better than hiding the library.
        if ($allowed === []) {
            return $query;
        }

        // Qualified with the table name: this gate is applied to queries that
        // join media_tags and media_availability, where a bare `type` is
        // ambiguous and SQLite refuses the query outright.
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $outer) use ($allowed, $table): void {
            // Movies: keep anything unrated or at/below the cap.
            $outer->where(function (Builder $q) use ($allowed, $table): void {
                $q->where($table . '.type', '!=', MediaItemType::Movie->value)
                    ->orWhereHas('movieMetadata', fn (Builder $meta) => $meta
                        ->whereNull('mpaa_rating')
                        ->orWhereIn('mpaa_rating', $allowed))
                    ->orWhereDoesntHave('movieMetadata');
            });

            // Shows, same rule against their own certification column.
            $outer->where(function (Builder $q) use ($allowed, $table): void {
                $q->where($table . '.type', '!=', MediaItemType::Show->value)
                    ->orWhereHas('showMetadata', fn (Builder $meta) => $meta
                        ->whereNull('content_rating')
                        ->orWhereIn('content_rating', $allowed))
                    ->orWhereDoesntHave('showMetadata');
            });
        });
    }

    /**
     * Every certification at or below a cap.
     *
     * @return array<int, string>
     */
    public function allowedRatings(string $cap): array
    {
        $index = array_search($cap, Profile::RATING_ORDER, true);

        return $index === false
            ? []
            : array_slice(Profile::RATING_ORDER, 0, $index + 1);
    }

    /**
     * Whether one specific item is viewable.
     *
     * Used on detail and playback routes, where the item is already loaded and
     * a query scope would be wasteful.
     */
    public function allows(\App\Models\MediaItem $item): bool
    {
        $profile = $this->profiles->get();

        if ($profile?->max_rating === null) {
            return true;
        }

        $rating = match ($item->type) {
            MediaItemType::Movie => $item->movieMetadata?->mpaa_rating,
            MediaItemType::Show => $item->showMetadata?->content_rating,
            default => null,
        };

        return $profile->allowsRating($rating);
    }
}
