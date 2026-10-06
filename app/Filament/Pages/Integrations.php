<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Services\ArrServices;
use App\Services\Metadata\CredentialTester;
use App\Services\Metadata\SourceCatalogue;
use App\Services\Oauth\OauthFlow;
use App\Services\Oauth\OauthProvider;
use App\Services\Oauth\TokenStore;
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
     * The second credential being typed, for a source that needs two.
     *
     * Spotify needs a client id and a secret. One field would have meant
     * either two cards for one service or a key silently half-saved.
     */
    public string $editingSecondKey = '';

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
        $this->editingSecondKey = '';
        $this->testResult = null;

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
        $this->editingSecondKey = '';
        $this->testResult = null;
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

        // A metadata source declaring two settings saves both at once. Done
        // before the single-key path so a half-filled pair cannot be stored as
        // if it were complete -- the state that left this install showing
        // Spotify connected with no client id.
        $declared = $this->editingRow()['keys'] ?? null;

        if (! $isApp && is_array($declared) && count($declared) > 1) {
            $this->saveDeclaredKeys($declared);

            return;
        }

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
        'Subtitles',
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

        // Known groups first, in the order above; then anything it has not
        // heard of. This used to iterate GROUP_ORDER alone, which made the
        // list a *filter* -- OpenSubtitles built a card in a "Subtitles" group
        // that was not on the list, and the page silently rendered 12 of 13
        // rows. A new group must appear in the wrong place rather than vanish.
        $ordered = [
            ...self::GROUP_ORDER,
            ...array_diff($grouped->keys()->all(), self::GROUP_ORDER),
        ];

        foreach ($ordered as $group) {
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
     * The metadata providers, built from what each source declares (#490).
     *
     * Folded in from the old standalone "Metadata Sources" page: enriching the
     * catalogue is library administration, and these read-only API keys live
     * alongside the acquisition apps now so there is one place for every
     * external service.
     *
     * **Derived, not listed.** This used to hold its own list of thirteen
     * providers, while `requiredSettings()` -- which is on the contract
     * precisely to drive this page -- went unread. The two drifted, and seven
     * of those thirteen keys turned out to be read by nothing at all. A source
     * now gets a card by declaring a setting, and a key with no source behind
     * it appears in its own section saying so.
     *
     * @return array<int, array<string, mixed>>
     */
    private function metadataRows(): array
    {
        $rows = [];

        foreach (app(SourceCatalogue::class)->credentialled() as $source) {
            $keys = $source['keys'];

            $rows[] = [
                // The first key identifies the card. Spotify needs two and is
                // one integration -- two cards would ask somebody to connect
                // the same service twice.
                'key' => (string) array_key_first($keys),
                'keys' => $keys,
                'group' => $source['group'],
                'label' => $source['name'],
                'detail' => $source['adds'],
                'connected' => $source['configured'],
                // Half-configured is its own state. This install has a Spotify
                // secret and no client id, which the old page showed as
                // connected while `supports()` returned false -- green card,
                // nothing happening.
                'partial' => $source['partial'],
                'warnings' => $source['partial']
                    ? ['Half set up: '.$this->missingKeyLabels($keys).' still needed.']
                    : [],
                'connected_url' => null,
                'unlinkable' => $source['configured'] || $source['partial'],
            ];
        }

        return $rows;
    }

    /**
     * Sources that need no key, for the "already working" section.
     *
     * @return array<int, array{name: string, group: string, adds: string}>
     */
    public function keylessSources(): array
    {
        return app(SourceCatalogue::class)->keyless();
    }

    /**
     * Keys this page used to collect that nothing reads.
     *
     * Shown rather than quietly dropped. Somebody who wants lyrics should be
     * able to see that we know lyrics are missing, and somebody who already
     * pasted a Discogs token needs telling it is doing nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unimplementedSources(): array
    {
        return app(SourceCatalogue::class)->unimplemented();
    }

    /** Whether any dead key is still stored, so the page can say so. */
    public function hasStoredDeadKeys(): bool
    {
        return collect($this->unimplementedSources())->contains('stored', true);
    }

    /** Forgets a key for a source that was never built. */
    public function forgetDeadKey(string $key): void
    {
        if (! app(SourceCatalogue::class)->isUnimplemented($key)) {
            return;
        }

        app(SettingsService::class)->forget($key);

        Notification::make()->title('Removed. Nothing was reading it.')->success()->send();
    }

    /** The labels of whichever keys are still missing, for the warning text. */
    private function missingKeyLabels(array $keys): string
    {
        $settings = app(SettingsService::class);

        $missing = [];

        foreach ($keys as $key => $label) {
            if (blank($settings->get($key))) {
                $missing[] = $label;
            }
        }

        return implode(' and ', $missing);
    }

    /**
     * Saves a source that needs more than one credential.
     *
     * Both or neither, in the order declared. Storing one of a pair is what
     * produces a card that says Connected while `supports()` returns false,
     * and an integration that silently does nothing is the fault this whole
     * change is about.
     *
     * An already-stored value is kept when its field is left blank, so
     * correcting a mistyped secret does not mean re-pasting the client id --
     * neither is readable from the page to copy.
     *
     * @param  array<string, string>  $declared
     */
    private function saveDeclaredKeys(array $declared): void
    {
        $settings = app(SettingsService::class);
        $typed = [trim($this->editingKey), trim($this->editingSecondKey)];

        $values = [];
        $i = 0;

        foreach ($declared as $key => $label) {
            $given = $typed[$i] ?? '';
            $i++;

            // Blank means "leave what is there", not "clear it".
            $values[$key] = $given !== '' ? $given : (string) $settings->get($key);
        }

        $missing = array_keys(array_filter($values, fn (string $v): bool => $v === ''));

        if ($missing !== []) {
            $labels = implode(' and ', array_map(fn (string $k): string => $declared[$k], $missing));

            Notification::make()
                ->title('Both are needed')
                ->body($labels.' is still empty, and the source does nothing without it.')
                ->warning()
                ->send();

            return;
        }

        foreach ($values as $key => $value) {
            $settings->set($key, $value, encrypt: true);
        }

        $label = $this->editingRow()['label'] ?? 'Integration';

        $this->closeModal();
        $this->load();

        Notification::make()->title($label.' connected.')->success()->send();
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

        if (! $isApp) {
            // Every key the source declares, not just the one naming the card.
            // Forgetting one of a pair leaves an orphan credential behind --
            // which is exactly how this install ended up holding a Spotify
            // secret with no client id.
            foreach ($this->declaredKeysFor($key) as $declared) {
                $settings->forget($declared);
            }
        } else {
            $settings->forget("arr.{$key}.api_key");
        }

        if ($isApp) {
            app(ArrServices::class)->forget();
        }

        $this->load();

        Notification::make()->title('Unlinked.')->success()->send();
    }

    /**
     * Every settings key belonging to the card a key names.
     *
     * Falls back to the key itself, so a webhook, a toggle or anything not
     * coming from the catalogue behaves exactly as before.
     *
     * @return array<int, string>
     */
    private function declaredKeysFor(string $key): array
    {
        foreach (app(SourceCatalogue::class)->credentialled() as $source) {
            if (array_key_exists($key, $source['keys'])) {
                return array_keys($source['keys']);
            }
        }

        return [$key];
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
     * The services that need signing in to, not just a key (#490).
     *
     * Separate from the key cards because the question is different: a key is
     * pasted once and belongs to the install, while a sign-in belongs to a
     * person and expires. Trakt's whole purpose is one particular person's
     * watch history, and no pasted secret can stand in for that -- which is
     * why a Trakt key field was among the seven that could never work.
     *
     * @return array<int, array<string, mixed>>
     */
    public function signInSources(): array
    {
        $flow = app(OauthFlow::class);
        $tokens = app(TokenStore::class);

        $out = [];

        foreach (OauthProvider::cases() as $provider) {
            $out[] = [
                'slug' => $provider->value,
                'label' => $provider->label(),
                'adds' => $provider->adds(),
                'registered' => $flow->isRegistered($provider),
                'connected' => $tokens->isConnected($provider),
                'expires_at' => $tokens->expiresAt($provider)?->diffForHumans(),
                'redirect_uri' => $flow->redirectUri($provider),
            ];
        }

        return $out;
    }

    /** Signs a service out on this install. */
    public function signOut(string $slug): void
    {
        $provider = OauthProvider::tryFromSlug($slug);

        if ($provider === null) {
            return;
        }

        app(TokenStore::class)->forget($provider);

        Notification::make()->title($provider->label().' signed out.')->success()->send();
    }

    /**
     * The result of the last credential test, or null.
     *
     * Kept on the page rather than shown as a toast: a toast disappears while
     * somebody is still reading a key off another screen, and the one thing
     * they need to see is whether this one worked.
     *
     * @var array{ok: bool, message: string}|null
     */
    public ?array $testResult = null;

    /** Whether the open modal's credential can be checked against its service. */
    public function canTestEditing(): bool
    {
        if ($this->editing === null) {
            return false;
        }

        return app(CredentialTester::class)->canTest($this->editing);
    }

    /**
     * Checks the key currently typed in the modal against its service.
     *
     * Tests what is **typed**, not what is stored, so a bad paste never has to
     * become a stored credential first. Nothing is saved either way -- the
     * question is only whether this key works.
     */
    public function testCredential(): void
    {
        if ($this->editing === null) {
            return;
        }

        $declared = $this->editingRow()['keys'] ?? [$this->editing => ''];
        $typed = [trim($this->editingKey), trim($this->editingSecondKey)];

        $settings = app(SettingsService::class);
        $values = [];
        $i = 0;

        foreach (array_keys($declared) as $key) {
            $given = $typed[$i] ?? '';
            $i++;

            // An untouched field falls back to what is stored, so "test" works
            // on a saved key as well as on a newly pasted one.
            $values[$key] = $given !== '' ? $given : (string) $settings->get($key);
        }

        $this->testResult = app(CredentialTester::class)->test($this->editing, $values);
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
