# 198 — The library on the LAN, over DLNA

**Merged** 2026-09-24 · **Issues** S-7

Devices with no SoundChex app — older smart TVs, a PS5, a network receiver —
can now browse and play the library natively. They find it themselves; there
is nothing to install on them.

## What changed

`php artisan dlna:serve` answers SSDP searches on the LAN, and four routes
under `/dlna` serve the device description, the SOAP ContentDirectory, and the
bytes.

Discovery is its own process because it holds a UDP socket open on port 1900,
which PHP-FPM cannot do. It is supervised alongside the queue worker and
scheduler on macOS, Linux and Windows.

### Off by default, and one profile stands for the network

DLNA has no authentication — a television cannot log in — so two things stand
in for it:

- **It advertises nothing until switched on** in Network settings. Off answers
  **404**, not 403: a server not offering DLNA should look like it has none,
  not like it has one that refused.
- **When on, it serves one chosen profile's view.** A TV cannot say who is
  watching, so the profile *is* the access control. Point it at a
  rating-capped profile and the cap holds on the living-room TV. Switching it
  on without choosing a profile is refused rather than defaulted — quietly
  picking one would be picking who can see what.

The gating goes through `ContentGate`, which gains `applyFor()` to take a
profile explicitly. A second copy of the rating logic is a cap that silently
stops matching the first.

### Local callers only

SSDP is multicast and never leaves the LAN — but the HTTP endpoints it points
at are ordinary routes on the same server that answers the public Funnel.
Without a check, anyone reaching the public address could browse the library
unauthenticated. Requests from outside the private ranges get a 404.

Tailscale addresses (100.64/10) are refused too. That is deliberate: this is a
LAN feature, and the apps have a real API with real auth.

## Worth knowing

- **Nothing is exposed by installing this.** The service runs, the setting is
  off, and it answers nothing.
- There is no per-device picker, unlike AirPlay. DLNA either advertises to the
  whole network or it does not.
- No play is recorded for DLNA playback: a TV is not a profile, and counting
  it against a household member would put listens in the wrong place.
- `phpunit.xml` now sets `memory_limit`. The full suite died part-way with a
  memory exhaustion inside symfony/mime rather than a test failure —
  pre-existing, reproduced on a clean tree (S-382).

## Still wrong

**Unverified against real hardware.** Every layer is tested and the message
formats follow the spec, but no television, console or receiver has actually
discovered this server yet. Protocol code that has never met a real device
should be treated as a first draft.

Only Browse is implemented, not Search. Clients fall back to browsing when
Search is absent, and answering it badly would be worse than not offering it.

The browse tree is deliberately shallow — Music / Movies / TV Shows, then
items. No artists, albums or genres yet: the devices this exists for have slow
browsers and worse remotes.

## Tests

PHP · 39 new across four files — the catalogue and its gating (7), the
DIDL-Lite writer (8), the HTTP endpoints and their gates (10), SSDP message
format (6), and the settings page (3). Verified the gating tests fail when the
gate is removed.

Related suites 62/62.
