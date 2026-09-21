<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Building SoundChex plugins

A plugin extends SoundChex — add a metadata source, react to what happens in the
library, transform a value as it passes through, contribute an admin screen. A
plugin is a folder with a manifest and some PHP; it installs from the admin UI or
by dropping it into the plugins directory, and it runs as part of the server.

> **A plugin is code that runs with the server's full access.** There is no
> sandbox — the same is true of Filament, Emby/Jellyfin and WordPress plugins.
> Install and enable only plugins you trust, from a source you recognise.

---

## Your first plugin in two minutes

Scaffold a working starter rather than start from a blank file:

```
php artisan plugin:make acme.hello --author="Your Name"
```

That writes a complete plugin into the plugins directory:

```
acme.hello/
  plugin.json            the manifest — identity, compatibility, what it provides
  src/Plugin.php         the entry class — getId / register / boot
  src/ExampleSource.php  a sample metadata source
```

Enable it under **Admin → System → Plugins**. It arrives *disabled* — enabling is
a deliberate act, because it runs the plugin's code. Once enabled, edit `src/` and
the changes take effect on the next request.

---

## The entry class

Every plugin has one class implementing `App\Plugins\Contracts\SoundChexPlugin`:

```php
use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;

class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'acme.hello';           // must match the manifest id
    }

    public function register(Registry $registry): void
    {
        // Wire your contributions here. Runs once at boot, for every request.
    }

    public function boot(Registry $registry): void
    {
        // Serving-time wiring only. Most plugins leave this empty.
    }
}
```

`register()` is where everything happens. It is handed the **`Registry`** — the
one object you push contributions onto. The constructor may type-hint any bound
service (it is resolved through the container), but the registry only exists from
`register()` onward.

---

## The manifest (`plugin.json`)

