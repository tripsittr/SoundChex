<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

use App\Events\CoverEmbedded;
use App\Events\CoverFetched;
use App\Events\DeviceReported;
use App\Events\DeviceSignedIn;
use App\Events\DeviceSignedOut;
use App\Events\DuplicateDetected;
use App\Events\DuplicateMerged;
use App\Events\DuplicateResolved;
use App\Events\EpisodeAdded;
use App\Events\MediaItemAdded;
use App\Events\MediaItemCatalogued;
use App\Events\MediaItemDeleted;
use App\Events\MediaItemEnriched;
use App\Events\MediaItemReviewFlagged;
use App\Events\MetadataTitleTidied;
use App\Events\NotificationRecorded;
use App\Events\PlaybackCompleted;
use App\Events\PlaybackProgress;
use App\Events\PlaybackRecorded;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Events\ProfileCreated;
use App\Events\ProfileDeleted;
use App\Events\ProfileSwitched;
use App\Events\ScanFinished;
use App\Events\ScanStarted;
use App\Events\ServerExtensionMissing;
use App\Events\ServerHealthChecked;
use App\Events\TranscodeFailed;
use App\Events\TranscodeFinished;
use App\Events\TranscodeStarted;
use App\Events\TransferCompleted;
use App\Events\TransferFailed;
use App\Events\TransferStarted;
use App\Events\UploadCompleted;
use App\Events\UserRated;
use App\Events\UserSearched;
use App\Events\WatchlistAdded;
use App\Events\WatchlistRemoved;
use Illuminate\Support\Facades\Event;

/**
 * The surface a plugin pushes its contributions onto (S-264).
 *
 * Every plugin's `register()` is handed this object and calls methods on it to
 * add what it provides. The registry collects those contributions; the loader
 * and the rest of the app read them back. Keeping registration on one object
 * — rather than plugins reaching into the container directly — means every
 * contribution is attributed to a plugin id and can be dropped when that plugin
 * is disabled.
 *
 * The seams grow here, never on a new object, so a plugin's `register()` learns
 * one surface: metadata sources (Phase 2), named events and value filters
 * (Phase 3), and the routes, cover/subtitle sources and admin pages that follow.
 */
class Registry
{
    /**
     * Metadata-source classes contributed by plugins, per media type.
     *
     * @var array<string, array<int, array{plugin: string, class: string, priority: int}>>
     */
    private array $metadataSources = [];

    /**
     * The plugin whose registrations are currently being collected, so each
     * contribution is attributed without the plugin having to name itself.
     */
    private ?string $currentPlugin = null;

    /**
     * Runs a callback with contributions attributed to one plugin.
     *
     * The loader wraps each plugin's `register()`/`boot()` in this, so a plugin
     * simply calls `$registry->metadataSource(...)` and the registry records who
     * it was — the id a disable later filters on.
     */
    public function forPlugin(string $pluginId, callable $callback): void
    {
        $previous = $this->currentPlugin;
        $this->currentPlugin = $pluginId;

        try {
            $callback($this);
        } finally {
            $this->currentPlugin = $previous;
        }
    }

