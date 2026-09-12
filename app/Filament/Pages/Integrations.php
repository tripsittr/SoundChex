<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Services\ArrServices;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Every outside service this install talks to, and where each is set up.
 *
 * One page to answer "what is connected?", because the answer was previously
 * spread across a settings page, an env file and a container that may or may
 * not be running — and nothing said which.
 *
 * **It configures the acquisition apps and only points at the rest.** Metadata
 * sources keep their own page under Settings, deliberately: they are read-only
 * API keys and enriching the catalogue *is* library administration, so a
 * profile trusted with the library can manage them. Radarr, Sonarr and Lidarr
 * are not that — they reach the network, spend disk and decide what arrives on
 * this machine, which is why this page sits behind the server-administration
 * gate. Merging the two would mean either locking metadata keys away from the
 * people who should have them, or opening acquisition to people who should
 * not. Listing both and linking out costs one click and keeps the line.
 *
 * **Not running is normal.** Most installs have no Docker, and this page says
 * so plainly rather than presenting an error, because there is nothing wrong.
 */
class Integrations extends Page
{
    use RestrictsToServerAdmins;

    protected string $view = 'filament.pages.integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Integrations';

    protected static ?string $navigationLabel = 'Integrations';

    /** After Services, which is the page that matters more often. */
    protected static ?int $navigationSort = 20;

    /** @var array<string, array<string, mixed>> */
    public array $apps = [];

    /** Which integration's modal is open, or null. */
    public ?string $editing = null;

