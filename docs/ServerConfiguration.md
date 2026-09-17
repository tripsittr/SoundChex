# Server configuration

Most of what a server needs is set from the admin panel and stored in the
database. A few things the framework reads at boot must live in `.env` instead —
`APP_URL` and `APP_ENV`. Both can now be set from the panel too; editing `.env`
by hand is the advanced path.

## From the panel (recommended)

**Admin → System → Server Settings.**

| Setting | What it does |
|---|---|
| **Server address (`APP_URL`)** | The address this server advertises to devices, port included. Wrong or portless, and phones can't find the server. Pick one of this machine's detected addresses, or type a custom one (a public tunnel, a reverse proxy). |
| **Environment (`APP_ENV`)** | `local` for setup (detailed error pages). `production` once the server is reachable from the internet (errors hidden, HTTPS URLs generated). |

Saving writes `.env` and clears the config cache for you — no terminal step.

**Auto-detect** fills the address with this machine's best detected LAN or
tailnet address in a click.

**Watch folders** are on **Admin → Settings → Library**, not here.

### Zero-config for the downloaded app

The desktop **SoundChex Server** app detects its own address and keeps `APP_URL`
current on its own (an hourly `server:detect-address` task, and it runs on first
launch). It only fills `APP_URL` when it is unset or the install default, so an
address you set deliberately is never overwritten. This is what stops the
"server moved to a new network and the phone can't find it" problem.

## From `.env` (advanced / custom deploys)

If you run the Laravel server yourself, these keys can be edited directly:

| Key | What it does |
|---|---|
| `APP_URL` | The address the server advertises to devices — **port included**. |
| `APP_ENV` | `production` once reachable from the internet: HTTPS URL generation on, detailed error pages off. |
| `LIBRARY_WATCH_FOLDERS` | Watch folders as comma-separated **absolute** paths — the `.env` alternative to the Library Settings page. |

**After changing `.env` by hand, run `php artisan config:clear`.** The panel and
the `server:detect-address` command do this for you; a hand edit does not.

## Detecting an address by hand

```sh
php artisan server:detect-address          # set APP_URL if it is unset/default
php artisan server:detect-address --force  # set it regardless
```
