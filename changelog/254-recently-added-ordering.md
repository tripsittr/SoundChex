# "Recently added" was not ordered by anything

S-451.

## What it was

The clients built that row from the first twenty items in the catalogue
payload. `/api/v1/library` applies **no ordering at all** —
`$this->visible()->get()` — so the row showed whatever order SQLite happened
to return. Stable, arbitrary, and unchanged when a scan added something, which
is the one thing it existed to show.

## Why `updated_at` was not the answer

The payload already carried `updated_at`, and sorting on it would have been
wrong in a way that is worse than arbitrary: an enrichment pass moves it on
every item it touches, so the row would fill with whatever the scanner last
looked at rather than with what is new.

The catalogue now carries `added_at` — `created_at`, when the row entered the
library — which is a different question and the one the row is asking.

## Tested

Two tests. One that the field is present and is the creation time; one that
editing an item afterwards moves `updated_at` and leaves `added_at` alone,
which is the distinction the whole change rests on.

Writing the second one surfaced a trap worth noting: passing `created_at` to
`create()` does nothing, because Eloquent sets both timestamps on insert. The
backdating has to happen in a second write.
