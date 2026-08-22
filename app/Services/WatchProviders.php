<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaAvailability;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Http;

/**
 * Fetches where a title can currently be watched, from TMDB.
 *
 * Separate from the metadata pipeline on purpose: metadata describes the work
 * and rarely changes, while availability is licensing and shifts constantly.
 * Rows are replaced wholesale on each refresh rather than merged, so a title
 * leaving a service actually disappears instead of lingering.
 */
class WatchProviders
{
    private const BASE = 'https://api.themoviedb.org/3';

    private const IMAGE_BASE = 'https://image.tmdb.org/t/p/original';

    public function __construct(private readonly SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('tmdb_api_key'));
    }

    /**
     * Refreshes availability for one item.
     *
     * @return int Number of provider rows written.
     */
    public function refresh(MediaItem $item): int
    {
        if (! $this->isConfigured() || ! $this->supports($item)) {
            return 0;
        }

        $tmdbId = $this->tmdbIdFor($item);

        if ($tmdbId === null) {
            return 0;
        }

        $region = strtoupper((string) config('providers.region', 'US'));
        $segment = $item->type === MediaItemType::Movie ? 'movie' : 'tv';

        $response = Http::acceptJson()
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->get(self::BASE . "/{$segment}/{$tmdbId}/watch/providers", [
                'api_key' => $this->settings->get('tmdb_api_key'),
            ]);

        if (! $response->successful()) {
            return 0;
        }

        $offers = $response->json("results.{$region}") ?? [];

        return $this->store($item, $offers, $region);
    }

    /**
     * @param array<string, mixed> $offers
     */
    private function store(MediaItem $item, array $offers, string $region): int
    {
        $lookup = $this->slugsByTmdbId();
        $rows = [];

        // TMDB groups by offer type; "on Netflix" and "rentable from Apple TV"
        // are different answers to "can I watch this".
        foreach (['flatrate' => 'stream', 'rent' => 'rent', 'buy' => 'buy'] as $key => $offerType) {
            foreach ($offers[$key] ?? [] as $provider) {
                $slug = $lookup[$provider['provider_id'] ?? null] ?? null;

                // Providers outside the configured list are skipped rather than
                // invented — the UI has no colour or ordering for them.
                if ($slug === null) {
                    continue;
                }

                $rows[$slug . $offerType] = [
                    'media_item_id' => $item->id,
                    'provider_slug' => $slug,
                    'provider_name' => $provider['provider_name'] ?? $slug,
                    'logo_url' => filled($provider['logo_path'] ?? null)
                        ? self::IMAGE_BASE . $provider['logo_path']
                        : null,
                    'offer_type' => $offerType,
                    'region' => $region,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Replaced rather than merged: a title dropped from a service has to
        // actually disappear.
        $item->availability()->where('region', $region)->delete();

        if ($rows !== []) {
            MediaAvailability::insert(array_values($rows));
        }

        return count($rows);
    }

    /**
     * TMDB provider id => our slug.
     *
     * @return array<int, string>
     */
    private function slugsByTmdbId(): array
    {
        $lookup = [];

        foreach ((array) config('providers.services', []) as $slug => $service) {
            if (filled($service['tmdb_id'] ?? null)) {
                $lookup[$service['tmdb_id']] = $slug;
            }
        }

        return $lookup;
    }

    private function supports(MediaItem $item): bool
    {
        return in_array($item->type, [MediaItemType::Movie, MediaItemType::Show], true);
    }

    private function tmdbIdFor(MediaItem $item): ?int
    {
        $id = $item->type === MediaItemType::Movie
            ? $item->movieMetadata?->tmdb_id
            : $item->showMetadata?->tmdb_id;

        return filled($id) ? (int) $id : null;
    }
}
