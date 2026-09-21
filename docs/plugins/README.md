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

React to something the server did:

```php
$registry->on('media.enriched', function ($event) {
    $item = $event->item;   // a freshly-enriched MediaItem
});
```

| Event | Fired when | Carries |
|---|---|---|
| `media.catalogued` | a file enters the library, before enrichment | `item` |
| `media.enriched` | the pipeline has finished an item | `item` |
| `playback.recorded` | a play is recorded | `item`, `profileId` |

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
