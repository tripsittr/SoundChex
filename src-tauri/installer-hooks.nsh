; SPDX-License-Identifier: AGPL-3.0-or-later
; Copyright (C) 2026 SoundChex
;
; Let the server be reached, which is the whole point of it.
;
; Windows blocks unsolicited inbound connections for which no rule exists, and
; nothing here created one. The server bound :8000 correctly, answered on
; loopback, and was unreachable from every other device on the tailnet — which
; looks exactly like a broken server and is not one. Diagnosing that from a
; phone that will not connect is not a reasonable thing to ask of anyone.
;
; Scoped, not opened. The rule accepts connections from two places:
;
;   100.64.0.0/10  Tailscale's address range. This is the CGNAT block the
;                  tailnet uses, so the rule covers the user's own devices
;                  wherever they are, and nothing else can route to it.
;   LocalSubnet    The home network, so a laptop in the same house can reach
;                  the library without Tailscale at all.
;
; Deliberately not the public internet: a machine on a café network must not
; start serving someone's film collection to the room. A user who wants that
; exposure can widen the rule or put a tunnel in front of it, and either is a
; decision for them to take knowingly rather than one taken for them by an
; installer.
;
; The uninstaller takes it away again. A firewall hole outliving the thing it
; was for is how a machine ends up with holes nobody can account for.

!macro NSIS_HOOK_POSTINSTALL
  DetailPrint "Allowing SoundChex Server through the firewall (Tailscale and local network only)…"

  ; Removed first so re-running the installer replaces the rule rather than
  ; stacking another copy of it on every upgrade.
  nsExec::ExecToLog 'netsh advfirewall firewall delete rule name="SoundChex Server"'
  Pop $0

  nsExec::ExecToLog 'netsh advfirewall firewall add rule name="SoundChex Server" dir=in action=allow protocol=TCP localport=8000 remoteip=100.64.0.0/10,LocalSubnet profile=any description="Lets your own devices reach SoundChex Server over Tailscale or the local network. Removed when SoundChex Server is uninstalled."'
  Pop $0

  ${If} $0 != 0
    ; Not fatal. The application installs and runs; it is only unreachable from
    ; other devices, and saying so beats a silent failure the user meets later
    ; as a phone that will not connect.
    DetailPrint "Could not add the firewall rule (netsh returned $0). The server will work on this machine but may be unreachable from other devices."
  ${EndIf}
!macroend

!macro NSIS_HOOK_POSTUNINSTALL
  DetailPrint "Removing the SoundChex Server firewall rule…"
  nsExec::ExecToLog 'netsh advfirewall firewall delete rule name="SoundChex Server"'
  Pop $0
!macroend
