<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins;

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
 * Phase 1 ships the two seams the platform can honour today: metadata sources
 * (the existing pipeline reads this in Phase 2) and named events. The later
 * seams — routes, cover/subtitle sources, admin pages — land as methods here in
 * Phase 3, so a plugin's `register()` never has to learn a new object.
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
     * Subscribe to a named app event.
     *
     * A thin pass-through to Laravel's event bus, offered here so a plugin has
     * one object for every kind of contribution rather than reaching for the
     * `Event` facade itself. The events a plugin can usefully listen to arrive
     * in Phase 3; the mechanism is here now.
     */
    public function on(string $event, callable $listener): static
    {
        Event::listen($event, $listener);

        return $this;
    }
}
