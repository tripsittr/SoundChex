# 040 — Stale paths, a leaky endpoint, and Windows certificates

**Merged** 2026-09-12 · **Issues** S-137, S-11, S-75, S-45

Three fixes that were overdue, and a data repair. Two touch security; one
fixed eleven books that could not be opened at all.

## What changed

### 1,303 items pointed at a folder that no longer exists (S-137)

When the repository moved, `media_items.file_path` kept the old absolute
prefix — `/Users/.../Documents/GitHub/SoundChex/...` — for 1,303 rows. The
reader 404s on a missing file, so **11 of 14 books could not be opened**, and
music pages pointed at nothing.

The files themselves were never gone. Only the stored paths were stale. So
this is a database-only repair, done against the live library after a
`.backup`, and dry-run first per the working agreement:

- 1,297 rows rewrote cleanly, 6 resolved to files that did not exist, 0
  collisions.
- All 1,303 were rewritten to **relative** paths, matching the other 7,032
  rows. `absoluteFilePath()` resolves those against the storage disk, so this
  is the shape the code already prefers — and it immunises them against the
  next move, which is the exact failure class as S-112 and S-127.
- The 6 that resolved to nothing turned out to be duplicate rows of files that
  had been re-filed (the S-45 family): 10 in total, every one with zero plays
  and no reading progress. The rows were deleted; no file was touched.

After: 8,328 items, zero absolute paths, and only two genuinely-lost files
left (a Royal Blood video and an Aphex Twin set with no copy on disk). All 11
books open, and music item pages serve their stream.

### `soundchex-addresses.json` stopped telling the world where you live (S-11)

This endpoint listed **every** address the server answers on — LAN IP, tailnet
hostname, public relay — to anyone, with `Access-Control-Allow-Origin: *`.
Reaching one of those routes does not entitle a caller to a map of the others:
someone on the public relay had no business learning the LAN address.

It is now behind `auth`, with the CORS header removed. Its only caller is
`failover.js`, a same-origin fetch from pages already behind auth, and its
`!response.ok` branch already falls back to the stored address list — so a
signed-out request degrades rather than breaks.

`/soundchex.json` stays public and minimal by design: it says only "this is a
SoundChex server, on build X", which discloses nothing about the household. A
test now guards that its payload never quietly grows past `{app, version,
build}`.

### Windows no longer fails every HTTPS request (S-75)

Windows PHP ships without a CA bundle, so transfers, TMDB, MusicBrainz,
artwork and subtitles all failed with `cURL error 60` until someone edited
`php.ini` by hand. Installed `composer/ca-bundle` and pointed every outbound
request at it in `AppServiceProvider::boot()`:

```php
Http::globalOptions([
    'verify' => CaBundle::getSystemCaRootBundlePath(),
]);
```

It finds the system bundle where one exists — a no-op on macOS and Linux — and
falls back to the pem the package ships on Windows. Verified: outbound HTTPS to
TMDB returns 204 through the global verify path.

## Worth knowing

- **The S-137 repair has run on this machine only**, against the live library,
  with a `.backup` taken first. Any other deployment with the old prefix needs
  the same rewrite.
- **Two reading-progress / catalogue files are genuinely lost** (ids 3410,
  3887). Their rows remain; the files are not on disk under any path.
- The transfer error message that used to blame `php.ini` now describes a
  genuinely bad certificate, clock skew, or TLS interception — because a
  missing bundle is no longer a possible cause.

## Still wrong

- The two lost files are not recoverable from here; they would need to be
  re-imported from the source.
- The CA-bundle fix is verified on macOS. It is the documented remedy for
  Windows but has **not** been run on a Windows machine this session.

## Tests

**PHP 559** (new: `DiscoveryEndpointsTest`, 4; updated `TransferErrorMessagesTest`).
