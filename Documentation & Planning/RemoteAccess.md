# Reaching SoundChex From Anywhere

SoundChex runs on one machine in your home. Getting to it from a phone on
mobile data is a **networking** problem, not an application one — nothing in
the app changes. Pick one of the options below.

Before you start, confirm it works on your own network: open
`http://<your-server-ip>/app` from another device at home. If that fails,
remote access won't help yet.

---

## Option 1 — Cloudflare Tunnel (recommended)

Free, needs a domain you own, and works even if your ISP blocks inbound ports
or gives you a changing IP address. No port forwarding.

**1. Install the daemon on the server**

```bash
brew install cloudflared          # macOS
# or: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
```

**2. Authenticate and create the tunnel**

```bash
cloudflared tunnel login
cloudflared tunnel create soundchex
cloudflared tunnel route dns soundchex media.yourdomain.com
```

**3. Point it at your local server**

`~/.cloudflared/config.yml`:

```yaml
tunnel: soundchex
credentials-file: /Users/YOU/.cloudflared/<tunnel-id>.json

ingress:
  - hostname: media.yourdomain.com
    service: http://localhost:80
  - service: http_status:404
```

**4. Run it as a background service**

```bash
sudo cloudflared service install
```

**5. Update the app**

In `.env`:

```dotenv
APP_URL=https://media.yourdomain.com
APP_ENV=production
```

Then `php artisan config:clear`.

`APP_ENV=production` matters: it turns on HTTPS URL generation and hides
detailed error pages from the internet.

> **Access control.** A Cloudflare Tunnel puts your login page on the public
> internet. Login is rate limited (5 attempts per minute, per IP *and* per
> account), but if you'd rather nobody outside your household can even reach
> it, put Cloudflare Access in front of the hostname — it adds an
> email-verification step before any request touches your server.

---

## Option 2 — Tailscale (most private)

Free for personal use, no domain, no public exposure at all. The trade-off is
that every device you want to use must have Tailscale installed — so it's
excellent for family, awkward for showing something to a guest.

```bash
brew install tailscale
sudo tailscale up
```

Install Tailscale on your phone, sign in with the same account, and open
`http://<tailscale-ip>/app`.

For a real hostname and automatic HTTPS:

```bash
tailscale cert <your-machine>.<your-tailnet>.ts.net
tailscale serve https / http://localhost:80
```

Then set `APP_URL` to that `https://…ts.net` address.

---

## Option 3 — VPS reverse proxy

Worth it only if your home connection is unreliable enough that you want a
stable public IP in front of it. A small VPS holds the public address and
forwards over WireGuard to your home server. Costs a few dollars a month and
takes about half a day; the two options above are free and faster to set up.

---

## After it's reachable

**Install it on your phone.** Open the media center in the phone's browser and
use "Add to Home Screen". SoundChex ships a web app manifest, so it launches
without browser chrome and uses your logo as the icon.

**Keep the queue running.** Metadata enrichment happens in the background, so
a worker needs to be alive:

```bash
php artisan queue:work
```

For a machine that should always be serving, run it under a process manager
(launchd on macOS, systemd on Linux) so it restarts on reboot and on crash.

**Back up two things.** `database/database.sqlite` holds the entire catalog,
and `storage/app/public/artwork` holds extracted cover art. Media files live
wherever you imported them from. A plain-text catalog backup is also one
command away:

```bash
php artisan library:export ~/soundchex-backup.csv
```

---

## Security notes

Already handled in the app:

- Login and registration are rate limited per IP and per account
- HTTPS URLs are forced when `APP_ENV=production`
- Proxy headers are trusted, so tunnelled requests report the real client IP
  rather than throttling everyone as one visitor
- Audio never gets a public URL — playback is proxied through an authenticated
  route
- Only owners and admins can reach `/admin`; members get the media center only

Worth doing yourself:

- Use a real password on every account; this is now internet-facing
- Keep `APP_DEBUG=false` in production — debug pages leak file paths and config
- Prefer Cloudflare Access or Tailscale if you don't want a public login page

