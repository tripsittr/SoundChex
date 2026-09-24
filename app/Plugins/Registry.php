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
use Illuminate\Support\Facades\Log;

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
     * discover and subscribe to them. Name => the defining plugin and the
     * description it gave.
     *
     * @var array<string, array{plugin: string, description: ?string}>
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
     *
     * The description is kept, not just accepted: it is the whole reason an
     * author declares rather than only emitting, and `definedEvents()` is what
     * surfaces it to the docs and the admin UI.
     */
    public function defineEvent(string $event, ?string $description = null): static
    {
        $this->pluginEvents[$event] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'description' => $description,
        ];

        return $this;
    }

    /**
     * The plugin-defined events, with who defined each and what they said it is
     * — so the docs and the admin UI can list a plugin's own events the way they
     * list the built-in catalogue, description included.
     *
     * @return array<string, array{plugin: string, description: ?string}>
     */
    public function definedEvents(): array
    {
        return $this->pluginEvents;
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

    /**
     * Filament page classes contributed by plugins.
     *
     * @var array<int, array{plugin: string, class: string}>
     */
    private array $adminPages = [];

    /**
     * Contribute a page to the Filament admin panel (S-264, #284).
     *
     * The class is an ordinary `Filament\Pages\Page` living in the plugin's own
     * namespace; this makes the panel aware of it, since Filament only discovers
     * pages under `app/Filament`. The page appears in the nav like any core one,
     * subject to its own `canAccess()` — so a plugin page can gate itself to
     * server admins exactly as the built-in System pages do.
     *
     * This is the seam that lets a plugin add a whole screen, not just a settings
     * form: the audit-log plugin is its first user.
     */
    public function adminPage(string $pageClass): static
    {
        $this->adminPages[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'class' => $pageClass,
        ];

        return $this;
    }

    /**
     * The plugin-contributed Filament page classes, de-duplicated so a page
     * registered twice (a plugin re-registering) appears once in the nav.
     *
     * @return array<int, string>
     */
    public function adminPageClasses(): array
    {
        return array_values(array_unique(array_column($this->adminPages, 'class')));
    }

    // MARK: - Self-contained seams: routes, migrations, bindings (S-314)

    /**
     * Route files contributed by plugins, loaded only while the plugin is on.
     *
     * @var array<int, array{plugin: string, path: string, prefix: ?string, middleware: array<int, string>}>
     */
    private array $routeFiles = [];

    /**
     * Migration directories contributed by plugins.
     *
     * @var array<int, array{plugin: string, path: string}>
     */
    private array $migrationPaths = [];

    /**
     * Slot callbacks contributed by plugins, per slot name, in registration
     * order.
     *
     * @var array<string, array<int, array{plugin: string, callback: callable}>>
     */
    private array $slots = [];

    /**
     * Render into a named slot in the user-facing player (S-318).
     *
     * The admin panel gets its positions from Filament (`renderHook()`); the
     * media center has no such system, so the slots are placed deliberately in
     * its Blade templates with `@pluginSlot('name')`. That is the point: each
     * one is a decision about where a plugin may write, not an accident of
     * whatever markup happened to be wrapped.
     *
     * The callback returns a string of HTML or a rendered view, and runs on
     * every render of the template holding the slot — so keep it cheap.
     *
     * Slots available today:
     *
     *   `player.controls`     beside the transport in the now-playing bar
     *   `player.meta`         under the title and artist in that bar
     *   `album.detail`        below an album's track list
     *   `artist.detail`       below an artist's albums
     *   `song.row.actions`    at the end of a track row
     *
     * A plugin's markup cannot use the app's Tailwind classes — the app's CSS
     * is compiled before an installed plugin exists. Ship CSS with the plugin,
     * or use the tokens exposed as CSS custom properties. See AGENTS.md.
     *
     * @param  callable(mixed ...$context): string  $callback
     */
    public function slot(string $slot, callable $callback): static
    {
        $this->slots[$slot][] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * The markup every plugin has contributed to one slot, concatenated in
     * registration order.
     *
     * A plugin that throws contributes nothing there and is logged, rather
     * than taking the page down — the same posture as every other seam.
     */
    public function renderSlot(string $slot, mixed ...$context): string
    {
        $html = '';

        foreach ($this->slots[$slot] ?? [] as $entry) {
            try {
                $html .= (string) ($entry['callback'])(...$context);
            } catch (\Throwable $e) {
                Log::warning('plugin slot failed', [
                    'plugin' => $entry['plugin'],
                    'slot' => $slot,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $html;
    }

    /** Whether anything is registered for a slot — so a wrapper can be skipped. */
    public function hasSlot(string $slot): bool
    {
        return ! empty($this->slots[$slot]);
    }

    /**
     * Admin-panel render hooks contributed by plugins, in registration order.
     *
     * @var array<int, array{plugin: string, hook: string, callback: callable}>
     */
    private array $renderHooks = [];

    /**
     * Render something into the admin panel at a named point (S-316).
     *
     * Filament exposes fixed positions in its chrome — above a page's header,
     * at the end of the sidebar, before a table's rows — and this is the seam
     * that lets a plugin put its own markup there without the panel knowing
     * the plugin exists.
     *
     * The callback returns a string of HTML, or a rendered view. It is called
     * on every request that reaches the hook, so it must be cheap: a database
     * query here runs on every page load in the panel.
     *
     * Hook names come from `Filament\View\PanelsRenderHook`, e.g.
     * `PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE`. Passing the constant
     * rather than a bare string is what keeps a plugin working across a
     * Filament upgrade that renames one.
     *
     * A plugin's markup cannot rely on the app's Tailwind — the app's CSS is
     * compiled before release and an installed plugin's markup did not exist
     * then. Ship CSS with the plugin, or use the classes Filament's own build
     * provides. See `StyleCompiler` (S-350) and AGENTS.md.
     *
     * @param  callable(): string  $callback
     */
    public function renderHook(string $hook, callable $callback): static
    {
        $this->renderHooks[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'hook' => $hook,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * Every render hook a plugin has registered, in registration order.
     *
     * @return array<int, array{plugin: string, hook: string, callback: callable}>
     */
    public function renderHooks(): array
    {
        return $this->renderHooks;
    }

    /**
     * Dashboard widget classes contributed by plugins.
     *
     * @var array<int, array{plugin: string, class: class-string}>
     */
    private array $widgets = [];

    /**
     * Add a dashboard widget to the admin panel (S-316).
     *
     * The class is an ordinary Filament widget. Registered only while the
     * plugin is enabled, so disabling one takes its widget off the dashboard
     * rather than leaving a broken tile behind.
     *
     * @param  class-string  $widgetClass
     */
    public function widget(string $widgetClass): static
    {
        $this->widgets[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'class' => $widgetClass,
        ];

        return $this;
    }

    /**
     * The widget classes contributed by enabled plugins.
     *
     * @return array<int, class-string>
     */
    public function widgetClasses(): array
    {
        return array_column($this->widgets, 'class');
    }

    /**
     * Contribute a routes file so a plugin can own its own endpoints (S-314).
     *
     * The file is an ordinary Laravel routes file (`Route::get(...)`), loaded
     * only while the plugin is enabled — so disabling the plugin removes its
     * endpoints entirely (they 404), which is what makes a plugin a self-contained
     * vertical rather than a UI over always-present core routes.
     *
     * `prefix` and `middleware` wrap the file's routes, so a plugin's API can sit
     * under the same guard the core API uses without repeating it on every route.
     *
     * IMPORTANT for an API: include the `api` middleware group, not just
     * `auth:sanctum`. The `api` group carries `SubstituteBindings`, which resolves
     * route-model parameters (`{import}`, `{item}`); without it every bound route
     * returns a bare 404 even though the URL matched. So an authed API plugin
     * registers `middleware: ['api', 'auth:sanctum']`.
     *
     * @param  array<int, string>  $middleware
     */
    public function routes(string $path, ?string $prefix = null, array $middleware = []): static
    {
        $this->routeFiles[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'path' => $path,
            'prefix' => $prefix,
            'middleware' => $middleware,
        ];

        return $this;
    }

    /**
     * The route files contributed by enabled plugins.
     *
     * @return array<int, array{plugin: string, path: string, prefix: ?string, middleware: array<int, string>}>
     */
    public function routeFiles(): array
    {
        return $this->routeFiles;
    }

    /**
     * Contribute a directory of migrations so a plugin can ship its own schema
     * (S-314). Registered with Laravel's migrator, so `migrate` runs them with
     * the core migrations and a plugin can own its tables.
     */
    public function migrations(string $path): static
    {
        $this->migrationPaths[] = [
            'plugin' => $this->currentPlugin ?? 'unknown',
            'path' => $path,
        ];

        return $this;
    }

    /**
     * The migration directories contributed by plugins, de-duplicated.
     *
     * @return array<int, string>
     */
    public function migrationPaths(): array
    {
        return array_values(array_unique(array_column($this->migrationPaths, 'path')));
    }

    /**
     * Bind a service into the container for a plugin (S-314).
     *
     * A plugin's own services (a parser, a matcher, a source) are bound here
     * rather than the plugin reaching into the container directly, so the binding
     * is attributed and applied as part of loading an enabled plugin. `shared`
     * makes it a singleton. The concrete may be a class name or a factory closure.
     */
    public function binding(string $abstract, string|\Closure $concrete, bool $shared = false): static
    {
        if ($shared) {
            app()->singleton($abstract, $concrete);
        } else {
            app()->bind($abstract, $concrete);
        }

        return $this;
    }

    /** A shared (singleton) binding — the common case for a plugin's services. */
    public function singleton(string $abstract, string|\Closure $concrete): static
    {
        return $this->binding($abstract, $concrete, shared: true);
    }
}
