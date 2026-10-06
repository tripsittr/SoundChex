<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Metadata;

use App\Services\Metadata\Contracts\MetadataSource;
use App\Services\SettingsService;
use App\Services\Subtitles\OpenSubtitles;
use Illuminate\Support\Facades\Log;

/**
 * Every outside source, what it needs, and what entering a key buys you (#490).
 *
 * The Integrations page hardcoded its own list of thirteen fields, and
 * `requiredSettings()` — which is on the `MetadataSource` contract *precisely*
 * to drive that page — was read **zero times**. The two drifted, and the
 * measured result was seven keys the page collects that **no code reads at
 * all**: OMDb, Trakt, Discogs, Genius, Musixmatch, Google Books and Fanart.tv.
 * Someone pastes a token, the card goes green, and nothing happens — which is
 * worse than never asking.
 *
 * So the page is built from this instead. A source that declares a setting gets
 * a field without anybody editing the page, and a field with no source behind
 * it is visible as exactly that.
 *
 * It also carries what no code had anywhere: **what the key is for.** A field
 * labelled "Discogs Token" with no explanation is a field nobody fills in.
 */
class SourceCatalogue
{
    /**
     * The sources that are actually wired up, in the order to show them.
     *
     * Listed rather than discovered. A `glob()` over the directory would pick
     * up an abstract base or a half-finished class and put a field on the
     * settings page for it, which is the failure this whole class exists to
     * end.
     *
     * `OpenSubtitles` is not a `MetadataSource` — it fetches subtitles, not
     * metadata — but it holds a key the page collects, and a user does not
     * care which interface it implements. Typed as the union below so both
     * belong.
     *
     * @var array<int, class-string>
     */
    private const SOURCES = [
        // Identity: these decide *what* a file is.
        Sources\Music\FileTagger::class,
        Sources\Music\MusicBrainz::class,
        Sources\Music\AcoustId::class,
        Sources\Movie\Tmdb::class,
        Sources\Movie\Omdb::class,
        Sources\Show\Tmdb::class,
        Sources\Show\Tvdb::class,
        Sources\Book\OpenLibrary::class,

        // Enrichment: these add to something already identified.
        Sources\Music\Spotify::class,
        Sources\Music\Lastfm::class,
        Sources\Music\Deezer::class,
        Sources\Music\ItunesSearch::class,

        // Not metadata, but it holds a key this page offers.
        OpenSubtitles::class,
    ];

    /**
     * Which group each source belongs in, and one line on what it adds.
     *
     * Keyed by `name()` rather than by class, so a plugin source can describe
     * itself the same way without this file knowing it exists.
     *
     * The descriptions are written for somebody deciding whether getting a key
     * is worth the trouble, which means saying what they get and what it
     * costs — "free, no key" is the most useful thing on the page for five of
     * these.
     *
     * @var array<string, array{group: string, adds: string}>
     */
    private const PROFILES = [
        'File Tags (getID3)' => [
            'group' => 'Music',
            'adds' => 'Reads the tags already inside your files — artist, album, track numbers, MusicBrainz ids, embedded cover art. Needs nothing and runs first.',
        ],
        'MusicBrainz' => [
            'group' => 'Music',
            'adds' => 'The main music database: recordings, releases, credits and ids. Free, no key, rate-limited to one request a second.',
        ],
        'AcoustID (fingerprint)' => [
            'group' => 'Music',
            'adds' => 'Identifies a track from the audio itself when the tags are wrong or missing — the only thing here that can name an untagged file.',
        ],
        'Spotify' => [
            'group' => 'Music',
            'adds' => 'Audio features and artist detail, and — once signed in — your own playlists for importing. Needs an app registered with Spotify.',
        ],
        'Last.fm' => [
            'group' => 'Music',
            'adds' => 'Listener counts, tags and artist biographies. A free key.',
        ],
        'Deezer' => [
            'group' => 'Artwork',
            'adds' => 'A fallback for cover art when nothing else has one. Free, no key.',
        ],
        'iTunes Search' => [
            'group' => 'Artwork',
            'adds' => 'High-resolution cover art, and a fallback for album details. Free, no key.',
        ],
        'OMDb' => [
            'group' => 'Film & TV',
            'adds' => 'The IMDb rating, the Rotten Tomatoes score, Metacritic, and the awards a film won. Nothing else here carries any of them. A free key.',
        ],
        'TMDB' => [
            'group' => 'Film & TV',
            'adds' => 'Films and television: titles, years, synopses, cast, posters and backdrops. One free key covers both.',
        ],
        // 'TVDB', not 'TheTVDB': keyed off what name() actually returns,
        // which the Other fallback caught when this was guessed wrong.
        'TVDB' => [
            'group' => 'Film & TV',
            'adds' => 'A second opinion on episode numbering, which the two databases sometimes disagree about.',
        ],
        'Open Library' => [
            'group' => 'Books',
            'adds' => 'Books: authors, publication dates, covers and subjects. Free, no key.',
        ],
        'OpenSubtitles' => [
            'group' => 'Subtitles',
            'adds' => 'Downloads subtitles for films and episodes. A free account gives a daily quota.',
        ],
    ];

