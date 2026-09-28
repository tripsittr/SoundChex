# The desktop client's own pages

S-441.

## Most of this was already done

The Tauri client is a web view in a native window, so nearly everything a
person sees is the server's web UI — which S-442 covered. What belongs to the
client itself is small:

- **The native menus** (Refresh, Reconnect, Library, Admin) carry real titles
  and accelerators. macOS exposes those to VoiceOver on its own; there is
  nothing to add.
- **The connect page** already had a `<form>`, `<label for>` on both inputs,
  an ordered heading, and a `role="status"` live region. Better shape than
  most of the app was in.

## What was missing

**The server page announced nothing.** `public/tauri/server.html` — the panel
that starts and watches the bundled runtime — had no `aria-live`, no
`role="status"`, no `aria-*` of any kind. Its status line changes constantly
("Checking services…", "Started. Waiting for it to answer…", "Could not start
the bundled runtime") and said all of it to nobody. It is now a live region.

**Errors waited their turn.** Both pages used `polite` for everything, so
"Could not reach that server" queued behind whatever was already being read.
Errors are now `assertive` and ordinary progress stays `polite` — the
distinction is the whole point of having two.

**The service dots were announced.** Three coloured dots marking running or
stopped, each sitting beside a text label that already says the same thing.
Hidden from the screen reader rather than read twice.

## Worth stating

These pages are easy to forget. They are their own origin, plain HTML shipped
as-is with no build step, and they are the *first* thing anyone meets — before
any of the accessible UI behind them exists.
