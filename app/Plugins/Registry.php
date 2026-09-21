<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

use App\Events\MediaItemCatalogued;
use App\Events\MediaItemEnriched;
use App\Events\PlaybackRecorded;
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
        MediaItemCatalogued::NAME => MediaItemCatalogued::class,
        MediaItemEnriched::NAME => MediaItemEnriched::class,
        PlaybackRecorded::NAME => PlaybackRecorded::class,
    ];

    /**
     * Subscribe to one of the app's named events (S-264 Phase 3).
     *
     * The listener receives the event object — e.g. a `MediaItemEnriched`
     * carrying the item. Offered on the registry so a plugin has one object for
     * every kind of contribution rather than reaching for the `Event` facade.
     */
    public function on(string $event, callable $listener): static
    {
        Event::listen(self::EVENTS[$event] ?? $event, $listener);

        return $this;
    }

    /**
     * The events a plugin may subscribe to, for docs and the admin UI.
     *
     * @return array<int, string>
     */
    public static function availableEvents(): array
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
