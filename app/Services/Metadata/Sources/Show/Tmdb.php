<?php

namespace App\Services\Metadata\Sources\Show;

use App\Enums\MatchConfidence;
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
        // An episode inherits its series' identity — searching TMDB for
        // "The Bear S01E02" finds nothing, because that is a filename, not a
        // title. The series is looked up once and the episode adds its own
        // name and air date on top.
        if ($item->isEpisode()) {
            $this->enrichEpisode($item);

            return;
        }

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
     * Fills an episode's title and air date from its series.
     *
     * The numbering came from the filename and is authoritative about which
     * file this is; TMDB only supplies what the episode is called.
     */
    private function enrichEpisode(MediaItem $item): void
    {
        $meta = $item->showMetadata;
        $series = $item->series;

        if ($meta === null || $series === null) {
            return;
        }

        // The series may not have been enriched yet, in which case there is no
        // id to query against. Its own run will happen; this one simply waits.
        $seriesTmdbId = $series->showMetadata?->tmdb_id;

        if (blank($seriesTmdbId)) {
            return;
        }

        $episode = $this->request(sprintf(
            '/tv/%d/season/%d/episode/%d',
            (int) $seriesTmdbId,
            (int) $meta->season_number,
            (int) $meta->episode_number,
        ))?->json();

        if ($episode === null) {
            return;
        }

        $this->fillBlank($meta, [
            'tmdb_id' => $seriesTmdbId,
            'episode_title' => $episode['name'] ?? null,
            'episode_air_date' => $episode['air_date'] ?? null,
        ]);

        // The row was named after its filename code; the real title is better
        // once known, and the code survives in the path.
        if (filled($episode['name'] ?? null)) {
            $item->forceFill(['title' => $episode['name']])->saveQuietly();
        }

        // Numbering came from the filename, so this match is as exact as it
        // gets — the file itself said which episode it is.
        $item->forceFill([
            'match_confidence' => MatchConfidence::Exact,
            'matched_by' => $this->name(),
        ])->saveQuietly();
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
