# 073 — Server config from the panel

**Merged** 2026-09-17 · **Issues** S-147

`APP_URL` and `APP_ENV` — the settings the framework reads at boot, which have to
live in `.env` — can now be set from the admin panel instead of a terminal, and
the server keeps its own address current.

## What changed

### Server Settings page

**Admin → System → Server Settings** (server-admin gated). Edits the server
address (`APP_URL`) and environment (`APP_ENV`), writes `.env`, and runs
`config:clear` itself so the change is live with no terminal step. The address
field offers this machine's detected addresses, and an **Auto-detect** action
fills it with the best one.

Before, the docs told self-hosters to edit `.env` by hand and remember
`config:clear` — and `APP_URL` had a chicken-and-egg, since it is the address the
server advertises and you cannot set it from a page reached *via* that address.
The panel is reachable on localhost whatever `APP_URL` says, which breaks that.

### `server:detect-address` keeps APP_URL current

A new command sets `APP_URL` to the machine's best detected LAN or tailnet
address. It only fills an address that is unset or the install default, so a
deliberately-set one (a public tunnel, a reverse proxy) is left alone; `--force`
overrides. It is scheduled hourly and is meant to run on desktop-app launch, so
the downloaded Server app is zero-config and a machine that changes networks
fixes its own address without anyone editing `.env` — the recurring
"server moved, the phone can't find it" problem.

### `.env` still works

`docs/ServerConfiguration.md` documents both paths: the panel (recommended) and
editing `.env` directly (advanced), including `LIBRARY_WATCH_FOLDERS` and the
`config:clear` reminder for hand edits.

## Worth knowing

- This machine's `APP_URL` is still the stale `macbookair` address. Run
  `php artisan server:detect-address --force` (or Server Settings → Auto-detect
  → Save) to correct it to the tailnet address.
- The old **Metadata Sources** admin page was removed separately (PR #72); its
  keys are on the Integrations page now.
- No migration; `EnvironmentFile` writes `.env`, which is not tracked.
