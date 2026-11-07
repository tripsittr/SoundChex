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
- container and VM bridges: `docker0`, Docker's `br-` plus twelve hex
  digits, `virbr0`, `vnic0` and ZeroTier;
- Windows' Hyper-V, WSL, WireGuard and AnyConnect adapters, recognised by
  description, since Windows marks nothing point-to-point.

OpenWrt's `br-lan` and macOS's `bridge0` still count, because both are often
real networks. A real LAN behind a Hyper-V external switch shares the virtual
adapter's description, so that host can miss the warning.

### Only this machine's other networks are ignored

#314 dropped every search from outside our own subnet. That dropped searches
relayed from an IoT VLAN, which used to be answered, and every search on a
/32. Now a search is ignored only when it comes from one of this machine's
*other* networks: the uplink's subnet, a VPN's far end, or a /31 uplink's
peer. Some sources are always answered:
- **Our own subnet.** It is checked first, so Docker's 172.17/16 over a
  172.17.5.x LAN cannot hide the LAN's TVs.
- **This machine's own addresses.** A local client whose search leaves by
  the VPN can still reach the LAN address.

A prefix shorter than /8 on another interface is ignored. The first ignored
search from each source is logged as a warning.

### Clocks

The loop uses a monotonic clock, so a wall-clock step on a router with no RTC
cannot stall it. That clock stops during sleep, so a wake is detected when the
wall clock has run more than a minute ahead of it, and the server announces
at once.

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
  functions that are.
- `ssdp:all` gets one reply rather than one per type, and `ST: uuid:…` is not
  answered. Both predate #312.
- The Server window does not show the `dlna` process.

## Tests

A subagent reviewed this code twice on the #314 branch, and this PR answers
both reviews. `DlnaAddressingTest` (33) and `DlnaSettingsReadTest` (3) cover:
- the join fallback and the settings read;
- the bridge and Windows-adapter exclusions;
- the foreign-source rules: overlapping subnets, a relayed VLAN, a tunnel
  peer, a /31 uplink, our own address, a /0 netmask;
- wake detection and the prefix clamp.

Each of these mutations turns them red:
- removing the fallback join;
- returning `false` on a failed read;
- logging every failure;
- not skipping down interfaces;
- ignoring the tunnel peer;
- removing the re-announce;
- removing the point-to-point check;
- removing the own-subnet-first rule;
- removing the /8 floor.

The full PHP suite in a clean worktree: 1,861 pass. One fails,
`ServerHealthCheckTest`, from this Mac's low disk space; it fails the same way
without this change. Rust: 36 pass.
