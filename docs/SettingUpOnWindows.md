# Setting up SoundChex on Windows 11

Written to be followed in order. Each step says how to tell it worked, because
several of the failures here are silent — the app starts, looks right, and does
nothing useful.

**Nobody has completed this.** SoundChex has been built and run on macOS and
iOS many times and on Windows never. The steps come from Tauri's requirements
and from reading this codebase for the places it assumes Unix, not from a run
anyone has watched succeed. Where something is likely to be wrong, it says so.

---

## 1. Install the toolchain

| | | |
| --- | --- | --- |
| **PHP 8.4+** | [windows.php.net](https://windows.php.net/download) or `winget install PHP.PHP` | 8.3 satisfies composer.json, but everything has been tested on 8.4 |
| **Composer** | [getcomposer.org](https://getcomposer.org/download/) | |
| **Node 22+** | `winget install OpenJS.NodeJS` | |
| **FFmpeg** | `winget install Gyan.FFmpeg` | Transcoding, captions and durations all shell out to it |
| **Rust** | [rustup.rs](https://rustup.rs) | Take the **MSVC** toolchain, not GNU |
| **VS Build Tools** | [visualstudio.microsoft.com](https://visualstudio.microsoft.com/visual-cpp-build-tools/) | Tick **Desktop development with C++** |

Rust and the Build Tools are only needed to build the desktop app. The server
runs without them.

**Reopen your terminal** after installing — `winget` changes the PATH and an
open shell will not see it.

**Check:**

```powershell
php -v          # 8.4.x
node -v         # v22.x
composer -V
ffmpeg -version
```

`ffmpeg` is the one that most often is not found. If it is missing, transcoding
and duration reading fail with nothing obvious in the app.

---

## 2. Get the code

```powershell
git clone https://github.com/tripsittr/SoundChex.git
cd SoundChex

composer install
npm install
```

---

## 3. Configure it

```powershell
copy .env.example .env
php artisan key:generate
```

Then open `.env` and set two things.

**Where the media is.** Absolute paths, comma-separated. Backslashes are fine —
the split is on commas, so a drive letter is never mistaken for a separator:

```
LIBRARY_WATCH_FOLDERS=D:\Media\Music,D:\Media\Films
```

**What the app calls itself.** This is the address it advertises to phones and
other devices, so the port matters:

```
APP_URL=http://localhost:8000
```

Nothing is moved out of the watch folders until it has been catalogued and
identified, and the originals are never deleted.

---

## 4. Create the database

```powershell
php artisan migrate --force
```

SQLite, one file at `database\database.sqlite`. The command creates it — there
is no file in a fresh clone and none is needed. 43 migrations should run.

**Check:** `database\database.sqlite` exists and is a few hundred KB.

---

## 5. Link public storage

```powershell
php artisan storage:link
```

**This one fails silently if you skip it.** `public/storage` is a symlink,
gitignored, and absent from a fresh clone. Without it every avatar and artist
image returns 404 — the app works, the pictures are missing, and nothing
explains why.

Windows restricts symlink creation. If the command errors:

- turn on **Settings → System → For developers → Developer Mode**, or
- run the command from a terminal opened as Administrator.

**Check:** `public\storage` exists and opening it shows the contents of
`storage\app\public`.

---

## 6. Build the front end

```powershell
npm run build
```

**Check:** `public\build\manifest.json` exists. Without this the app serves
pages with no CSS or JavaScript.

---

## 7. Start it

Three terminals, or three Windows services later.

```powershell
php artisan serve                # the app
php artisan queue:work           # enrichment, transcoding, downloads
php artisan schedule:work        # scanning, backups, pruning
```

The **Services** page in the admin panel manages those last two on macOS. It is
built on launchd and has no Windows equivalent; the page detects this and says
so rather than failing. [NSSM](https://nssm.cc) will run them as services if
you want them at boot.

**Check:** open `http://localhost:8000`. Register — the first account becomes
the owner automatically.

---

## 8. Scan the library

Start with a **small** folder — a dozen files, not the whole collection.

```powershell
php artisan library:scan
```

**Check:** the items appear at `http://localhost:8000/app/music`.

This is the step most likely to fail on Windows, and the reason to start small.
Path handling is where a Unix-shaped codebase breaks, and three fixes for it
have been written and never run here:

- absolute paths are now recognised by drive letter and UNC share rather than a
  leading separator (`C:\Users\...` previously read as *relative*, which would
  have made **every file in the library** unreadable);
- LAN address detection asks PowerShell rather than running `ipconfig getifaddr
  en0`;
- Tailscale is looked for in `C:\Program Files\Tailscale\` as well as the
  Homebrew paths.

If the scan finds nothing, or finds files it cannot then play, that is the
first place to look.

---

## 9. Build the desktop app

```powershell
npm run tauri:build          # the client
npm run build:server         # the host app, with service controls
```

Output lands in `src-tauri\target\release\bundle\` as `.msi` and `.exe`.

A first build compiles the whole Rust dependency tree and takes a while.

---

## Running the tests

```powershell
php artisan test             # 334
npx vitest run               # 87
```

Both are portable and both should pass. If they do not, the problem is the PHP
or Node install rather than anything about Windows.

```powershell
npx playwright test          # needs bash
```

The browser suite shells out to `tests/e2e/bootstrap.sh` to build its isolated
environment, so it needs Git Bash or WSL. It never touches a real library — the
bootstrap refuses to run unless its paths are scratch paths.

---

## If something is wrong

| Symptom | Cause |
| --- | --- |
| Pages render with no styling | `npm run build` was not run |
| Avatars and artist images 404 | `php artisan storage:link` was skipped or failed |
| Scan finds nothing | Watch folder paths — see step 8 |
| Files catalogued but will not play | `ffmpeg` not on the PATH |
| Phones cannot find the server | `APP_URL` missing its port, or the firewall |
| Services page says it is unsupported | Expected. It is launchd-only |

The app records what fails. **Admin → Device reports** shows what each device
sent back, filterable by device and by log type, which is more use than
guessing.

---

## What to expect

The web app itself is platform-neutral — Blade, CSS and JavaScript on a Laravel
server. What is Unix-shaped is the handful of places the server asks the
operating system a question, and those are listed above.

The realistic risk is not that nothing works. It is that something works
*almost*: files catalogue but do not play, or the library scans but the app
cannot find the files afterwards. Both are path handling, and both show up in
step 8 — which is why it comes before building anything.
