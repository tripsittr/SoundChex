# The synopsis, which was already there

S-412.

## What the issue assumed, and what was true

The issue said there was "NO overview/plot/synopsis column anywhere" and that
"TMDB returns an overview and the enrichment pipeline discards it". It called
for a migration, an enrichment change, a backfill, an API field and a client
change.

Only the last two were needed. The overview is stored on `media_items.notes`
— the admin already labels that field **Overview**, with the helper text
"Left empty, TMDB's synopsis is used", and `Movie\Tmdb::writeOverview()` has
been filling it all along. Both films in this library have one.

So nothing was discarded and nothing needed backfilling. The data simply
never left the server.

## Where it is sent

On `/api/v1/items/{id}/details`, beside cast and crew — not on the catalogue
row. A synopsis is a paragraph, and the library payload is mirrored in full by
every device: thousands of paragraphs carried so that one can be shown at a
time. The detail page already calls this endpoint, so it costs nothing extra.

A test asserts the synopsis does **not** appear in `/api/v1/library`, so that
reasoning cannot quietly be undone later.

## On the client

iOS shows it above the facts table, since the synopsis is what someone reads
to decide what to watch and the runtime is what they check afterwards.

Because the server prefers the owner's own words over TMDB's, this is not
always a scraped blurb — if someone wrote their own description in the admin,
that is what the phone shows.

## Still open

Tapping a cast member does nothing: there is no person page. Noted on the
issue, not built here.
