# Moving a library to another machine

Copying a SoundChex server — the catalogue, the media, the profiles — to a
second machine over a tailnet or a forwarded address.

Both machines run the same code. The one being copied **approves**; the one
doing the copying **asks and pulls**.

---

## Before you start

**On both machines**

- SoundChex installed and running, on a build that has the transfer tables.
  `php artisan migrate` after pulling.
- **`php artisan queue:work` running.** This is the one people forget: without
  it a transfer is approved and then sits doing nothing at all. It needs its own
  terminal on Windows, where the Services page does not work.

**On the receiving machine**

- Enough free disk for what is coming. A full copy of this library is ~46 GB.
- `php artisan storage:link` done, or artwork will 404 afterwards.

**Reachable**

The receiver has to be able to open the source in a browser. Try it first:

```
https://macbookair.tail7e590c.ts.net/soundchex.json
```

A tailnet name or a forwarded address, including `https://`. If that does not
answer, nothing below will work.

---

## Doing it

### 1. On the receiving machine — ask

**Admin → Server transfer**

Fill in:

- **Its address** — the server you are copying *from*.
- **What to bring across**:

| | |
| --- | --- |
| **The catalogue** | Titles, artists, albums, artwork. Minutes. |
| **The media files** | The actual music, films and books. Hours. |
| **Profiles and history** | People, resume points, watchlists. **Comes with the catalogue** — the database holds both, so choosing the catalogue brings these too. |
| **Settings and keys** | API keys, watch folders. Usually leave this off — the new machine wants its own. |

- **Your password.**

Press **Ask that server**. Note the code it shows you.

Nothing has moved yet. Nothing *can* move until the next step.

### 2. On the source machine — approve

**Admin → Server transfer**

The request appears at the top with:

- the address it came from
- what the machine calls itself
- what it is asking for
- **a four-digit code**

**Check the code matches** the one on the other screen. That is how you know
this is the request that was just started, rather than someone else's arriving
at the same moment.

Type your password and press **Approve**.

### 3. Back on the receiving machine — start

Press **Check for approval** on the transfer. It will start.

From here it runs in the background:

- Files already present with the right content are skipped.
- Each file is checked after it arrives; anything corrupt is deleted and
  recorded rather than kept.
- **Pause** and **Resume** whenever. It picks up where it stopped, not from the
  beginning.

Progress shows files done, gigabytes, and anything that failed.

---

## While it runs

**It can be stopped from either end.** The source shows approved transfers with
a **Stop** button, and pressing it ends the transfer immediately — every
request the receiver makes re-checks whether it is still allowed.

**Interruptions are expected.** A laptop sleeping, a network dropping, a
machine rebooting. Nothing is lost: what has arrived stays arrived, and
resuming continues.

**Where the files land.** Under the receiving machine's own storage root, in
the same folder shape as the source — `media/library/Music/Artist/Album/`. It
does not inherit absolute paths, which is why a Mac can copy to a Windows
machine.

---

## Afterwards

**If you brought the catalogue**, the receiving machine's own database was
backed up first — to `storage/app/backups/before-transfer-*.sqlite` — and then
replaced. Its own play history and playlists are in that file if you want them
back.

The backup is taken before anything is downloaded, so it exists even if the
import then fails.

**Run a scan** on the receiving machine to confirm what arrived:

```
php artisan library:scan
```

**Check the failures.** The transfer page lists anything that did not arrive,
with the reason. A handful of failures out of thousands is usually a network
blip and worth simply running the transfer again — it will skip everything
already present and retry only what is missing.

**Nothing is deleted from the source.** A transfer copies. Emptying the old
machine is a separate, deliberate act, and worth leaving until you have played
something from the new one.

---

## When it goes wrong

| What you see | What it is |
| --- | --- |
| "Could not ask that server" | The address is wrong or unreachable. Try it in a browser. |
| Approved, then nothing happens | `queue:work` is not running on the receiving machine. |
| Stuck at "requested" | Nobody has approved it yet, or the request expired — they last four hours. |
| Files failing with hash mismatches | The connection is corrupting data. Retry; persistent mismatches on the same file mean it is damaged at the source. |
| "This transfer is no longer approved" | It was stopped from the source, or the four hours ran out. Ask again. |

The transfer page records every failure with its reason. **Admin → Device
reports** has anything the app itself logged.

---

## What it does not do

- **Two-way sync.** This is a copy, not a merge. Running it in both directions
  will not reconcile anything — whichever ran last wins.
- **Delete from the source.**
- **Compress the media.** Measured: an MP3 gzips by 0.4%. The database is
  compressed, at 85%.