---

## This install (Tailscale)

Configured and working. Reachable from any device signed into the same
tailnet, at home or on cellular:

```
https://macbookair.tail7e590c.ts.net
```

Real certificate via MagicDNS, so it is a genuine secure context — which is
what makes service workers, add-to-home-screen and durable download storage
work at all.

### Why it does not proxy to port 80

Herd's nginx matches sites by hostname and returns **404** for anything it does
not recognise, so pointing `tailscale serve` at port 80 serves nothing. The app
is served directly instead, and Tailscale proxies to it:

```bash
php artisan serve --host=127.0.0.1 --port=8000
sudo tailscale serve --bg 8000
```

`trustProxies(at: '*')` is already set in `bootstrap/app.php`, so Laravel sees
the forwarded HTTPS scheme rather than generating `http://` URLs behind the
proxy.

### Keeping it running

`php artisan serve` is single-threaded and dies with its terminal. Fine while
testing; for something that survives a reboot, run it under launchd — see
`com.soundchex.serve.plist` in this folder.

`tailscale serve --bg` persists on its own and comes back after a restart.

### APP_URL

Set to the Tailscale hostname. It has to match the origin pages are actually
served from: Laravel builds asset and route URLs from it, and a mismatch
registers the service worker against a scope the pages do not live in.

Switching back to local-only development means setting it back to
`https://soundchex.test`.

### Making it public without a client install (Funnel)

Tailscale requires the app on every device, which is right for a household and
awkward for anything else. **Funnel** serves the same URL to the open internet
with the same certificate, and needs nothing installed on the visitor's device:

```bash
sudo tailscale funnel --bg 8000     # public
sudo tailscale funnel --bg off      # back to tailnet-only
tailscale funnel status
```

There is no middle ground here: anything that removes the per-device install
necessarily makes the server publicly reachable. That is the definition, not a
limitation of the tool.

**Close registration before enabling it.** The login page is then the only
thing between the internet and the library:

- Sign-up is gated by `EnsureRegistrationIsOpen`, driven by the
  "Allow public registration" setting. It defaults to **closed** — a server
  that might be public should not accept sign-ups because nobody has opened
  the settings page yet.
- Login and registration are both rate-limited per IP and per account.
- Household members are added from `/admin/users`.

The stronger option is Cloudflare Tunnel with Cloudflare Access, which
authenticates against Google or GitHub *before* a request reaches this machine.
It needs a free Cloudflare account and is worth it if the library is ever
exposed for long periods.

## Keeping the address stable

The tailnet IP and MagicDNS name belong to the machine rather than the network,
so they follow a travelling laptop between home, office and a phone hotspot. On
this setup the LAN address changed four times in one day while
`100.106.62.120` and `macbookair.tail7e590c.ts.net` did not move.

Two things would change that, and both are avoidable:

- **Removing and re-adding the machine** in the Tailscale admin console. It
  comes back as a new node with a new address, and every client that had the old
  one is pointing at nothing.
- **Key expiry.** A node's auth key expires — this one on 2027-02-09 — and the
  machine drops off the tailnet until someone re-authenticates it. The address
  survives, but the server is unreachable in the meantime, which on a phone
  looks exactly like the server being down.

Disable key expiry for the host machine in the admin console. It is the right
call for a server that is meant to answer without anyone tending it, and the
alternative is the library going dark on a date nobody has written down.

## Which route to prefer

Measured against this server, from the same room:

| | |
| --- | --- |
| LAN | 37ms |
| Tailscale direct | 18ms |
| Funnel hostname | **1,332ms** |

Funnel relays through Tailscale's public infrastructure — the nearest relay here
is Los Angeles — so it is slow from anywhere, including the next room. It earns
its place as the only route that works from outside the tailnet, and should
never be preferred while a direct one answers. The clients measure every
candidate and take the quickest rather than the first to reply.