| Field | Required | What it is |
|---|---|---|
| `id` | yes | A stable slug, reverse-DNS style — `acme.hello`. Namespaces your plugin and keys the install. Never change it. |
| `name` | yes | The human name shown in the admin. |
| `version` | yes | SemVer — `1.2.0`. |
| `entrypoint` | yes | The fully-qualified entry class — `Acme\Hello\Plugin`. Its namespace maps to your `src/`. |
| `minSoundChexVersion` | no | The oldest server this build runs on. An older server refuses to load it (like Jellyfin's `targetAbi`). |
| `targetApi` | no | The plugin-API version you built against — `1.0.0`. This, not the app version, is what decides survival across releases: see [Surviving server versions](#surviving-server-versions). Scaffolded for you by `plugin:make`. |
| `requiresPhp` | no | The minimum PHP version. |
| `provides` | no | The seams you use — `["metadata-source", "notification"]`. Drives the catalogue and admin UI; not an enforced boundary. |
| `author` | no | Shown in the admin. |
| `description` | no | One line, shown in the admin and catalogue. |
| `configPage` | no | A Filament page/Livewire class your plugin ships as its settings screen. |
| `license` | no | SPDX id. SoundChex is AGPL-3.0-or-later; a plugin distributed with it should be compatible. |

The namespace is derived from the `entrypoint`: `Acme\Hello\Plugin` autoloads from
`acme.hello/src/Plugin.php` — everything under `Acme\Hello\` maps to `src/`.

---

## Extension points

Everything is registered on the `Registry` in `register()`.

### A metadata source

The most common plugin. Implement the same `MetadataSource` the built-in sources
use, and register it for a media type:

```php
$registry->metadataSource('music', DiscogsSource::class, priority: 40);
```

Lower priority runs earlier. Your source joins the pipeline alongside the built-in
ones and is indistinguishable from them. The interface:

```php
use App\Services\Metadata\Contracts\MetadataSource;

class DiscogsSource implements MetadataSource
{
    public function name(): string { return 'Discogs'; }
    public function priority(): int { return 40; }
    public function requiredSettings(): array { return ['discogs_token' => 'Discogs Token']; }

    public function supports(App\Models\MediaItem $item): bool
    {
        // Return false to skip (wrong type, or a required key not configured).
    }

    public function enrich(App\Models\MediaItem $item): void
    {
        // Fetch, then write into $item->musicMetadata / tags. Never overwrite a
        // field whose tag source is 'manual'.
    }
}
```

The worked example under `plugins/examples/year-tagger` is a complete, keyless
source — copy it.

### Events

React to something the app did. Subscribe by the event's stable name; the
listener receives the event object (its `public readonly` properties are the
payload):

```php
$registry->on('media.enriched', function ($event) {
    $item = $event->item;   // a freshly-enriched MediaItem
});
```

The full catalogue — every event a plugin may subscribe to:

| Event | Carries | Fired when |
|---|---|---|
| `cover.embedded` | item | A cover image was baked into an item's audio file |
| `cover.fetched` | item, coverUrl | A verified album cover was fetched for an item |
| `device.reported` | report | A device sent a diagnostic report (a crash, a failed navigation) — the hook a monitoring plugin needs |
| `device.signedIn` | deviceName, profileId | A device signed in — an access token was issued for a profile |
| `device.signedOut` | deviceName | A device signed out — its access token was revoked |
| `duplicate.detected` | item, original | A duplicate was detected — a byte-identical copy or the same recording in another file |
| `duplicate.merged` | item, original | A duplicate was merged into the copy that was kept |
| `duplicate.resolved` | item | A content-match duplicate was resolved by keeping one copy and deleting the other |
| `episode.added` | item | A new episode of a series already in the library was catalogued |
| `media.added` | item | A new item finished enriching and settled into the library — distinct from being catalogued, this fires once it is a real, kept item |
| `media.catalogued` | item | A file has just entered the library (S-264 Phase 3) |
| `media.deleted` | item | An item was removed from the library (row deleted) |
| `media.enriched` | item | Enrichment has finished for an item (S-264 Phase 3) |
| `media.reviewFlagged` | item, source | The metadata pipeline flagged an item as needing a human look — an ambiguous or missing match |
| `metadata.titleTidied` | item, was, now | An item's title was rewritten during enrichment (an artist stripped, a case change) |
| `notification.recorded` | notification | An in-app notification was recorded — the single choke point every notification (scan finished, episode added, and future ones) passes through |
| `playback.completed` | item, profileId | An item was watched or listened to the end (past the completion threshold) — the 'watched'/'scrobble' signal a tracker plugin reports |
| `playback.progress` | item, profileId, position | A resume position was saved for an item |
| `playback.recorded` | item, profileId | A play was recorded for an item (S-264 Phase 3) |
| `playlist.created` | playlist, profileId | A playlist was created |
| `playlist.deleted` | playlist, profileId | A playlist was deleted |
| `playlist.updated` | playlist, profileId | A playlist's details or tracks changed |
| `profile.created` | profile | A viewing profile was created |
| `profile.deleted` | profile | A viewing profile was deleted |
| `profile.switched` | profile | The active viewing profile changed |
| `scan.finished` | result | A library scan finished, carrying its counts (imported, duplicates, unsettled) |
| `scan.started` | folders | A library scan has begun |
| `server.extensionMissing` | missing | A required PHP extension is missing on the server — a config fault a monitoring plugin should surface |
| `server.healthChecked` | metrics, healthy | A scheduled server health check ran — disk, queue depth, failed jobs, scan age |
| `transcode.failed` | item, reason | A media conversion failed for an item |
| `transcode.finished` | item, convertedPath | A browser-playable converted copy was produced for an item (converted_path set) |
| `transcode.started` | item | A media conversion job has begun for an item |
| `transfer.completed` | transfer | A server-to-server library transfer finished |
| `transfer.failed` | transfer, reason | A server-to-server library transfer failed |
| `transfer.started` | transfer | A server-to-server library transfer began |
| `upload.completed` | count | A bulk upload finished queuing its files for cataloguing |
| `user.rated` | item, rating, profileId | A profile set or changed an item's rating |
| `user.searched` | query, resultCount, profileId | Someone ran a library search — the hook for analytics or a 'popular searches' plugin |
| `watchlist.added` | item, profileId | An item was added to a profile's watchlist — the hook an 'add to Radarr/Sonarr' automation wants |
| `watchlist.removed` | item, profileId | An item was removed from a profile's watchlist |

`Registry::builtInEvents()` returns this list at runtime.

#### Your own events

A plugin can define and fire its own events, so *other* plugins can react to it:

```php
// in your register():
$registry->defineEvent('acme.export.finished', 'An export finished');

// later, when it happens:
$registry->emit('acme.export.finished', ['file' => 'out.zip']);

// another plugin subscribes just like a built-in one:
$registry->on('acme.export.finished', fn ($payload) => /* ... */);
```

Namespace your event names (prefix with your plugin id) so they can't clash with
the app's or another plugin's. A defined event joins `availableEvents()` so other
authors can discover it. `emit()` with no subscribers is harmless.

### A cover source

Contribute a fallback album-cover provider, tried after the built-in
iTunes/Deezer lookups:

```php
$registry->coverSource(CoverArtArchiveSource::class, priority: 50);
```

Implement `App\Plugins\Contracts\CoverSource` — `name()`, `priority()`, and
`coverUrlFor(string $artist, ?string $album): ?string` returning a verified cover
URL or null. Validate the result against the artist; a wrong cover is worse than
none. The worked example under `plugins/examples/coverart-archive` reaches the
Cover Art Archive via MusicBrainz, keyless, in ~40 lines.

### A notification target

Deliver server notifications (scan finished, download failed, new episode)
somewhere the built-in Discord/Slack/generic webhooks do not reach:

```php
$registry->notificationTarget(TelegramTarget::class);
```

Implement `App\Plugins\Contracts\NotificationTarget` — `name()`,
`isConfigured()` (the cheap gate; false skips it), and `send(Notification)`
(swallow your own failures — a dead endpoint must not break the scan). The worked
example under `plugins/examples/telegram-notify` posts to a Telegram chat, reading
a bot token and chat id from settings.

### Filters

Transform a value passing through the app — the callback receives a value and
returns the (possibly changed) one:

```php
$registry->filter('metadata.title', function (string $title, $item) {
    return trim($title);
});
```

| Filter | Applied to | Context |
|---|---|---|
| `metadata.title` | a track's final title during enrichment | the `MediaItem` |

Filters chain in registration order, each seeing the previous result. A filter
that throws is logged and skipped — it cannot break the value for others.

---

## Distributing your plugin

Two ways a user installs it:

1. **Drop the folder in.** Zip your plugin (the folder containing `plugin.json`),
   the user unzips it into the plugins directory and presses **Re-scan**.
2. **A repository.** Host a `manifest.json` catalogue at a URL; the user adds the
   URL under **Plugins → Browse** and installs from there. Each catalogue plugin
   lists its versions, newest first:

```json
[{
  "id": "acme.hello",
  "name": "Hello",
  "description": "…",
  "author": "You",
  "versions": [{
    "version": "1.0.0",
    "sourceUrl": "https://…/acme-hello-1.0.0.zip",
    "targetAbi": "0.1.0",
    "checksum": "sha256:…",
    "changelog": "First release"
  }]
}]
```

- `targetAbi` gates on the server version — a build that needs a newer server is
  shown as incompatible rather than installed.
- `checksum` (`sha256:…` or a bare hash) is verified on download — a corrupted or
  tampered file is refused.

The install verifies the checksum, re-checks compatibility, and extracts safely
(an archive that tries to write outside its folder is refused). The plugin lands
**disabled**; the user reviews and enables it.

---

## Surviving server versions

A plugin you install should keep working when the app updates. It does — and the
rule for when it *stops* is deliberately narrow.

Compatibility is decided by the **plugin API version**, not the app version. The
app has its own SemVer (`0.2.0`, `0.3.0`, …); the plugin surface — the events,
the seams, the `Registry` — has a separate version, `plugin_api_version`, that
only moves when that surface changes. Your manifest records which one you built
against in `targetApi`.

The contract, in one line: **a plugin survives every server release that shares
its plugin-API major version.** So a plugin built against `1.x` keeps loading
across `1.0`, `1.4`, `1.9` — new events and seams are added under MINOR bumps,
which never break you. It is refused only when:

- **the app has moved to a new plugin-API major** (e.g. `2.0.0`) — a deliberate
  breaking overhaul of the plugin surface. This is the "unless a major overhaul"
  case, and the only one. You publish a new build targeting `2.x`.
- **the plugin targets a newer API than the server has** — you built against
  `1.4` and the user is still on a server that ships `1.1`. They update the
  server (or you lower `targetApi`).

A manifest with no `targetApi` is treated as compatible and still loads — the
field is how you opt into the guarantee and signal what you tested against, not
a gate that locks older plugins out. When a plugin *is* refused, the loader logs
why (major overhaul vs. server-too-old), so the reason is never a mystery.

Practically: set `targetApi` to whatever `plugin_api_version` was when you built
(`plugin:make` fills in the current one), and you inherit the full major line for
free. You only ever revisit it when the app announces a plugin-API major bump.

---

## Trust, honestly

SoundChex matches what every plugin ecosystem does, and no more:

- a curated official repository, and an explicit *at-your-own-risk* note on any
  third-party repository added;
- integrity checked on download (checksum) and compatibility gated before install;
- every plugin installed **disabled**, enabled only by a deliberate act;
- a master switch (`SOUNDCHEX_PLUGINS_ENABLED=false`) that stops all plugin code.

What it does **not** do is sandbox plugin code. A plugin runs in the server
process with full access. That is the same as Filament, Emby/Jellyfin and
WordPress — write plugins accordingly, and install only what you trust.
