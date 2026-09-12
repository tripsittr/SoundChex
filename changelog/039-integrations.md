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

## Still wrong

- The page reads; it does not write. You cannot add a film to Radarr from a
  SoundChex search result — that was considered and left out, because it means
  four API clients to keep working against apps that version independently.
- Health is polled on render with a 15-second cache. There is no push, so a
  queue that empties between renders still reads as full for up to 15 seconds.
- **Unverified against real running containers.** The stack validates, and the
  service is covered by faked HTTP, but no image has been pulled on this
  machine — so version parsing and health-warning shapes are tested against
  what the API documents rather than against what it returns.

## Tests

**PHP 544** (12 new), covering the gate, the not-running case, a missing key
told apart from a stopped app, health warnings, the set-up/unlink round trip,
that keys are stored encrypted, that the modal never shows a stored key, and
that acquisition keys are namespaced away from metadata keys so unlink cannot
clear the wrong row.
