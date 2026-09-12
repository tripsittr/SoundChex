# 039 — Integrations

**Merged** 2026-09-12 · **Issues** S-138

One page that answers "what is this install connected to?", and optional
support for Radarr, Sonarr and Lidarr.

## What changed

### An Integrations page

Under **System → Integrations**. Every outside service this install can talk to
is one row: its name, what it is for, whether it is connected, and a button.

- Not connected → **Set up**
- Connected → **Manage** and **Unlink**

Both buttons open the same modal, because the question is identical in every
case: paste a key. Fifteen integrations, one form.

The answer used to be spread across a settings page, an `.env` file and a
container that may or may not have been running, with nothing saying which.

**Metadata sources are listed here but still configured on their own page.**
That is deliberate rather than unfinished. They are read-only API keys, and
enriching the catalogue *is* library administration — a profile trusted with
the library should keep them. This page is gated to server administration,
because acquisition reaches the network, spends disk and decides what arrives
on the machine. Merging the two would mean either locking metadata keys away
from the people who should have them, or opening acquisition to people who
should not.

### Radarr, Sonarr and Lidarr, if you want them

A Docker Compose stack in `docker/arr/`, with qBittorrent as the download
client. They find new media; SoundChex catalogues what exists. They meet at the
folder the library scanner already watches, so a finished download is picked up
on the next scan with nothing needing to tell SoundChex it happened.

`php artisan arr:setup --start` writes the environment file, makes the folders
and brings the stack up. Every path and the host UID/GID are derived from what
the app already knows, so there is nothing to hand-edit — getting those wrong
produces root-owned files the scanner cannot read, which looks like a failed
download rather than a permissions problem.

**The apps are not bundled, and should not be.** They are GPLv3, so shipping
them inside a SoundChex installer would make us a redistributor with the
obligations that carries; App Store review would refuse a bundled torrent
client outright; and it is ~400MB most installs will never use. This is the
same pattern AGENTS.md already mandates for `ffmpeg` and `tesseract` — detect,
offer, name the command, never ship.

## Worth knowing

- **Nothing downloads.** No indexers ship with these images and none are
  configured here. The stack is inert until someone adds one by hand inside
  each app. That is a deliberate line, not an unfinished step.
- **Web UIs bind to `127.0.0.1` only.** These have no authentication until it
  is set up inside each app, and this machine is reachable over Tailscale — a
  default bind would put four unauthenticated admin panels on the tailnet. Only
  qBittorrent's protocol port is open, because a torrent client that cannot
  accept incoming connections runs at a fraction of its speed.
- **Readarr is excluded.** Retired by its maintainers in 2025, and its metadata
  backend has been unreliable since. Books are enriched from Open Library,
  which is maintained and already works.
- **Keys are stored encrypted**, and the modal never pre-fills a stored one.
  They are encrypted precisely so the panel cannot read them back; decrypting
  one onto a screen to populate a form would undo that for a field nobody edits
  in place. Replace a key, don't amend it.
- **Unlink only forgets the key.** Nothing is uninstalled and no container is
  stopped.
- `storage/app/arr` and `storage/app/arr-downloads` are gitignored, as is
  `docker/arr/.env`.

## Fixed after first review

**The Set up buttons did nothing.** The modal was bound with `:visible` on a
Livewire property, which renders the markup with a hidden class and never runs
the Alpine handler that shows it — Filament's modal opens on an `open-modal`
browser event. It now dispatches that event.

Worth recording *how* it shipped: twelve tests passed. Every one asserted what
`edit()` put in the component's state, and none asserted that anything
appeared. A test that checks the server did its half is not a test that the
feature works.

**The metadata list was fifteen unsorted rows.** Now grouped by what each
provider is *for* — Film & TV, Music, Lyrics, Books, Artwork — in a declared
order, with connected providers first within each group. A configured provider
is the one with something to say, and burying it under eight unconfigured ones
made the page look emptier than it was.

**Lidarr reported itself as not running while it was running.** Its API is
`v1`; Radarr and Sonarr moved to `v3` and Lidarr never did. Every call 404'd,
and a 404 is indistinguishable from nothing listening on the port — so a
perfectly healthy Lidarr showed as stopped. The version is now a property of
each app rather than a hardcoded assumption.

This is precisely the failure the "unverified against real containers" note
below predicted, and it survived 19 passing tests because the faked HTTP matched
`*/api/v3/*` — asserting against the assumption rather than against the API. The
new test asserts the URL actually requested, and fails against the version that
shipped.

## Still wrong

- The page reads; it does not write. You cannot add a film to Radarr from a
  SoundChex search result — that was considered and left out, because it means
  four API clients to keep working against apps that version independently.
- Health is polled on render with a 15-second cache. There is no push, so a
  queue that empties between renders still reads as full for up to 15 seconds.
- ~~Unverified against real running containers.~~ **Now verified.** All four
  containers run; Lidarr 2.5.3 reports its version, queue and health warnings
  through the page. Radarr and Sonarr are up but have no key entered yet, so
  their probes are still only exercised by faked HTTP.

## Tests

**PHP 553** (21 new), covering the gate, the not-running case, a missing key
told apart from a stopped app, health warnings, the set-up/unlink round trip,
that keys are stored encrypted, that the modal never shows a stored key, and
that acquisition keys are namespaced away from metadata keys so unlink cannot
clear the wrong row.

Three of those are new after the modal bug, and assert that opening, closing and
saving dispatch the browser events the modal actually listens for.

**One ordering test was written twice before it meant anything.** Comparing
rendered order to declared order passes whether the sort runs or not, because
the sources happen to be declared in the same sequence as `GROUP_ORDER` — it
passed with the ordering reverted, twice. The assertion that has teeth is that a
group missing from `GROUP_ORDER` is dropped rather than silently appended, which
is the one behaviour build order cannot imitate.