    /**
     * Settings the page used to offer that nothing reads.
     *
     * Named explicitly rather than inferred. Inferring it would mean grepping
     * at runtime for each key, which is both slow and wrong the moment a
     * source reads a key through a variable — and a list that quietly stops
     * flagging something is how this drifted in the first place. Writing them
     * down makes removing an entry part of implementing the source.
     *
     * Each says what it *would* add, because a user who wants lyrics should be
     * able to see that we know lyrics are missing rather than concluding we
     * never thought of it.
     *
     * @var array<string, array{label: string, group: string, would_add: string}>
     */
    public const UNIMPLEMENTED = [
        'trakt_client_secret' => [
            'label' => 'Trakt',
            'group' => 'Film & TV',
            'would_add' => 'Watch history and scrobbling. Needs signing in, not a pasted key.',
        ],
        'fanart_tv_api_key' => [
            'label' => 'Fanart.tv',
            'group' => 'Artwork',
            'would_add' => 'Film and TV artwork beyond posters: logos, banners, disc art, character art.',
        ],
        'discogs_token' => [
            'label' => 'Discogs',
            'group' => 'Music',
            'would_add' => 'Pressing-level release detail, labels and catalogue numbers — the thing that tells two issues of one album apart.',
        ],
        'genius_api_key' => [
            'label' => 'Genius',
            'group' => 'Lyrics',
            'would_add' => 'Lyrics.',
        ],
        'musixmatch_api_key' => [
            'label' => 'Musixmatch',
            'group' => 'Lyrics',
            'would_add' => 'Lyrics, including time-synced lyrics.',
        ],
        'google_books_api_key' => [
            'label' => 'Google Books',
            'group' => 'Books',
            'would_add' => 'A second book source, for anything Open Library does not have.',
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Every source that resolves.
     *
     * A class that fails to construct is logged and skipped rather than fatal.
     * One bad source must not take the settings page down, because the settings
     * page is where somebody would go to fix it.
     *
     * @return array<int, MetadataSource|OpenSubtitles>
     */
    public function sources(): array
    {
        $sources = [];

        foreach (self::SOURCES as $class) {
            try {
                $sources[] = app($class);
            } catch (\Throwable $e) {
                Log::warning('A metadata source could not be loaded for the integrations page', [
                    'class' => $class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sources;
    }

    /**
     * The sources that need a credential, each with its fields.
     *
     * One entry per source, not per key: Spotify needs two settings and is one
     * integration, and showing it as two cards would ask somebody to connect
     * the same service twice.
     *
     * @return array<int, array{name: string, group: string, adds: string, keys: array<string, string>, configured: bool, partial: bool}>
     */
    public function credentialled(): array
    {
        $out = [];
        $claimed = [];

        foreach ($this->sources() as $source) {
            $keys = $this->normalise($source->requiredSettings());

            if ($keys === []) {
                continue;
            }

            // Two sources sharing a credential -- the Movie and Show TMDB
            // sources do -- is one card, not two. The first wins, which is why
            // the list above puts Movie first.
            if (array_intersect(array_keys($keys), $claimed) !== []) {
                continue;
            }

            $claimed = [...$claimed, ...array_keys($keys)];

            $set = array_filter(array_keys($keys), fn (string $key): bool => filled($this->settings->get($key)));

            $out[] = [
                'name' => $source->name(),
                'group' => $this->profile($source->name())['group'],
                'adds' => $this->profile($source->name())['adds'],
                'keys' => $keys,
                'configured' => count($set) === count($keys),
                // Spotify with an id and no secret is not connected, and
                // saying so is more use than a red cross: the user has done
                // half the job and needs to know which half.
                'partial' => $set !== [] && count($set) !== count($keys),
            ];
        }

        return $out;
    }

    /**
     * Sources that need no credential at all.
     *
     * Worth showing. Somebody looking at a page of API-key fields reasonably
     * concludes nothing works without one, when in fact the file tagger,
     * MusicBrainz, iTunes, Deezer and Open Library — which between them do
     * most of the identifying — all work out of the box.
     *
     * @return array<int, array{name: string, group: string, adds: string}>
     */
    public function keyless(): array
    {
        $out = [];

        foreach ($this->sources() as $source) {
            if ($this->normalise($source->requiredSettings()) !== []) {
                continue;
            }

            $out[] = [
                'name' => $source->name(),
                'group' => $this->profile($source->name())['group'],
                'adds' => $this->profile($source->name())['adds'],
            ];
        }

        return $out;
    }

    /**
     * Keys the page offers that nothing reads, and whether one is set.
     *
     * A key already stored is worth surfacing rather than hiding: somebody
     * pasted it, believes it is working, and should be told it is not.
     *
     * @return array<int, array{key: string, label: string, group: string, would_add: string, stored: bool}>
     */
    public function unimplemented(): array
    {
        $out = [];

        foreach (self::UNIMPLEMENTED as $key => $profile) {
            $out[] = [
                'key' => $key,
                'label' => $profile['label'],
                'group' => $profile['group'],
                'would_add' => $profile['would_add'],
                'stored' => filled($this->settings->get($key)),
            ];
        }

        return $out;
    }

    /** Whether a settings key belongs to a source that is not built yet. */
    public function isUnimplemented(string $key): bool
    {
        return array_key_exists($key, self::UNIMPLEMENTED);
    }

    /**
     * Every credential key a real source reads.
     *
     * @return array<int, string>
     */
    public function liveKeys(): array
    {
        $keys = [];

        foreach ($this->sources() as $source) {
            $keys = [...$keys, ...array_keys($this->normalise($source->requiredSettings()))];
        }

        return array_values(array_unique($keys));
    }

    /** @return array{group: string, adds: string} */
    private function profile(string $name): array
    {
        // A source with no entry still gets a card -- a plugin's source, most
        // likely. Grouped under Other rather than dropped, because silently
        // omitting an integration from the page is the bug being fixed.
        return self::PROFILES[$name] ?? ['group' => 'Other', 'adds' => ''];
    }

    /**
     * Forces `requiredSettings()` into `key => label` shape.
     *
     * The contract documents a map, and two sources returned a bare list
     * anyway — which makes the key an integer and the label the key, so the
     * page would have rendered a field called "0". Both are fixed, but a
     * plugin can still get it wrong and a settings page is a poor place to
     * discover that.
     *
     * @param  array<array-key, string>  $settings
     * @return array<string, string>
     */
    private function normalise(array $settings): array
    {
        $out = [];

        foreach ($settings as $key => $label) {
            if (is_int($key)) {
                $key = $label;
                $label = ucwords(str_replace('_', ' ', (string) $label));
            }

            $out[(string) $key] = (string) $label;
        }

        return $out;
    }
}
