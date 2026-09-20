<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Services\ArrServices;
use App\Services\SettingsService;
use App\Services\WebhookNotifier;
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
 * **It configures both the acquisition apps and the metadata sources.** The
 * separate "Metadata Sources" page was folded into this one — the keys are
 * managed here now. Radarr, Sonarr and Lidarr reach the network, spend disk and
 * decide what arrives on this machine; a metadata provider is a read-only API
 * key that only enriches the catalogue. They are different kinds of thing, so
 * the page keeps them in separate sections, but a single place to configure
 * every external service someone would look for is worth more than the tidiness
 * of splitting them by which admin gate each sits behind.
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

    /**
     * The active group filter: '' for all, or a group name. A Livewire property
     * so the chosen filter survives a re-render (editing a key, unlinking).
     */
    public string $filter = '';

    /** Which integration's modal is open, or null. */
    public ?string $editing = null;

    /** The key being typed in the modal. Never pre-filled — see `edit()`. */
    public string $editingKey = '';

    /**
     * The address being typed in the modal.
     *
     * Pre-filled, unlike the key: it is not a secret, and someone correcting a
     * port should not have to retype the whole URL.
     */
    public string $editingUrl = '';

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
        // A keyless toggle has nothing to type — "Set up" just turns it on.
        if ($this->isToggle($key)) {
            app(SettingsService::class)->set($key, true);
            $this->load();

            Notification::make()->title($this->toggleLabel($key).' enabled.')->success()->send();

            return;
        }

        $this->editing = $key;
        $this->editingKey = '';

        // Acquisition apps carry an address; a webhook is *only* an address (the
        // URL the events POST to). A metadata provider is a fixed public API, so
        // has neither.
        if ($this->isWebhook($key)) {
            $this->editingUrl = (string) app(SettingsService::class)->get($key);
        } else {
            $this->editingUrl = array_key_exists($key, config('arr.apps', []))
                ? app(ArrServices::class)->url($key)
                : '';
        }

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
        $this->editingUrl = '';

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
        if ($this->editing === null) {
            return;
        }

        // A webhook is a URL and nothing else: save it and we're done.
        if ($this->isWebhook($this->editing)) {
            $url = trim($this->editingUrl);

            if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
                Notification::make()->title('Enter a valid webhook URL.')->warning()->send();

                return;
            }

            $label = $this->editingRow()['label'] ?? 'Webhook';
            app(SettingsService::class)->set($this->editing, $url);

            $this->closeModal();
            $this->load();

            Notification::make()->title($label.' webhook saved.')->success()->send();

            return;
        }

        $key = trim($this->editingKey);
        $isApp = array_key_exists($this->editing, config('arr.apps', []));

        // The address is saved on its own, because a wrong one is the more
        // likely fault: these apps are installed natively, on a NAS, in
        // someone else's Docker stack or on another machine, and none of those
        // are on the loopback address we default to. Making someone re-enter a
        // working key to correct a port would be a poor trade.
        if ($isApp) {
            $url = trim($this->editingUrl);

            if ($url !== '' && ! filter_var($url, FILTER_VALIDATE_URL)) {
                Notification::make()->title('That does not look like a URL.')->warning()->send();

                return;
            }

            if ($url !== '') {
                app(SettingsService::class)->set("arr.{$this->editing}.url", rtrim($url, '/'));
            }
        }

        if ($key === '') {
            // A URL-only save is a complete action, not a failed one.
            if ($isApp && trim($this->editingUrl) !== '') {
                app(ArrServices::class)->forget();

                $label = $this->editingRow()['label'] ?? 'Integration';

                $this->closeModal();
                $this->load();

                Notification::make()->title($label.' address saved.')->success()->send();

                return;
            }

            Notification::make()->title('Enter a key first.')->warning()->send();

            return;
        }

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

        Notification::make()->title($label.' connected.')->success()->send();
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
        return [...$this->acquisitionRows(), ...$this->metadataRows(), ...$this->toggleRows(), ...$this->webhookRows()];
    }

    /**
     * Keyless integrations — a plain on/off toggle rather than an API key or an
     * address. Deezer is the first (S-259): a free public API, so "connecting" it
     * is just enabling it. `unlinkable` reuses the card's Unlink control to turn
     * it back off.
     *
     * @return array<int, array<string, mixed>>
     */
    private function toggleRows(): array
    {
        $settings = app(SettingsService::class);

        $toggles = [
            'deezer_enabled' => ['Deezer', 'A second cover-art source, no key needed', 'Artwork'],
        ];

        $rows = [];

        foreach ($toggles as $key => [$label, $detail, $group]) {
            $on = (bool) $settings->get($key);

            $rows[] = [
                'key' => $key,
                'group' => $group,
                'label' => $label,
                'detail' => $detail,
                'connected' => $on,
                'warnings' => [],
                'connected_url' => null,
                'unlinkable' => $on,
                'toggle' => true,
            ];
        }

        return $rows;
    }

    /**
     * Webhook notification destinations — Discord, Slack, and a generic hook for
     * ntfy / Apprise / Gotify (S-262, S-263). Each is a URL the admin pastes; a
     * saved URL means events are delivered there. In the Communication group, a
     * new integration type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function webhookRows(): array
    {
        $settings = app(SettingsService::class);

        $hooks = [
            'webhook_discord_url' => ['Discord', 'Post events to a Discord channel'],
            'webhook_slack_url' => ['Slack', 'Post events to a Slack channel'],
            'webhook_generic_url' => ['Webhook', 'Any endpoint — ntfy, Apprise, Gotify, your own'],
        ];

        $rows = [];

        foreach ($hooks as $key => [$label, $detail]) {
            $connected = filled($settings->get($key));

            $rows[] = [
                'key' => $key,
                'group' => 'Communication',
                'label' => $label,
                'detail' => $detail,
                'connected' => $connected,
                'warnings' => [],
                'connected_url' => null,
                'unlinkable' => $connected,
                'webhook' => true,
            ];
        }

        return $rows;
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
        'Communication',
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

    /**
     * Groups to show, honouring the active filter.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function visibleGroups(): array
    {
        $grouped = $this->groupedRows();

        if ($this->filter === '' || ! isset($grouped[$this->filter])) {
            return $grouped;
        }

        return [$this->filter => $grouped[$this->filter]];
    }

    /**
     * The filter chips: "All" plus each group, each with its connected/total
     * count so the page reads at a glance which categories are set up.
     *
     * @return array<int, array{value: string, label: string, connected: int, total: int}>
     */
    public function filterOptions(): array
    {
        $grouped = $this->groupedRows();
        $rows = collect($grouped)->flatten(1);

        $options = [[
            'value' => '',
            'label' => 'All',
            'connected' => $rows->where('connected', true)->count(),
            'total' => $rows->count(),
        ]];

        foreach ($grouped as $group => $groupRows) {
            $options[] = [
                'value' => $group,
                'label' => $group,
                'connected' => collect($groupRows)->where('connected', true)->count(),
                'total' => count($groupRows),
            ];
        }

        return $options;
    }

    /** Sets the active group filter (from a chip click). */
    public function setFilter(string $group): void
    {
        $this->filter = $group;
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
                    ? trim(($app['version'] ?? '').' · '.number_format($app['queued']).' queued', ' ·')
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
     * The metadata providers, configured here.
     *
     * Folded in from the old standalone "Metadata Sources" page: enriching the
     * catalogue is library administration, and these read-only API keys live
     * alongside the acquisition apps now so there is one place for every
     * external service.
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

            'opensubtitles_api_key' => ['OpenSubtitles', 'Subtitles', 'Subtitles'],

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

        // A keyless toggle turns off rather than forgetting a key.
        if ($this->isToggle($key)) {
            $settings->set($key, false);
            $this->load();

            Notification::make()->title($this->toggleLabel($key).' disabled.')->success()->send();

            return;
        }

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

    /** Whether a key is a keyless on/off integration rather than a credential. */
    private function isToggle(string $key): bool
    {
        return collect($this->toggleRows())->contains('key', $key);
    }

    /** The display label for a toggle key. */
    private function toggleLabel(string $key): string
    {
        return collect($this->toggleRows())->firstWhere('key', $key)['label'] ?? 'Integration';
    }

    /** Whether a key is a webhook destination (a URL, not a credential). */
    public function isWebhook(string $key): bool
    {
        return array_key_exists($key, WebhookNotifier::DESTINATIONS);
    }

    /**
     * Sends a test message to a webhook the admin is setting up, so they can
     * confirm the URL before relying on it. Uses the value currently in the
     * modal field, not the saved one, so an unsaved URL can be tested first.
     */
    public function testWebhook(): void
    {
        if ($this->editing === null || ! $this->isWebhook($this->editing)) {
            return;
        }

        $url = trim($this->editingUrl);

        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            Notification::make()->title('Enter a valid webhook URL first.')->warning()->send();

            return;
        }

        $kind = WebhookNotifier::DESTINATIONS[$this->editing];
        $ok = app(WebhookNotifier::class)->test($kind, $url);

        $ok
            ? Notification::make()->title('Test sent — check the channel.')->success()->send()
            : Notification::make()->title('The endpoint did not accept the test.')->danger()->send();
    }

    /** The command that starts the stack, shown when nothing is running. */
    public function startCommand(): string
    {
        return 'docker compose -f docker/arr/compose.yaml up -d';
    }
}
