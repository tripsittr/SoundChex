# 172 — A stable address for OAuth redirect URIs

**Merged** 2026-09-22 · **Issues** S-322

Connecting Spotify from the Playlist Porter plugin failed with `redirect_uri:
Not matching configuration`. This adds a configured address that does not move
with the request, which is what an OAuth redirect URI needs.

## What changed

### `app.configured_url`

`config/app.php` gains `configured_url`, set from the same `APP_URL` as
`app.url`. The `SetAppUrl` middleware deliberately leaves it alone.

That middleware rebases `app.url` and the URL generator onto whichever address
a request arrived on, because this server answers on several at once — a
tailnet host on `:8443`, a public Funnel host on `:443`, loopback `:8000`
behind the desktop shell. That is right for assets and redirects and wrong for
an OAuth redirect URI: a service matches it against the single URI registered
for the app, character for character. Built from the live request, the same
install sent `http://127.0.0.1:8000/…`, `https://…ts.net/…` or
`https://…ts.net:8443/…` depending on how the page was reached, and every one
but the registered address was rejected.

Anything needing the server's own stable address now reads
`config('app.configured_url')`. Unlike calling `env('APP_URL')` at runtime, it
still holds if the config is ever cached.

The plugin side of the fix (one `OAuthRedirect` helper feeding the authorize
step, the token exchange and the URI shown for copying) is in
`soundchex-playlist-porter` v1.0.4.

## Worth knowing

- No migration, no data rewritten.
- `APP_URL` now decides the OAuth redirect URI. `server:detect-address` already
  keeps it pointed at a real address; if it changes, the redirect URI registered
  at Spotify has to change with it, or the plugin's per-source override has to
  be set.
- Nothing else reads `configured_url` yet — `app.url` keeps its request-following
  behaviour everywhere it is used today.

## Still wrong

- **The full Spotify round-trip is still unverified.** The redirect URI is now
  provably identical across access paths, but no real authorization has been
  completed; that needs the URI registered in the Spotify app dashboard and a
  human to click through consent.
- The two Spotify credential paths remain unconsolidated: the core Integrations
  page's `spotify_client_secret` (metadata enrichment) is still separate from
  the plugin's `spotify.client_id`/`spotify.client_secret`, which has already
  confused the operator once.
