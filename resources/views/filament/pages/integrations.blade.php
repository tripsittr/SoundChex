<x-filament-panels::page>

    @php
        /** Filtered + grouped on the page object, so ordering and the active
            filter are decisions rather than whatever `groupBy()` returned. */
        $groups = $this->visibleGroups();
        $filters = $this->filterOptions();
    @endphp

    {{-- Filter by kind. Chips rather than a select: a handful of categories,
         each worth showing with its connected/total count so the page says at a
         glance what is set up. --}}
    <div class="flex flex-wrap gap-2">
        @foreach ($filters as $option)
            @php $active = $this->filter === $option['value']; @endphp
            <button
                type="button"
                wire:click="setFilter('{{ $option['value'] }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition',
                    'bg-primary-600 text-white shadow' => $active,
                    'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => ! $active,
                ])
            >
                {{ $option['label'] }}
                <span @class(['text-xs', 'text-white/70' => $active, 'opacity-60' => ! $active])>
                    {{ $option['connected'] }}/{{ $option['total'] }}
                </span>
            </button>
        @endforeach
    </div>

    @foreach ($groups as $group => $rows)
        <x-filament::section>
            <x-slot name="heading">{{ $group }}</x-slot>
            <x-slot name="description">
                {{ collect($rows)->where('connected', true)->count() }} of {{ count($rows) }} connected
            </x-slot>

            {{-- Cards, not a list: each integration is its own tile so the page
                 feels less cramped and a connected one reads as a distinct
                 object rather than a row in a wall of text. --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($rows as $row)
                    <div @class([
                        'flex flex-col justify-between gap-3 rounded-xl border p-4 transition',
                        'border-primary-500/40 bg-primary-50/40 dark:bg-primary-500/5' => $row['connected'],
                        'border-gray-200 dark:border-white/10' => ! $row['connected'],
                    ])>
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-semibold">{{ $row['label'] }}</span>

                                @if ($row['connected'])
                                    <x-filament::badge color="success" size="sm">Connected</x-filament::badge>
                                @elseif (! empty($row['partial']))
                                    {{-- Half set up is neither connected nor
                                         untouched, and calling it either is
                                         how a green card came to sit over a
                                         source that did nothing. --}}
                                    <x-filament::badge color="warning" size="sm">Half set up</x-filament::badge>
                                @endif

                                @if (count($row['warnings']) > 0)
                                    {{-- An app that is up but cannot reach its
                                         download client looks healthy and
                                         quietly does nothing. --}}
                                    <x-filament::badge color="warning" size="sm">
                                        {{ count($row['warnings']) }} warning{{ count($row['warnings']) === 1 ? '' : 's' }}
                                    </x-filament::badge>
                                @endif
                            </div>

                            {{-- Not truncated: for a metadata source this is
                                 the sentence saying what a key buys you, and a
                                 clipped half-sentence is the reason nobody
                                 filled these in. --}}
                            <p class="text-sm opacity-60">{{ $row['detail'] }}</p>
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($row['connected'] || $row['unlinkable'])
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    wire:click="edit('{{ $row['key'] }}')">
                                    Manage
                                </x-filament::button>

                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    outlined
                                    wire:click="unlink('{{ $row['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="unlink">
                                    Unlink
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    size="sm"
                                    wire:click="edit('{{ $row['key'] }}')">
                                    Set up
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endforeach

    {{-- Flash messages from the OAuth round trip. The callback is a browser
         redirect, so its outcome cannot be a Livewire notification -- the page
         is loaded fresh afterwards. --}}
    @if (session('oauth_success'))
        <x-filament::section>
            <div class="rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-400">
                {{ session('oauth_success') }}
            </div>
        </x-filament::section>
    @endif

    @if (session('oauth_error'))
        <x-filament::section>
            <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                {{ session('oauth_error') }}
            </div>
        </x-filament::section>
    @endif

    {{-- Services you sign in to. Separate from the key cards on purpose: a key
         is pasted once and belongs to the install, a sign-in belongs to a
         person and expires. --}}
    @if ($this->filter === '')
        <x-filament::section>
            <x-slot name="heading">Signed-in services</x-slot>
            <x-slot name="description">
                These need an account, not just a key — a pasted secret cannot read your own playlists or watch history
            </x-slot>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($this->signInSources() as $source)
                    <div @class([
                        'flex flex-col gap-3 rounded-xl border p-4',
                        'border-primary-500/40 bg-primary-50/40 dark:bg-primary-500/5' => $source['connected'],
                        'border-gray-200 dark:border-white/10' => ! $source['connected'],
                    ])>
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold">{{ $source['label'] }}</span>

                                @if ($source['connected'])
                                    <x-filament::badge color="success" size="sm">Signed in</x-filament::badge>
                                @elseif (! $source['registered'])
                                    <x-filament::badge color="gray" size="sm">Needs an app first</x-filament::badge>
                                @endif
                            </div>

                            <p class="text-sm opacity-60">{{ $source['adds'] }}</p>

                            @if (! $source['registered'])
                                {{-- The redirect URI, because a mismatch here is
                                     the single most common reason an OAuth setup
                                     fails and the service's own error says only
                                     that it did not match. --}}
                                <div class="mt-2 space-y-1">
                                    <p class="text-xs opacity-60">
                                        Register an app with {{ $source['label'] }}, paste its id and secret above,
                                        then give it exactly this redirect URI:
                                    </p>
                                    <pre class="overflow-x-auto rounded-lg bg-gray-950 p-2 text-xs text-gray-100">{{ $source['redirect_uri'] }}</pre>
                                </div>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($source['registered'])
                                <x-filament::button
                                    tag="a"
                                    size="sm"
                                    href="{{ route('oauth.redirect', ['provider' => $source['slug']]) }}">
                                    {{ $source['connected'] ? 'Sign in again' : 'Sign in' }}
                                </x-filament::button>
                            @endif

                            @if ($source['connected'])
                                <x-filament::button
                                    size="sm"
                                    color="danger"
                                    outlined
                                    wire:click="signOut('{{ $source['slug'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="signOut">
                                    Sign out
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Sources needing nothing. A page made entirely of API-key fields
         implies nothing works without one, when in fact the file tagger,
         MusicBrainz, iTunes, Deezer and Open Library -- which between them do
         most of the identifying -- all work out of the box. --}}
    @if (count($this->keylessSources()) > 0 && ($this->filter === '' || $this->filter === 'Music'))
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Working already, no key needed</x-slot>
            <x-slot name="description">
                {{ count($this->keylessSources()) }} sources that need no account and no setup
            </x-slot>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($this->keylessSources() as $source)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold">{{ $source['name'] }}</span>
                            <x-filament::badge color="success" size="sm">Active</x-filament::badge>
                        </div>
                        <p class="mt-1 text-sm opacity-60">{{ $source['adds'] }}</p>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Keys this page used to collect that nothing reads. Shown rather than
         quietly removed: somebody who wants lyrics should see that we know
         lyrics are missing, and somebody who already pasted a Discogs token
         needs telling it is doing nothing. --}}
    @if ($this->filter === '')
        <x-filament::section collapsible :collapsed="! $this->hasStoredDeadKeys()">
            <x-slot name="heading">Not built yet</x-slot>
            <x-slot name="description">
                {{ count($this->unimplementedSources()) }} services SoundChex does not talk to — a key here would do nothing
            </x-slot>

            <div class="space-y-3">
                @if ($this->hasStoredDeadKeys())
                    <div class="rounded-lg bg-warning-50 p-3 text-xs text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                        A key is stored for one of these. It was collected by an
                        earlier version of this page and nothing reads it — the
                        service is not connected, whatever was pasted.
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($this->unimplementedSources() as $source)
                        <div class="flex items-start justify-between gap-3 rounded-xl border border-dashed border-gray-300 p-4 dark:border-white/10">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold opacity-70">{{ $source['label'] }}</span>
                                    <span class="text-xs opacity-50">{{ $source['group'] }}</span>

                                    @if ($source['stored'])
                                        <x-filament::badge color="warning" size="sm">Key stored, unused</x-filament::badge>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm opacity-60">Would add: {{ $source['would_add'] }}</p>
                            </div>

                            @if ($source['stored'])
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    outlined
                                    wire:click="forgetDeadKey('{{ $source['key'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="forgetDeadKey">
                                    Remove
                                </x-filament::button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </x-filament::section>
    @endif

    {{-- One modal for every row: the question is the same in each case, and a
         modal per integration would be fifteen copies of one form. --}}
    @php $editing = $this->editingRow(); @endphp

    <x-filament::modal id="integration" width="lg">

        @if ($editing)
            <x-slot name="heading">{{ $editing['label'] }}</x-slot>

            <x-slot name="description">
                {{ $editing['detail'] }}
            </x-slot>

            <div class="space-y-4">
                @if (count($editing['warnings']) > 0)
                    <div class="space-y-1 rounded-lg bg-warning-50 p-3 text-xs dark:bg-warning-400/10">
                        @foreach ($editing['warnings'] as $warning)
                            <p class="text-warning-700 dark:text-warning-400">{{ $warning }}</p>
                        @endforeach
                    </div>
                @endif

                @if (! empty($editing['webhook']))
                    {{-- A webhook is only a URL — the endpoint events POST to.
                         Discord/Slack give you one under channel settings; a
                         generic hook is whatever ntfy/Apprise/your own accepts. --}}
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="integration-url">
                            Webhook URL
                        </label>

                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="integration-url"
                                type="url"
                                wire:model="editingUrl"
                                autocomplete="off"
                                placeholder="https://…" />
                        </x-filament::input.wrapper>

                        <p class="mt-2 text-xs opacity-60">
                            @if ($editing['key'] === 'webhook_discord_url')
                                Discord: Channel &rarr; Edit &rarr; Integrations &rarr; Webhooks &rarr; New Webhook &rarr; Copy URL.
                            @elseif ($editing['key'] === 'webhook_slack_url')
                                Slack: an Incoming Webhook URL from your workspace's app settings.
                            @else
                                Any endpoint that accepts a JSON POST — ntfy, Apprise, Gotify, or your own.
                            @endif
                            Events post here as they happen.
                        </p>
                    </div>
                @else
                    @if ($editing['group'] === 'Acquisition' && ! $editing['connected'])
                        {{-- What this app is and how to start it, shown here
                             rather than as a banner across the top of the page.
                             It used to lead the page whenever nothing was
                             running, which is most installs: an optional,
                             not-installed, separate thing explaining itself
                             above the integrations somebody actually came to
                             configure. It belongs where they click into the app
                             that is not running. --}}
                        <div class="space-y-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                            <p>
                                {{ $editing['label'] }} is not running. Radarr, Sonarr and
                                Lidarr find new films, television and music; SoundChex
                                catalogues what is already here. They meet at the folder the
                                library scanner watches, so a finished download is picked up
                                on the next scan with nothing needing to tell SoundChex it
                                happened.
                            </p>

                            <p class="opacity-70">Optional, and separate from SoundChex. To start the bundled stack:</p>

                            <pre class="overflow-x-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">php artisan arr:setup --start</pre>

                            <p class="opacity-60">
                                Nothing downloads until an indexer is added inside each app
                                by hand. None ship with them and none are configured here.
                            </p>
                        </div>
                    @endif

                    @if ($editing['group'] === 'Acquisition')
                        {{-- The address, because loopback is only right for our own
                             compose stack. These apps are also installed natively,
                             on a Synology or unRAID box, inside someone else's
                             Docker stack, or on a different machine entirely — none
                             of which answer on 127.0.0.1 from here. --}}
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="integration-url">
                                Address
                            </label>

                            <x-filament::input.wrapper>
                                <x-filament::input
                                    id="integration-url"
                                    type="url"
                                    wire:model="editingUrl"
                                    autocomplete="off"
                                    placeholder="http://127.0.0.1:8686" />
                            </x-filament::input.wrapper>

                            <p class="mt-2 text-xs opacity-60">
                                Where {{ $editing['label'] }} is reachable from this
                                server. Another machine on the network works — use
                                its address rather than localhost.
                            </p>
                        </div>
                    @endif

                    @php
                        /* A source declares its own fields, so Spotify's two
                           credentials are two labelled inputs rather than one
                           box and a guess. Anything not from the catalogue --
                           an acquisition app, a toggle -- keeps the single
                           generic field it always had. */
                        $fields = $editing['keys'] ?? [$editing['key'] => 'API key'];
                        $models = ['editingKey', 'editingSecondKey'];
                    @endphp

                    @foreach (array_values($fields) as $i => $fieldLabel)
                        @if ($i < 2)
                            <div>
                                <label class="mb-1 block text-sm font-medium" for="integration-key-{{ $i }}">
                                    {{ count($fields) > 1 ? $fieldLabel : 'API key' }}
                                </label>

                                <x-filament::input.wrapper>
                                    <x-filament::input
                                        id="integration-key-{{ $i }}"
                                        type="password"
                                        wire:model="{{ $models[$i] }}"
                                        wire:keydown.enter="saveModal"
                                        autocomplete="off"
                                        :placeholder="$editing['connected'] ? 'Enter a new value to replace the stored one' : 'Paste it here'" />
                                </x-filament::input.wrapper>
                            </div>
                        @endif
                    @endforeach

                    <p class="text-xs opacity-60">
                        @if ($editing['group'] === 'Acquisition')
                            Found in {{ $editing['label'] }}'s own Settings &rarr; General.
                        @else
                            Issued by {{ $editing['label'] }}. Used only to enrich the catalogue.
                        @endif
                        Stored encrypted, and never shown again — replace it rather than edit it.
                        @if (count($fields) > 1)
                            Both are needed; one on its own does nothing.
                        @endif
                    </p>

                    @if ($this->canTestEditing())
                        {{-- Checked against the service before it is saved, so
                             a mistyped key never becomes a stored one that
                             looks fine and silently enriches nothing. --}}
                        @if ($this->testResult)
                            <div @class([
                                'rounded-lg p-3 text-xs',
                                'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' => $this->testResult['ok'],
                                'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => ! $this->testResult['ok'],
                            ])>
                                {{ $this->testResult['ok'] ? 'Key accepted' : 'Not accepted' }} — {{ $this->testResult['message'] }}
                            </div>
                        @endif
                    @endif
                @endif

                @if ($editing['connected_url'])
                    {{-- No inline handler. Livewire morphs this modal's DOM
                         and strips `onclick`, so every attempt that put the
                         behaviour in an attribute never ran at all — which is
                         why a dead click produced no error and no alert.

                         A marked-up link plus a delegated listener on
                         `document`, which Livewire cannot touch. --}}
                    <a href="{{ $editing['connected_url'] }}"
                       target="_blank"
                       rel="noopener noreferrer"
                       data-open-external="{{ $editing['connected_url'] }}"
                       class="fi-link fi-size-sm inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                        Open {{ $editing['label'] }} &rarr;
                    </a>
                @endif
            </div>

            <x-slot name="footerActions">
                @if (! empty($editing['webhook']))
                    <x-filament::button wire:click="saveModal">
                        Save
                    </x-filament::button>

                    <x-filament::button color="gray" wire:click="testWebhook">
                        Send test
                    </x-filament::button>
                @else
                    <x-filament::button wire:click="saveModal">
                        {{ $editing['connected'] ? 'Replace key' : 'Connect' }}
                    </x-filament::button>

                    @if ($this->canTestEditing())
                        <x-filament::button
                            color="gray"
                            wire:click="testCredential"
                            wire:loading.attr="disabled"
                            wire:target="testCredential">
                            <span wire:loading.remove wire:target="testCredential">Test key</span>
                            <span wire:loading wire:target="testCredential">Testing…</span>
                        </x-filament::button>
                    @endif
                @endif

                <x-filament::button color="gray" wire:click="closeModal">
                    Cancel
                </x-filament::button>
            </x-slot>
        @endif
    </x-filament::modal>

</x-filament-panels::page>