    /** The key being typed in the modal. Never pre-filled — see `edit()`. */
    public string $editingKey = '';

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->apps = app(ArrServices::class)->all();
    }

    /**
     * Opens the modal for one integration.
     *
     * The field starts empty even when a key is already stored, rather than
     * showing the current one. These are stored encrypted precisely so they
     * are not readable from the panel, and decrypting one back onto a screen
     * to populate a form would undo that for the sake of a field nobody edits
     * in place — a key is replaced, not amended.
     */
    public function edit(string $key): void
    {
        $this->editing = $key;
        $this->editingKey = '';

        // Filament's modal is opened by a browser event, not by a property.
        // Binding `:visible` to state looks like it should work and does
        // nothing — the markup renders with the hidden class and no Alpine
        // handler ever runs, so the button appeared dead.
        $this->dispatch('open-modal', id: 'integration');
    }

    public function closeModal(): void
    {
        $this->editing = null;
        $this->editingKey = '';

        $this->dispatch('close-modal', id: 'integration');
    }

    /**
     * The row the modal is currently showing.
     *
     * @return array<string, mixed>|null
     */
    public function editingRow(): ?array
    {
        if ($this->editing === null) {
            return null;
        }

        return collect($this->rows())->firstWhere('key', $this->editing);
    }

    /** Saves whatever the modal is editing, app key or metadata key alike. */
    public function saveModal(): void
    {
        $key = trim($this->editingKey);

        if ($key === '' || $this->editing === null) {
            Notification::make()->title('Enter a key first.')->warning()->send();

            return;
        }

        $isApp = array_key_exists($this->editing, config('arr.apps', []));

        // Encrypted either way. An acquisition key is full control over that
        // app; a metadata key is someone's paid API quota.
        app(SettingsService::class)->set(
            $isApp ? "arr.{$this->editing}.api_key" : $this->editing,
            $key,
            encrypt: true,
        );

        if ($isApp) {
            app(ArrServices::class)->forget();
        }

        $label = $this->editingRow()['label'] ?? 'Integration';

        $this->closeModal();
        $this->load();

        Notification::make()->title($label . ' connected.')->success()->send();
    }

    /**
     * Whether any of them are up.
     *
     * Drives the explanation at the top of the page: with none running, the
     * useful thing to show is how to start them, not three identical rows
     * saying the same thing.
     */
    public function anyRunning(): bool
    {
        return collect($this->apps)->contains(fn (array $app): bool => $app['running'] === true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    app(ArrServices::class)->forget();
                    $this->load();
                }),
        ];
    }

    /**
     * Every integration this install can use, as one list.
     *
     * One shape for all of them, because from the outside they are the same
     * question — is this connected, and where do I go to change it? What
     * differs is who may answer it, which is why `manage_url` is null for a
     * page the current profile cannot open rather than being hidden entirely:
     * the row still says the provider is connected, which is true and useful.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return [...$this->acquisitionRows(), ...$this->metadataRows()];
    }

    /**
     * The groups, in the order they should be read.
     *
     * Fixed rather than derived from the rows: `groupBy()` returns them in
     * whatever order they were built, which is an implementation detail and
     * would reshuffle the page the moment a provider is added in the middle.
     *
     * Acquisition leads because it is the only group this page can change.
     *
     * @return array<int, string>
     */
    public const GROUP_ORDER = [
        'Acquisition',
        'Film & TV',
        'Music',
        'Lyrics',
        'Books',
        'Artwork',
    ];

    /**
     * Rows grouped and ordered for display, connected first within each group.
     *
     * Connected first because a configured provider is the one with something
     * to say — a version, a warning, a queue — and burying it under eight
     * unconfigured ones makes the page look emptier than it is.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function groupedRows(): array
    {
        $grouped = collect($this->rows())->groupBy('group');

        $out = [];

        foreach (self::GROUP_ORDER as $group) {
            if (! $grouped->has($group)) {
                continue;
            }

            $out[$group] = $grouped[$group]
                ->sortByDesc('connected')
                // Stable within each half, so the recommended order above
                // survives the sort rather than being scrambled by it.
                ->values()
                ->all();
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function acquisitionRows(): array
    {
        $rows = [];

        foreach ($this->apps as $name => $app) {
            $rows[] = [
                'key' => $name,
                'group' => 'Acquisition',
                'label' => $app['label'],
                'detail' => $app['running']
                    // What it is doing, not merely that it is up — a queue
                    // depth is the number someone actually came here for.
                    ? trim(($app['version'] ?? '') . ' · ' . number_format($app['queued']) . ' queued', ' ·')
                    : ($app['error'] ?? $app['kind']),
                'connected' => $app['running'],
                'warnings' => $app['warnings'],
                'connected_url' => $app['running'] ? $app['url'] : null,
                'unlinkable' => ! $app['needs_key'],
            ];
        }

        return $rows;
    }

    /**
     * The metadata providers.
     *
     * Listed here, configured on their own page. They belong to library
     * administration — enriching the catalogue is what a library admin does —
     * while this page is gated to server administration, so moving the form
     * here would lock those keys away from the people who should have them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function metadataRows(): array
    {
        $settings = app(SettingsService::class);

        // Grouped by what they are *for*, not alphabetically. Twelve providers
        // in one flat list is a wall of names — someone here is asking "what
        // improves my films?", and the grouping answers that directly.
        //
        // Within each group, the most useful first: TMDB before OMDb because
        // it covers both films and television, MusicBrainz-adjacent sources
        // before lyrics, and so on. Order is a recommendation.
        $sources = [
            'tmdb_api_key' => ['TMDB', 'Films and television', 'Film & TV'],
            'tvdb_api_key' => ['TVDB', 'Television', 'Film & TV'],
            'omdb_api_key' => ['OMDb', 'Films, ratings', 'Film & TV'],
            'trakt_client_secret' => ['Trakt', 'Watch history', 'Film & TV'],

            'spotify_client_secret' => ['Spotify', 'Albums and artists', 'Music'],
            'discogs_token' => ['Discogs', 'Releases and credits', 'Music'],
            'lastfm_api_key' => ['Last.fm', 'Listening data', 'Music'],
            'acoustid_api_key' => ['AcoustID', 'Identifies untagged audio', 'Music'],

            'genius_api_key' => ['Genius', 'Lyrics', 'Lyrics'],
            'musixmatch_api_key' => ['Musixmatch', 'Lyrics', 'Lyrics'],

            'google_books_api_key' => ['Google Books', 'Books', 'Books'],

            'fanart_tv_api_key' => ['Fanart.tv', 'Posters and backdrops', 'Artwork'],
        ];

        $rows = [];

        foreach ($sources as $key => [$label, $detail, $group]) {
            $connected = filled($settings->get($key));

            $rows[] = [
                'key' => $key,
                'group' => $group,
                'label' => $label,
                'detail' => $detail,
                'connected' => $connected,
                'warnings' => [],
                'connected_url' => null,
                'unlinkable' => $connected,
            ];
        }

        return $rows;
    }

    /**
     * Forgets one integration's credential.
     *
     * Only the key is removed. Nothing is uninstalled and no container is
     * stopped — "unlink" here means this install stops talking to it, which is
     * reversible by pasting the key back. Anything more destructive would be a
     * surprising thing for a button on a list to do.
     */
    public function unlink(string $key): void
    {
        $settings = app(SettingsService::class);

        // Acquisition keys are namespaced; metadata keys are not. Told apart
        // by whether the key names a configured app rather than by guessing
        // at the string, so a metadata provider called "radarr" could not
        // collide with one.
        $isApp = array_key_exists($key, config('arr.apps', []));

        $settings->forget($isApp ? "arr.{$key}.api_key" : $key);

        if ($isApp) {
            app(ArrServices::class)->forget();
        }

        $this->load();

        Notification::make()->title('Unlinked.')->success()->send();
    }

    /** The command that starts the stack, shown when nothing is running. */
    public function startCommand(): string
    {
        return 'docker compose -f docker/arr/compose.yaml up -d';
    }
}
