<?php

namespace App\Services\Metadata\Sources\Show;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\Metadata\Sources\Concerns\TalksToTmdb;
use App\Services\SettingsService;

/**
 * Layer 1 for TV — TMDB. Series details, season/episode counts, network,
 * status, cast, and artwork.
 *
 * Shares an API key with the movie source; one key covers both.
 */
class Tmdb implements MetadataSource
{
    use TalksToTmdb;

    public function __construct(private SettingsService $settings) {}

    public function name(): string { return 'TMDB'; }
    public function priority(): int { return 1; }

    public function supports(MediaItem $item): bool
    {
        return $item->type === MediaItemType::Show
            && filled($this->apiKey())
            && (filled($item->showMetadata?->tmdb_id) || filled($item->title));
    }

    public function enrich(MediaItem $item): void
    {
        // Captured before fillBlank writes an id — afterwards every match
        // would look as though it had been resolved by id.
        $matchedById = filled($item->showMetadata?->tmdb_id);

        $show = $this->resolveShow($item);

        if ($show === null) {
            return;
        }

        $this->fillBlank($item->showMetadata, [
            'tmdb_id'        => $show['id'] ?? null,
            'network'        => $show['networks'][0]['name'] ?? null,
            'creator'        => $show['created_by'][0]['name'] ?? null,
            'first_air_year' => $this->extractYear($show['first_air_date'] ?? null),
            'last_air_year'  => $this->extractYear($show['last_air_date'] ?? null),
            'season_count'   => $show['number_of_seasons'] ?? null,
            'episode_count'  => $show['number_of_episodes'] ?? null,
            'status'         => $this->normalizeStatus($show['status'] ?? null),
            'language'       => $show['original_language'] ?? null,
        ]);

        $this->promoteTitle($item, $show);
        $this->writeOverview($item, $show);
        $this->writeArtwork($item, $show);
        $this->writeGenreTags($item, $show['genres'] ?? []);
        $this->writeCredits($item, $show['credits'] ?? [], ['Executive Producer', 'Producer']);
        $this->recordConfidence($item, $show, $matchedById);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveShow(MediaItem $item): ?array
    {
        $tmdbId = $item->showMetadata?->tmdb_id;

        if (filled($tmdbId)) {
            return $this->fetchShow((int) $tmdbId);
        }

        $results = $this->request('/search/tv', [
            'query'         => $item->title,
            'include_adult' => false,
        ])?->json('results') ?? [];

        if (empty($results)) {
            return null;
        }

        $exact = collect($results)
            ->filter(fn (array $r) => strcasecmp($r['name'] ?? '', $item->title) === 0)
            ->count();

        if ($exact > 1) {
            $item->processing_status = ProcessingStatus::NeedsReview;
            $item->saveQuietly();
        }

        return $this->fetchShow((int) $results[0]['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchShow(int $id): ?array
    {
        return $this->request("/tv/{$id}", [
            'append_to_response' => 'credits',
        ])?->json();
    }

    /**
     * TMDB reports "Returning Series", "Ended", "Canceled", "In Production".
     * The catalog stores ongoing | ended | cancelled.
     */
    private function normalizeStatus(?string $status): ?string
    {
        return match ($status) {
            'Returning Series', 'In Production', 'Planned' => 'ongoing',
            'Ended' => 'ended',
            'Canceled', 'Cancelled' => 'cancelled',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $show
     */
    private function promoteTitle(MediaItem $item, array $show): void
    {
        $canonical = $show['name'] ?? null;

        if (blank($canonical) || strcasecmp($canonical, $item->title) === 0) {
            return;
        }

        $item->title = $canonical;
        $item->saveQuietly();
    }

    /**
     * @param array<string, mixed> $show
     */
    private function writeOverview(MediaItem $item, array $show): void
    {
        if (filled($item->notes) || blank($show['overview'] ?? null)) {
            return;
        }

        $item->notes = $show['overview'];
        $item->saveQuietly();
    }
}
