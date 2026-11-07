# 315 — Finish the DLNA review fixes that missed #314

**Merged** — · **Issues** #525, S-7

#314 was merged at its first commit. The fixes from its two later reviews
were pushed to the branch afterwards and never reached `main`. This brings
them in. What `main` had without them:
- A locked or unmigrated database could still kill `dlna:serve`.
- A brief lock made every TV drop the server and add it back.
- Every Docker, WSL or Parallels machine was warned about "several networks".
- Searches relayed from another VLAN, and every search on a /32 address,
  were dropped.

## What changed

### Every settings read is guarded

The server's UUID (written on first use), its name, and whether DLNA is on
are all read inside the loop through `readOr()`:
- **A failure keeps the last known state.** Treating it as "off" sent a
  byebye, then an alive five seconds later, to every TV.
- **A run of failures logs once,** and the identity read and the settings
  read are tracked separately.
- **Nothing is announced until the UUID can be read.**

### Fewer false "several networks" warnings

These no longer count as LANs:
- interfaces that are down;
- container and VM bridges: `docker0`, Swarm's `docker_gwbridge`, Docker's
  `br-` plus twelve hex digits, macOS's `bridge100` and up (Internet Sharing,
  UTM, Lima), `virbr0`, `vnic0` and ZeroTier;
- Windows' Hyper-V, WSL, WireGuard, AnyConnect, OpenVPN, Wintun, GlobalProtect,
  Fortinet and Tailscale adapters, recognised by description, since Windows
  marks nothing point-to-point.

OpenWrt's `br-lan` and macOS's `bridge0` still count, because both are often
real networks. A real LAN behind a Hyper-V external switch shares the virtual
adapter's description, so that host can miss the warning.

### Only this machine's other networks are ignored

#314 dropped every search from outside our own subnet. That dropped searches
relayed from an IoT VLAN, which used to be answered, and every search on a
/32. Now a search is ignored only when it comes from one of this machine's
*other* networks: the uplink's subnet, a VPN's far end, or a /31 uplink's
peer.

The rules, in order:
1. **This machine's own addresses are answered.** A local client whose search
   leaves by the VPN can still reach the LAN address.
2. **A tunnel's far end is ignored.**
3. **Otherwise the most specific subnet containing the source decides whose
   network it is on.** A tie goes to our own interface, so Wi-Fi and Ethernet
   on one LAN cannot hide the LAN's TVs. Docker's 172.17/16 cannot hide a LAN on 172.17.5.x,
   and a LAN on a 10/8 cannot swallow a VPN or uplink peer inside it. The
   second review's fix (our own subnet first) got the first case right and
   the second wrong; the third review caught it.
4. **A source on none of our networks is answered.** This covers routed and
   relayed searches. When our own interface, or its netmask, is unknown, every
   source except a tunnel's far end is answered: with nothing to compare
   against, answering is the safe side. In practice this does not arise,
   because Windows, macOS and Linux all report a netmask.

Subnets shorter than /8 are ignored. The first 256 ignored sources are each
logged as a warning; after that, one line says logging has stopped.

### Clocks

The loop uses a monotonic clock, so a wall-clock step on a router with no RTC
cannot stall it. That clock stops during sleep, so a wake is detected when the
wall clock has run more than a minute ahead of it. The server then announces
20 seconds later rather than at once, because the first pass after a wake runs
before Wi-Fi has rejoined, and an alive sent then is lost.

### Smaller

- Socket errors are cleared before each call, so a stale errno is not
  reported.
- The `IP_MULTICAST_ALL` failure is logged.
- A guess with no interface raises no PHP warning.
- The prefix is clamped.
- The join fallback is a pure function with tests.
- `SOUNDCHEX_DLNA` parsing has a Rust test.

## Worth knowing

HatCOS should still set `DLNA_ADDRESS`, and its firewall must allow UDP 1900
and the HTTP port from the LAN.

## Still wrong

- Discovery has never run on a real network, on Linux or on Windows.
- The loop's wiring in `handle()` is not under test; its decisions are pure
  functions that are. That includes the wake: `woke()` is tested, the line
  that acts on it is not.
- The interfaces are read once at start, so an address that changes later,
  including during sleep, is not picked up.
- On macOS a `utun` tunnel reports its own address as its peer, so a real VPN
  peer there is not recognised as foreign. This predates #312.
- `ssdp:all` gets one reply rather than one per type, and `ST: uuid:…` is not
  answered. Both predate #312.
- The Server window does not show the `dlna` process.
- If our own interface reported a 0.0.0.0 netmask, another interface's subnet
  could still make a LAN source foreign. No OS is known to report that.
- Windows' `net_get_interfaces()` lists only Ethernet, Wi-Fi and loopback
  adapters, so WireGuard and Wintun never appear there, and those description
  patterns match only elsewhere.

## Tests

A subagent reviewed this code five times: twice on the #314 branch, then three
times on this branch. The fourth review found that the longest-prefix rule let
a tie go to whichever interface was listed first. That is fixed and tested.
The fifth found no blocking or behavioural problems; its wording fixes are
applied and its two edge cases are listed above. `DlnaAddressingTest` (37) and `DlnaSettingsReadTest` (3) cover:
- the join fallback and the settings read;
- the bridge and Windows-adapter exclusions;
- the foreign-source rules: overlapping subnets either way round, a relayed
  VLAN, a tunnel peer, a /31 uplink, our own address, a /0 netmask;
- wake detection and the prefix clamp.

Each of these mutations turns them red:
- removing the fallback join;
- returning `false` on a failed read;
- logging every failure;
- not skipping down interfaces;
- ignoring the tunnel peer;
- removing the re-announce;
- removing the point-to-point check;
- breaking a tie in favour of the first interface listed;
- removing the early answer when our own subnet is unknown;
- removing the /8 floor;
- letting the last matching subnet win instead of the most specific.

The full PHP suite in a clean worktree: 1,865 pass. One fails,
`ServerHealthCheckTest`, from this Mac's low disk space; it fails the same way
without this change. Rust: 36 pass.
