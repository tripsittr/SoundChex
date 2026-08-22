# Direct remote access

A route to the library from outside the house that does not relay through
anyone else's infrastructure.

## Why

Every request from the phone currently goes out to Tailscale's Funnel and back.
Measured on this server, same page, same room:

| Route | TTFB |
| --- | --- |
| LAN `192.168.1.93` | **92ms** |
| Tailnet `100.106.62.120` | **37ms** |
| Funnel `macbookair.tail7e590c.ts.net` | **843ms** |

590ms of the Funnel figure is the TLS handshake to a relay in Los Angeles. The
server itself answers the music page's queries in 87ms — this is not a slow
application, it is a slow route.

The phone is not on the tailnet, so the two fast addresses are unreachable from
it away from home, and the app falls back to the relay.

**Already fixed** (`dcd267f`): the app ranked the Funnel *above* a direct route
at launch, because it is a `.ts.net` name and stability outranked speed. It now
ranks relays last. That fixes home use, where the LAN address answers. This plan
is about everywhere else.

## What this is not

**Not a fix for a travelling server.** Port forwarding is a rule on *this*
router, for traffic arriving at *this* house. With the laptop at a café, that
router is not yours and forwards nothing. A server that moves needs a fixed
point on the internet — a relay, or a VPS you rent — and that is a different
plan. See *The travelling case* below.

## What you need to do

Four of these are on the router or at a registrar, not in this codebase.

### 1. Pin the Mac's LAN address

The forward points at an IP. If DHCP hands the Mac a different one, the forward
points at nothing.

- Router admin at **http://192.168.1.1**
- Find DHCP reservations / static leases
- Reserve **192.168.1.93** for MAC **3c:a6:f6:12:a7:d1** (Wi-Fi)

Worth doing whatever else you decide — the LAN address is advertised to every
client and a change silently breaks it.

### 2. Forward a high port

Cox blocks inbound 80 and 443 on residential lines. High ports are open.

- Router admin → port forwarding
- **External 8443 → 192.168.1.93 : 443**, TCP
- Do **not** forward 8000. That is `artisan serve`, with no TLS in front of it.

### 3. A name that follows the IP

The public IP is **98.172.105.52** today. Cox will change it without warning,
and a bare IP cannot hold a TLS certificate anyone trusts.

- A domain you own, or a free dynamic-DNS hostname
- An A record pointing at the current IP, updated when it moves (step 5)

### 4. A certificate for that name

The app is a secure context; service workers, offline mode and add-to-home all
require it. The current certificate covers `SoundChex.test` and the Funnel
hostname, neither of which is this.

- Let's Encrypt via DNS-01, because HTTP-01 needs port 80 and Cox blocks it
- `certbot` or `lego` with your DNS provider's plugin, renewing on a timer

### 5. Confirm it works from outside

From cellular, with Wi-Fi off:

    curl -sS -o /dev/null -w '%{http_code} %{time_starttransfer}s\n' \
      https://<your-name>:8443/soundchex.json

Expect `200` and something under 100ms. A timeout means the forward is wrong;
a certificate error means step 4 is incomplete.

## What I build

### 1. Advertise the public address (~half a day)

`NetworkAddresses::detected()` already finds the LAN and tailnet addresses and
appends `app.url`. It gains the public one from config, so every client learns
it from `/soundchex-addresses.json` the way it learns the others.

Nothing else in the client needs to change: the app already races every known
address and now ranks correctly.

### 2. Rank it (~half a day)

A forwarded address is direct, so it is not a relay — but it is only true while
the server is at this house, which makes it less stable than a tailnet address
and more stable than a LAN one. A tier between the two, in both `failover.js`
and the connect screen, which currently share the ranking by duplication rather
than by import.

### 3. Keep the DNS record honest (~1 day)

`soundchex:announce-address`, on the scheduler: reads the current public IP,
compares it against what DNS says, and updates the record when they differ.
Provider-agnostic through a small interface, because a registrar's API is the
part most likely to change.

Logs when it changes. A silent update that turns out to be wrong is worse than
a noisy one that is right.

### 4. Refuse to advertise what does not work (~half a day)

`detected()` returns addresses that were *found*, not addresses that *answer*.
A stale forwarded address in the list costs every client a probe timeout at
launch.

The existing `probe()` already measures each one. Advertise only what responds,
and re-check on the same schedule as step 3.

### 5. Tests (~1 day)

- The public address is advertised when configured and absent when not.
- It ranks below tailnet and above LAN.
- A relay still ranks last, and is still chosen when it is the only answer.
- The announce command is a dry run by default and says what it would change.
- An unreachable address is not advertised.
- No address is advertised twice under two spellings.

**Total: ~3.5 days of code, plus the router and DNS work above.**

## What this costs you

The server becomes reachable from the open internet, which it is not today.
Tailscale's relay is unauthenticated to reach but the tailnet is not; a
forwarded port is reachable by anyone who finds it.

Before opening it:

- **Rate limiting on `/login`.** There is none. A forwarded port makes that a
  credential-stuffing target rather than a theoretical one.
- **The unauthenticated endpoints.** `/soundchex.json` and
  `/soundchex-addresses.json` are deliberately open, and they tell a stranger
  the server's name and every address it answers on — including the LAN and
  tailnet ones. Fine on a tailnet, an information leak on the open internet.
  They should stay open on private routes and close on the public one.
- **Fail2ban or equivalent**, so repeated failures cost something.

I would not open the port before those three are done, and I would rather build
them as part of this plan than after it.

## The travelling case

Out of scope here, and worth writing down so it is not rediscovered.

A forwarded port is tied to this house. The options for a server that moves:

- **Tailscale on the phone** — 10 minutes, 18ms, and the tailnet address already
  works. The cheapest answer by a wide margin, and the reason `100.106.62.120`
  is already advertised.
- **A VPS running WireGuard** as a fixed rendezvous point, with the Mac dialling
  out to it. No external party's *software*, but their hardware, and a monthly
  bill. ~2 days.
- **Accept the relay when away from home.** With the ranking fixed, the app uses
  the fast route whenever one answers and falls back only when none do. This is
  the current behaviour and it is not unreasonable.

## Not started
