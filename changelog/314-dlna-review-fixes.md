# 314 — Fix what the review of DLNA discovery found

**Merged** — · **Issues** #525, S-7

#312 was merged and released in `server-v0.2.0-beta.2` before it had been
reviewed. A review afterwards found a Windows regression it caused, and
several ways discovery could fail on a router like HatCOS without saying why.
This fixes them.

## What changed

### Windows hears searches again

`net_get_interfaces()` names a Windows adapter by its GUID (`p->AdapterName`
in php-src's `ext/standard/net.c`), and joining the group converts the name
with `if_nametoindex()`, which does not accept a GUID. So on Windows the join
by name failed and the server never heard an M-SEARCH. #312 had removed the
old join on interface 0. A failed join by name now falls back to interface 0,
and `IP_MULTICAST_IF` is set only when the name was usable. This is confirmed
from php-src; it has not been run on Windows.

### A wrong guess says so

With no `DLNA_ADDRESS`, the command guesses the default route's address. It
now warns, on screen and in the log, with the fix, when that address:
- is on a point-to-point link such as a VPN,
- is not RFC 1918 (Starlink's CGNAT `100.64/10`, for example), or
- is one of several LANs.

Interfaces that are down, container and VM bridges (`docker0`, Docker's
`br-` plus twelve hex digits, `virbr0`, `vnic0`, ZeroTier and similar), and
Windows' Hyper-V, WSL, WireGuard and AnyConnect adapters (recognised by
description, since Windows marks nothing point-to-point) do not count as
LANs. OpenWrt's `br-lan` and macOS's `bridge0` do count; both are often real
networks. A real LAN behind a Hyper-V external switch shares the virtual
adapter's description, so that host can miss the warning. Without that, every machine running
Docker or WSL warned on every start. Checked against this Mac's real
interfaces: the LAN gets no warning, and the VPN (`10.2.0.2`) and Tailscale
are flagged.

### Devices keep the server

While DLNA is on, the server announces again every 15 minutes, half the
30-minute cache time. Before, a device that listens rather than searches
dropped it after half an hour. It also announces all four notification types
(root device, UUID, device type, ContentDirectory) instead of only the device
type.

### Its other networks are not answered

A search from one of this machine's *other* networks gets no reply: the
uplink's subnet, a VPN's address or its far end, or a /31 uplink's peer.
Linux's `IP_MULTICAST_ALL` is turned off, and a failure to do so is logged.
Before, a router running another UPnP daemon could answer its uplink or VPN
with the LAN address.

This is deliberately not "anything outside our own subnet". That was the
first version, and the review showed it dropped searches relayed from
another VLAN (a common IoT setup that used to work), and every search on a
/32 address.

Some sources are always answered:
- **Our own subnet.** It is checked first, so a Docker /16 or a k3s range
  overlapping the LAN cannot hide the LAN's TVs.
- **This machine's own addresses.** A local client whose search leaves by
  the VPN can still reach the LAN address.
- **Anything, if another interface's prefix is shorter than /8.** Such a
  prefix (a misreported netmask) is ignored rather than making every source
  foreign.

The first ignored search from each source is logged as a warning, so a TV
that never finds the server can be traced in the log.

### Quieter, and survives a bad read

The settings are read every 5 seconds instead of every loop and every packet,
timed by a monotonic clock so a wall-clock jump cannot stall it. That clock
stops while the machine sleeps, so a wake is detected when the wall clock has
run more than a minute ahead of it, and the server announces at once instead
of after fifteen more minutes. Every
settings read is guarded, including the server's UUID, which is *written* on
first use, and its name. A failure (a locked SQLite file, or an unmigrated
first boot) keeps the last known state instead of killing the process into a
two-second restart loop. It also does not switch advertising off: that would
have sent a byebye, then an alive five seconds later, to every TV. A run of
failures is logged once. The two reads (identity and settings) are tracked
separately. Until the UUID can be read, nothing is announced.
Socket failures log PHP's own message, with the socket error cleared before
each call so an old errno is not reported, and a failed reply is logged.

### Nits

The runit config comment is back above its own block, and `SOUNDCHEX_DLNA`
also accepts `yes`, `on` and any case.

## Worth knowing

- HatCOS should still set `DLNA_ADDRESS`. Without it the server now warns,
  but it still advertises the guessed address.
- HatCOS's firewall must allow UDP 1900 and the HTTP port from the LAN.

## Still wrong

- Discovery has still never run on a real network, on Linux or on Windows.
- The loop itself (`handle()`) is not under test. Its decisions are pure
  functions with tests (`transition`, `readOr`, `joinWithFallback`,
  `foreignSource`, `addressProblem`), but the wiring between them is checked
  only by reading.
- On Windows the fallback joins the default adapter only, as before #312.
- `ssdp:all` gets one reply rather than one per type, and `ST: uuid:…` is not
  answered. Both predate #312.
- The Server window does not show the `dlna` process, though `status()`
  reports it.

## Tests

A subagent reviewed the PR three times:
1. The first review found that the first version half-handled exceptions,
   sent a byebye on a failed read, warned on every Docker or WSL machine, and
   dropped VLAN-relayed searches.
2. The second found that an overlapping subnet hid the whole LAN, that a wake
   from sleep went unannounced, and that `br-lan` was skipped.

All of that is fixed here, and the tests below cover it.

The refusal tests no longer run the command, which would have bound UDP 1900
on a real network had a refusal regressed. They test pure functions instead,
as do the new rules for announcing, subnets and doubtful guesses.
`DlnaAddressingTest` (33) and `DlnaSettingsReadTest` (3) cover the join
fallback, the settings read, the bridge and Windows-adapter exclusions, the
foreign-source rule (including a relayed VLAN, a tunnel peer and a /31
uplink) and the prefix clamp. Each of these mutations turns them red:
removing the fallback join, returning `false` on a failed read, logging every
failure, not skipping down interfaces, ignoring the tunnel peer, removing the
re-announce, removing the point-to-point check, removing the own-subnet-first
rule, and removing the /8 floor. One mutation survived and
showed a wrong rule (a /31 exemption carried into the other-networks check),
which is now removed and tested. The full PHP suite in a clean worktree:
1,861 pass. One fails, `ServerHealthCheckTest`, from this Mac's low disk
space; it fails the same way without this change. Rust: 36 pass (new:
`SOUNDCHEX_DLNA` parsing).
