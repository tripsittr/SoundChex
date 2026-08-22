# Issues

Everything reported, and where it got to. One line per thing, newest first
within each section.

**How this works.** Report something in any form — a sentence is enough — and
it gets an entry here before anything else happens. Entries move down the file
as they resolve: **Open** → **In progress** → **Done** or **Deferred**. Nothing
is deleted, because "we looked at this and decided not to" is worth as much as
a fix and is otherwise rediscovered every few months.

**IDs** are `S-nn` and never reused. Reference one and I will know exactly what
you mean.

**Verified** means it was checked against the real library or a real device,
not that a test passed. Both are noted where they differ.

---

## In progress

| ID | What | Notes |
| --- | --- | --- |
| S-01 | Artist profiles: images, bios, discographies | Plan written: `ArtistProfiles.md` steps 1–4 done, step 5 (the profile page itself) not started |

## Open

| ID | What | Notes |
| --- | --- | --- |
| S-02 | Reader is slow to open and to turn pages | Not diagnosed. Page content, OCR text and images all stream per page; the mirror does not cover the reader at all |
| S-03 | Artist, album and item pages are not in the device mirror | Six list screens are; these three are pure server round-trips, ~900ms each over the relay |
| S-04 | `phone-dl.spec.js` "a stored track stays marked through a pre-render" | Passes alone and with all four download specs; fails in the full 231-test run on both projects. Not explained |
| S-05 | iPhone was not on the tailnet | Tailscale now installed. Confirm the app actually settles on `100.106.62.120:8000` rather than the Funnel |
| S-06 | Native downloads: IndexedDB caps the library at ~1 GB | Plan written: `NativeDownloads.md`. Music alone is 7.71 GB |
| S-07 | Background downloads and audio stop when the app is backgrounded | Plan written: `NativeOfflineBridge.md`. Unblocked, since the offline failures turned out to be application logic |
| S-08 | HLS adaptive streaming | Largest remaining item. No plan written |
| S-09 | Direct route in from outside the house | Plan written: `DirectRemoteAccess.md`. Needs router and DNS work that is not code |
| S-10 | `/login` has no rate limiting | Theoretical today; load-bearing the moment a port is forwarded. Blocks S-09 |
| S-11 | `soundchex.json` and `soundchex-addresses.json` are unauthenticated | Deliberate on a tailnet, an information leak on the open internet. They name every address the server answers on. Blocks S-09 |
| S-12 | AcoustID, Spotify and OpenSubtitles have no API key | Sources skip themselves silently. Now logged, not yet fixed |
| S-13 | Three songs match no metadata provider | Items 1445, 2002, 2473. Complete with `match_confidence = none`; will never gain metadata without a manual match |
| S-14 | Windows and Linux client builds are untested | The config approach carries over unchanged, but neither has been built |
| S-15 | Tailnet key expires 2027-02-09 | The Mac drops off the tailnet that day. Disable key expiry in the Tailscale admin console |
| S-16 | Test suite is slow | `workers: 1`, one SQLite database and one seeded library. The fixable part is the remaining hardcoded `waitForTimeout` calls |
| S-17 | Device and session tracking | "What devices are online, using the server, and what they are doing." `device_reports` is a starting point |
| S-18 | APNs push notifications | Paid account makes it possible; `notifications.js` already sends what push would carry |

## Done

| ID | What | Fixed | Verified |
| --- | --- | --- | --- |
| S-19 | Long press highlights text instead of opening the menu; links show "Open in Safari" | `1ad8cf7` | Built CSS asserted; needs a device check |
| S-20 | Cannot change the server address — it connects before you can | `030ca5c` | 24 shell tests |
| S-21 | Batch download loses a track to one dropped packet | `e5dcc70` | 5 tests, retry verified to fail without the fix |
| S-22 | Remove button on downloads does nothing | `818c62c` | 10 tests, desktop and mobile |
| S-23 | No way to remove all downloads | `818c62c` | Confirmation names the count and the space |
| S-24 | iOS closes IndexedDB and every later transaction fails | `566ae5b` | Reported by the phone: 1 UnknownError, then 27 failures |
| S-25 | App picks the 843ms relay over a 92ms direct route | `dcd267f` | Vitest ranking suite, fails without the fix |
| S-26 | Artists page lists "$uicideboy$, Bones" as its own artist | `84a1500` | 1,034 → 883 artists; `$uicideboy$` 342 → 446 tracks |
| S-27 | Songs not grouped under a primary artist | `9e574a0` | 4,080 tracks credited, 419 collaborations |
| S-28 | An artist's collaborations are missing from their page | `84a1500` | "Appears on" reads the credits, not the string |
| S-29 | Enrichment silently skips a source with no API key | `31d2fa5` | Names AcoustId and Spotify against the real library |
| S-30 | Scan knocks over its own enrichment jobs | `4e02ca8` | 12 dropped jobs retried, all 12 ran |
| S-31 | now-playing sheet flake, ~1 run in 20 | `75c2d20` | 70 consecutive passes |
| S-32 | Converted film unplayable after a catalogue rebuild | `603427a` | Backrooms plays; original archived, not deleted |

## Deferred

| ID | What | Why |
| --- | --- | --- |
| S-33 | Bundle Tailscale into the app | On iOS a real tunnel needs a Network Extension entitlement and its own process; the userspace version would not carry WebView traffic, which is all the traffic. Three to four weeks to match what installing Tailscale does in ten minutes. Worth revisiting if the app is ever distributed to other people |
| S-34 | Build a Tailscale equivalent | NAT traversal and a relay network are the expensive parts, and a routable public IP means they can be skipped entirely. 6–12 months to own something that already works |
| S-35 | A second updater endpoint for redundancy | The tailnet IP serves 301 to HTTPS and the certificate covers the MagicDNS name, so the hop fails TLS. A fallback needs a certificate, not an array entry |
| S-36 | Splitting `music_metadata.artist` itself | It is what the file says, and `LibraryOrganizer` builds `Artist/Album/` from it. Changing it moves files on disk |

---

## Under discussion

Not yet decided, and worth deciding before more work goes into either side.

**A native iOS/Android app, keeping the web app for desktop.** Raised after
long press, link previews and background downloads each turned out to be the
platform fighting the web app. The case each way is in `NativeClients.md`.
