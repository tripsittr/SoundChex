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

Tunnels do not count as LANs. Checked against this Mac's real interfaces:
the LAN gets no warning, and the VPN (`10.2.0.2`) and Tailscale are flagged.

### Devices keep the server

While DLNA is on, the server announces again every 15 minutes, half the
30-minute cache time. Before, a device that listens rather than searches
dropped it after half an hour. It also announces all four notification types
(root device, UUID, device type, ContentDirectory) instead of only the device
type.

### Only its own network is answered

Searches from outside the advertised interface's subnet get no reply, and
Linux's `IP_MULTICAST_ALL` is turned off. Before, a router running another
UPnP daemon could answer its uplink or VPN with the LAN address.

### Quieter, and survives a bad read

The settings are read every 5 seconds instead of every loop and every packet.
A failure to read them (a locked SQLite file, or an unmigrated first boot) is
logged instead of killing the process into a two-second restart loop. Socket
failures now log PHP's own message instead of "Undefined error: 0", and a
failed reply is logged.

### Nits

The runit config comment is back above its own block, and `SOUNDCHEX_DLNA`
also accepts `yes`, `on` and any case.

## Worth knowing

- HatCOS should still set `DLNA_ADDRESS`. Without it the server now warns,
  but it still advertises the guessed address.
- HatCOS's firewall must allow UDP 1900 and the HTTP port from the LAN.

## Still wrong

- Discovery has still never run on a real network, on Linux or on Windows.
- The Server window does not show the `dlna` process, though `status()`
  reports it.

## Tests

The refusal tests no longer run the command, which would have bound UDP 1900
on a real network had a refusal regressed. They test pure functions instead,
as do the new rules for announcing, subnets and doubtful guesses.
`DlnaAddressingTest` has 17 tests. Removing the re-announce, or the
point-to-point check, turns it red. The full PHP suite in a clean worktree:
1,842 pass. One fails, `ServerHealthCheckTest`, from this Mac's low disk
space; it fails the same way without this change. Rust: 35 pass.
