<x-media.layout :counts="$counts" title="Settings">

    {{-- clears-header, not a flat padding: the fixed header grows by the
         safe-area inset on a notched phone. --}}
    <section class="clears-header mx-auto max-w-2xl px-4 pb-16 sm:px-8">

        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Settings</h1>
        <p class="mt-1 text-sm text-ink-500">
            These apply to <strong class="text-ink-300">{{ $profile?->name ?? 'this profile' }}</strong>.
            Anyone else using this server keeps their own.
        </p>

        @if (session('settings_status'))
            <p class="settings-flash" data-tone="good">{{ session('settings_status') }}</p>
        @endif

        @if (session('settings_error'))
            <p class="settings-flash" data-tone="bad">{{ session('settings_error') }}</p>
        @endif

        <form method="POST" action="{{ route('media.settings.update') }}" class="mt-8 space-y-8">
            @csrf
            @method('PATCH')

            <section class="settings-group">
                <h2 class="settings-group__title">Playback</h2>

                <x-media.setting-toggle
                    name="autoplay_next"
                    label="Play the next track automatically"
                    hint="When a track ends, continue through the queue."
                    :checked="$preferences['autoplay_next']" />

                <x-media.setting-toggle
                    name="remember_position"
                    label="Remember where I stopped"
                    hint="Resume films and audiobooks where you left them."
                    :checked="$preferences['remember_position']" />

                <x-media.setting-toggle
                    name="prefer_downloaded"
                    label="Prefer downloaded copies"
                    hint="Play from this device when a download exists, rather than streaming."
                    :checked="$preferences['prefer_downloaded']" />

                <label class="settings-row">
                    <span class="settings-row__text">
                        <span class="settings-row__label">Crossfade</span>
                        <span class="settings-row__hint">Overlap the end of one track with the start of the next. Zero is off.</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <input type="number" name="crossfade_seconds" min="0" max="12"
                               value="{{ $preferences['crossfade_seconds'] }}"
                               class="settings-number">
                        <span class="text-xs text-ink-500">sec</span>
                    </span>
                </label>
            </section>

            <section class="settings-group">
                <h2 class="settings-group__title">Security</h2>

                {{-- Rendered only where it can work. The plugin is iOS and
                     Android only, and a toggle that does nothing on a desktop
                     browser is worse than no toggle. --}}
                <div data-biometric-row hidden>
                    <x-media.setting-toggle
                        name="biometric_unlock"
                        label="Unlock with Face ID"
                        hint="Ask for Face ID when opening the app on this device."
                        :checked="$preferences['biometric_unlock']" />

                    <p class="settings-note" data-biometric-note></p>
                </div>

                <p class="settings-note" data-biometric-absent>
                    Face ID is available in the SoundChex app on a phone. This browser
                    cannot offer it.
                </p>
            </section>

            <section class="settings-group">
                <h2 class="settings-group__title">Notifications</h2>

                <x-media.setting-toggle
                    name="notifications_enabled"
                    label="Allow notifications"
                    hint="Off until you turn it on. Your device will ask permission the first time."
                    :checked="$preferences['notifications_enabled']"
                    data-notification-master />

                <div data-notification-detail @class(['opacity-50' => ! $preferences['notifications_enabled']])>
                    <x-media.setting-toggle
                        name="notify_download_complete"
                        label="Download finished"
                        hint="When something you downloaded is ready to play offline."
                        :checked="$preferences['notify_download_complete']" />

                    <x-media.setting-toggle
                        name="notify_scan_complete"
                        label="Library scan finished"
                        hint="When the server finishes finding new media."
                        :checked="$preferences['notify_scan_complete']" />
                </div>

                <p class="settings-note" data-notification-note></p>
            </section>

            <section class="settings-group">
                <h2 class="settings-group__title">Quality of life</h2>

                <x-media.setting-toggle
                    name="confirm_download_removal"
                    label="Ask before removing a download"
                    hint="The download icon both downloads and removes, so a mis-tap can delete a file."
                    :checked="$preferences['confirm_download_removal']" />

                <x-media.setting-toggle
                    name="reduce_motion"
                    label="Reduce motion"
                    hint="Fewer animations and transitions."
                    :checked="$preferences['reduce_motion']" />
            </section>

            <button type="submit" class="settings-save">Save settings</button>
        </form>

        {{-- A PIN is a credential rather than a setting: kept apart so changing
             an unrelated toggle does not mean re-entering it. --}}
        <section class="settings-group mt-10">
            <h2 class="settings-group__title">Profile PIN</h2>

            <p class="settings-note">
                @if ($profile?->requiresPin())
                    This profile asks for a PIN before it can be used.
                @else
                    No PIN is set. Anyone choosing this profile can use it.
                @endif
            </p>

            @if (session('pin_status'))
                <p class="settings-flash" data-tone="good">{{ session('pin_status') }}</p>
            @endif

            @error('current_pin')
                <p class="settings-flash" data-tone="bad">{{ $message }}</p>
            @enderror

            @error('pin')
                <p class="settings-flash" data-tone="bad">{{ $message }}</p>
            @enderror

            <form method="POST" action="{{ route('media.settings.pin') }}" class="mt-4 space-y-3">
                @csrf
                @method('PATCH')

                @if ($profile?->requiresPin())
                    <label class="settings-field">
                        <span class="settings-row__label">Current PIN</span>
                        <input type="password" name="current_pin" inputmode="numeric" autocomplete="off"
                               class="settings-input">
                    </label>
                @endif

                <label class="settings-field">
                    <span class="settings-row__label">New PIN</span>
                    <input type="password" name="pin" inputmode="numeric" autocomplete="off"
                           class="settings-input" placeholder="4 to 8 digits">
                </label>

                <label class="settings-field">
                    <span class="settings-row__label">Confirm new PIN</span>
                    <input type="password" name="pin_confirmation" inputmode="numeric" autocomplete="off"
                           class="settings-input">
                </label>

                <p class="settings-note">Leave both blank to remove the PIN.</p>

                <button type="submit" class="settings-save">Update PIN</button>
            </form>
        </section>
    </section>
</x-media.layout>