    /**
     * Contribute a metadata source for one media type.
     *
     * The class must implement the existing `MetadataSource` contract; it is
     * merged into that type's pipeline in priority order alongside the built-in
     * sources (Phase 2). A lower priority runs earlier, matching the pipeline.
     */
    public function metadataSource(string $type, string $sourceClass, int $priority = 100): static
    {
        $this->metadataSources[$type][] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'class' => $sourceClass,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * The plugin-contributed metadata sources for a media type, in the shape the
     * pipeline consumes.
     *
     * @return array<int, array{plugin: string, class: string, priority: int}>
     */
    public function metadataSourcesFor(string $type): array
    {
        return $this->metadataSources[$type] ?? [];
    }

    /**
     * The named events a plugin may subscribe to, mapped to their event classes.
     *
     * A plugin subscribes with the stable friendly name ("media.enriched") and
     * never has to know the class, so a class can be renamed without breaking a
     * plugin. A name not in this list is passed through as-is, so an author can
     * still listen to any Laravel event by its class if they want to.
     *
     * @var array<string, class-string>
     */
    private const EVENTS = [
        CoverEmbedded::NAME => CoverEmbedded::class,
        CoverFetched::NAME => CoverFetched::class,
        DeviceReported::NAME => DeviceReported::class,
        DeviceSignedIn::NAME => DeviceSignedIn::class,
        DeviceSignedOut::NAME => DeviceSignedOut::class,
        DuplicateDetected::NAME => DuplicateDetected::class,
        DuplicateMerged::NAME => DuplicateMerged::class,
        DuplicateResolved::NAME => DuplicateResolved::class,
        EpisodeAdded::NAME => EpisodeAdded::class,
        MediaItemAdded::NAME => MediaItemAdded::class,
        MediaItemCatalogued::NAME => MediaItemCatalogued::class,
        MediaItemDeleted::NAME => MediaItemDeleted::class,
        MediaItemEnriched::NAME => MediaItemEnriched::class,
        MediaItemReviewFlagged::NAME => MediaItemReviewFlagged::class,
        MetadataTitleTidied::NAME => MetadataTitleTidied::class,
        NotificationRecorded::NAME => NotificationRecorded::class,
        PlaybackCompleted::NAME => PlaybackCompleted::class,
        PlaybackProgress::NAME => PlaybackProgress::class,
        PlaybackRecorded::NAME => PlaybackRecorded::class,
        PlaylistCreated::NAME => PlaylistCreated::class,
        PlaylistDeleted::NAME => PlaylistDeleted::class,
        PlaylistUpdated::NAME => PlaylistUpdated::class,
        ProfileCreated::NAME => ProfileCreated::class,
        ProfileDeleted::NAME => ProfileDeleted::class,
        ProfileSwitched::NAME => ProfileSwitched::class,
        ScanFinished::NAME => ScanFinished::class,
        ScanStarted::NAME => ScanStarted::class,
        ServerExtensionMissing::NAME => ServerExtensionMissing::class,
        ServerHealthChecked::NAME => ServerHealthChecked::class,
        TranscodeFailed::NAME => TranscodeFailed::class,
        TranscodeFinished::NAME => TranscodeFinished::class,
        TranscodeStarted::NAME => TranscodeStarted::class,
        TransferCompleted::NAME => TransferCompleted::class,
        TransferFailed::NAME => TransferFailed::class,
        TransferStarted::NAME => TransferStarted::class,
        UploadCompleted::NAME => UploadCompleted::class,
        UserRated::NAME => UserRated::class,
        UserSearched::NAME => UserSearched::class,
        WatchlistAdded::NAME => WatchlistAdded::class,
        WatchlistRemoved::NAME => WatchlistRemoved::class,
    ];

    /**
     * Events a plugin has defined and emits itself, so other plugins can
     * discover and subscribe to them. Name => the defining plugin's id.
     *
     * @var array<string, string>
     */
    private array $pluginEvents = [];

    /**
     * Subscribe to a named event — one of the app's, or one another plugin
     * defined (S-264 Phase 3, extended S-276).
     *
     * The listener receives the event payload. For a built-in event that is the
     * event object (`MediaItemEnriched`, carrying the item); for a plugin-defined
     * event it is whatever the emitting plugin passed to `emit()`. A friendly
     * name maps to the app's event class; any other name — including a plugin's
     * custom `acme.thing.happened` — is used as-is, so plugins can listen to each
     * other without the app knowing the name ahead of time.
     */
    public function on(string $event, callable $listener): static
    {
        Event::listen(self::EVENTS[$event] ?? $event, $listener);

        return $this;
    }

    /**
     * Fire a plugin-defined event so any plugin (or the app) that subscribed to
     * it runs (S-276). This is how one plugin lets others react to something it
     * did — plugin A `emit()`s `acme.export.finished`, plugin B `on()`s it.
     *
     * The name should be namespaced to the plugin (reverse-DNS or the plugin id
     * as a prefix) to avoid clashing with the app's events or another plugin's.
     * Payload is passed straight through to every listener.
     */
    public function emit(string $event, mixed ...$payload): static
    {
        Event::dispatch($event, $payload);

        return $this;
    }

    /**
     * Declare a custom event this plugin emits, so it appears in the catalogue
     * other plugins and the docs read (S-276). Optional — a plugin can `emit()`
     * without declaring — but declaring makes the event discoverable rather than
     * something another author has to know about by reading source.
     */
    public function defineEvent(string $event, ?string $description = null): static
    {
        $this->pluginEvents[$event] = $this->currentPlugin ?? 'unknown';

        return $this;
    }

    /**
     * Every event a plugin may subscribe to — the app's built-in catalogue plus
     * any a plugin has defined. Drives the docs and the admin UI.
     *
     * @return array<int, string>
     */
    public function availableEvents(): array
    {
        return array_values(array_unique([
            ...array_keys(self::EVENTS),
            ...array_keys($this->pluginEvents),
        ]));
    }

    /**
     * The app's built-in events only, without instantiating the registry — for
     * static callers and the docs generator.
     *
     * @return array<int, string>
     */
    public static function builtInEvents(): array
    {
        return array_keys(self::EVENTS);
    }

    /**
     * Filter callbacks by named hook, in registration order.
     *
     * @var array<string, array<int, callable>>
     */
    private array $filters = [];

    /**
     * Register a filter — a callback that receives a value and returns a
     * (possibly changed) one (S-264 Phase 3).
     *
     * The WordPress-filter idea: the app calls `apply()` at a named point with a
     * value, and each registered filter gets to transform it in turn. Unlike an
     * event (which reacts), a filter *changes* the thing passing through — a
     * plugin can rewrite a title, add a genre, veto a value. The callback's
     * return replaces the value for the next filter and, finally, the caller.
     */
    public function filter(string $hook, callable $callback): static
    {
        $this->filters[$hook][] = $callback;

        return $this;
    }

    /**
     * Runs a value through every filter registered for a hook, in order, and
     * returns the result. With no filters the value is returned untouched, so a
     * caller can always apply a hook without checking whether anything listens.
     *
     * A filter that throws is logged and skipped — one plugin's bad filter must
     * not break the value for everyone downstream.
     *
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public function apply(string $hook, mixed $value, mixed ...$context): mixed
    {
        foreach ($this->filters[$hook] ?? [] as $callback) {
            try {
                $value = $callback($value, ...$context);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $value;
    }

    /** Whether any filter is registered for a hook — lets a caller skip the work. */
    public function hasFilters(string $hook): bool
    {
        return ! empty($this->filters[$hook]);
    }

    /**
     * Cover-source classes contributed by plugins.
     *
     * @var array<int, array{plugin: string, class: string, priority: int}>
     */
    private array $coverSources = [];

    /**
     * Contribute a cover-art source (S-264, #280).
     *
     * The class must implement `CoverSource`. It is tried by CoverArtFetcher as a
     * fallback after the built-in iTunes/Deezer lookups, in priority order among
     * the plugin sources — so a plugin can reach an artwork provider the core
     * does not, without disturbing the built-in path.
     */
    public function coverSource(string $sourceClass, int $priority = 100): static
    {
        $this->coverSources[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'class' => $sourceClass,
            'priority' => $priority,
        ];

        return $this;
    }

    /**
     * The plugin cover-source classes, in the order they should be tried.
     *
     * @return array<int, string>
     */
    public function coverSourceClasses(): array
    {
        $sorted = $this->coverSources;
        usort($sorted, fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_column($sorted, 'class');
    }

    /**
     * Notification-target classes contributed by plugins.
     *
     * @var array<int, array{plugin: string, class: string}>
     */
    private array $notificationTargets = [];

    /**
     * Contribute a notification target (S-264, #281).
     *
     * The class must implement `NotificationTarget`. Every recorded notification
     * is offered to it alongside the built-in webhook destinations, so a plugin
     * can deliver to somewhere the core does not — Telegram, ntfy, Pushover.
     */
    public function notificationTarget(string $targetClass): static
    {
        $this->notificationTargets[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'class' => $targetClass,
        ];

        return $this;
    }

    /**
     * The plugin notification-target classes.
     *
     * @return array<int, string>
     */
    public function notificationTargetClasses(): array
    {
        return array_column($this->notificationTargets, 'class');
    }
}
