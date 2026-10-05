# A reachability test from the server proves nothing

Curling the server's own address — including its tailnet address — says only
that the socket is bound. Loopback is not filtered, so the request never meets
the firewall that every other device does.

This shipped a wrong answer: the server was reported reachable at
`http://100.103.136.32:8000` on the strength of a local `HTTP 200`, and no
device on the tailnet could reach it, because Windows blocks unsolicited
inbound with no rule and nothing had created one.

To test reachability, test from somewhere else, or test the thing that stands in
for somewhere else:

- `Get-NetFirewallRule` / `Get-NetFirewallPortFilter` for an inbound allow on
  the port. No rule means no inbound, whatever a local curl says.
- A `tailscale serve` front end is the exception and the better answer:
  `tailscaled` is already privileged, accepts the connection itself and forwards
  to loopback, so nothing needs opening at all.

And a per-user install cannot fix it. The NSIS installer runs unelevated when
the install is per-user (`HKCU`, a user-writable directory), so
`netsh advfirewall` returns "requires elevation" and, in silent mode, fails
invisibly.
