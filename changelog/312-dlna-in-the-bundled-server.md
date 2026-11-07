# 312 — Make DLNA discovery work in the bundled server

**Merged** 2026-11-06 · **Issues** #525, S-7

`artisan dlna:serve` could not run in the bundled server on any platform, and
where it did run it advertised a port nothing listened on and, on a machine
that routes between networks, the wrong address. This fixes all of that and
lets headless mode run it.

## What changed

### sockets is built into the bundled PHP

The static PHP had no `sockets` extension, so `dlna:serve` exited at once.
Found by running `php -m` on the 0.2.0 x86_64 .deb (commit 1176b7a). It is
now in the build list in `build-server.yml`. `package-runtime.sh` refuses a
PHP without it, and a Windows bundle without `php_sockets.dll`. The Windows
`php.ini` loads it, and the Services page lists it as recommended.

### The advertised port is the one Caddy listens on

`--port` defaulted to 8200 while Caddy listens on 8000, and no unit passed a
port. The default now follows `SOUNDCHEX_LISTEN`, which the headless and
window supervisors already set. The systemd and launchd DLNA units now set
it, and the Windows installer passes `--port`. With nothing set, it uses
8000. `--port` still overrides.

### Choosing the address

`--address=IP`, or `DLNA_ADDRESS` in `.env`, sets the address to advertise.
The default is still the default route's. That address is wrong on a machine
that routes between networks: on HatCOS it is the Starlink side, or the VPN's
10.2.0.2. Multicast is now joined and sent on the interface that holds the
chosen address; before, it used the default route's interface whatever was
advertised. An address that is not IPv4, or that no interface has, is refused
before any socket opens.

### Reading the route source by name

The Linux lookup was `ip route get 1.1.1.1 | awk '{print $7}'`. When the
route has no `via` (a directly connected route such as a VPN tunnel), field 7
is the uid, so the server advertised itself at "0". It now reads the value
after `src`.

### Announcing only while DLNA is on

It used to announce at start whatever the setting. It now announces when DLNA
is switched on, sends byebye when it is switched off, and checks every loop.
The service units' claim that it is "harmless when DLNA is switched off" is
now true.

### Headless can run it

`soundchex-server --headless --dlna` (or `SOUNDCHEX_DLNA=1`, which the window
also honours) adds `dlna:serve` to the supervised processes. It is off by
default because HatCOS runs `dlna:serve` itself, and two copies answering
under one UUID would confuse devices.

### composer.json requires PHP 8.4.1

It declared `"php": "^8.3"`, but the lock holds Symfony 8 packages that need
PHP 8.4.1 or later. So `composer install` failed on PHP 8.3, Ubuntu 24.04's
default, and the requirement said otherwise (#526). It is now `^8.4.1`. The
bundled runtime and every CI workflow already use 8.4. The lock was refreshed
with `composer update --lock`, which changed no package versions: only the
content hash, the platform line, and phpdotenv's repository URL (the project
moved organisations; same commit). The README, the Windows setup guide and
Status.md no longer say 8.3.

## Worth knowing

- HatCOS can now drop any port workaround. To advertise the Wi-Fi network,
  set `DLNA_ADDRESS` or pass `--address`.
- The runtime has to be rebuilt to get `sockets`. A runtime reused from an
  earlier CI run does not have it.

## Still wrong

- Not run on a real network. The addressing logic is unit-tested, and the
  refusals are tested through the command without opening a socket. On this
  Mac, headless `--dlna` started and stopped cleanly, but the local runtime
  predates `sockets`, so `[dlna]` exited at once. That is the bug this fixes.
  Discovery itself is first seen on HatCOS.
- If `dlna:serve` keeps failing (no sockets, no address), the supervisor
  restarts it every two seconds with the same error, because there is no
  backoff.
- macOS still finds its address as `en0`'s only.
- `composer update --lock` reported 44 security advisories across 12 locked
  packages. They predate this change, which updates no packages; they need
  their own look.

## Tests

PHP: `DlnaAddressingTest` (7) covers the route source with and without a
gateway, the advertised port, and the interface lookup. Putting back the
field-7 parsing turns it red. `DlnaDiscoveryTest` has three new refusal
tests. The full suite in a clean worktree: 1,835 pass. One fails,
`ServerHealthCheckTest`, and it fails the same way without this branch: this
Mac is down to about 4% free disk, which the health check reports. Rust: 35
pass (new: `--dlna` is opt-in).
