<?php

namespace App\Services\Metadata\Sources\Music;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

/**
 * Spotify Web API — provides audio features (energy, danceability, valence, tempo, key, mode).
 * Requires client_id + client_secret configured in the Settings UI.
 */
class Spotify implements MetadataSource
{
    public function __construct(private SettingsService $settings) {}

    public function name(): string { return 'Spotify'; }
    public function priority(): int { return 6; }

    public function requiredSettings(): array
    {
        return [
            'spotify_client_id'     => 'Spotify Client ID',
            'spotify_client_secret' => 'Spotify Client Secret',
        ];
    }

    public function supports(MediaItem $item): bool
    {
        if ($item->type !== MediaItemType::Music) {
            return false;
        }


        return filled($this->settings->get('spotify_client_id'))
            && filled($this->settings->get('spotify_client_secret'));
    }

    public function enrich(MediaItem $item): void
    {
        $token = $this->getAccessToken();

        if (! $token) {
            return;
        }

        $spotifyId = $item->musicMetadata?->spotify_id;

        if (! $spotifyId) {
            $spotifyId = $this->searchForTrack($item, $token);
        }

        if (! $spotifyId) {
            return;
        }

        $features = Http::withToken($token)
            ->get("https://api.spotify.com/v1/audio-features/{$spotifyId}")
            ->json();

        if (empty($features)) {
            return;
        }

        $item->musicMetadata?->fill([
            'spotify_id' => $spotifyId,
            'bpm'        => $item->musicMetadata->bpm ?? round($features['tempo'], 1),
            'energy'     => $item->musicMetadata->energy ?? (int) round($features['energy'] * 100),
            // key + mode → musical key string; only write if not already set
            'key'        => $item->musicMetadata->key ?? $this->resolveKey($features['key'] ?? -1),
            'scale'      => $item->musicMetadata->scale ?? ($features['mode'] === 1 ? 'major' : 'minor'),
        ])->saveQuietly();
    }

    private function getAccessToken(): ?string
    {
        $clientId     = $this->settings->get('spotify_client_id');
        $clientSecret = $this->settings->get('spotify_client_secret');

        $response = Http::asForm()->post('https://accounts.spotify.com/api/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        return $response->json('access_token');
    }

    private function searchForTrack(MediaItem $item, string $token): ?string
    {
        $meta  = $item->musicMetadata;
        $query = implode(' ', array_filter([$meta?->artist, $item->title]));

        $results = Http::withToken($token)
            ->get('https://api.spotify.com/v1/search', [
                'q'     => $query,
                'type'  => 'track',
                'limit' => 1,
            ])
            ->json('tracks.items.0.id');

        return $results ?: null;
    }

    // Spotify key integers (Pitch Class notation) → note names
    private function resolveKey(int $key): ?string
    {
        return [0 => 'C', 1 => 'C#', 2 => 'D', 3 => 'D#', 4 => 'E', 5 => 'F',
                6 => 'F#', 7 => 'G', 8 => 'G#', 9 => 'A', 10 => 'A#', 11 => 'B'][$key] ?? null;
    }
}
