# 168 — Fix: plugin API routes need the `api` middleware for model binding

Moving the porting engine into its plugin (S-315) registered the plugin's API
routes with `middleware: ['auth:sanctum']` — but not Laravel's `api` middleware
group. The `api` group carries `SubstituteBindings`, the middleware that resolves
route-model parameters like `{import}`. Without it, every route-model-bound
endpoint (an import's `show` and `resolve`) returned a bare **404** even though
the URL matched — the porting resolve endpoint 404'd.

## Fixed

- The Playlist Porter plugin now registers its routes with
  `middleware: ['api', 'auth:sanctum']`, so route-model binding works and the
  `show`/`resolve` endpoints respond correctly.
- The `Registry::routes()` seam documents this explicitly, so no plugin author
  hits the same trap: an authed API plugin needs the `api` group, not just the
  guard.
- Removed a stray probe test that was committed by accident during the move.

## Testing

- The porting suites pass deterministically (18) across repeated runs — the
  earlier intermittent 404 is gone.
