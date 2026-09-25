# Send for review

S-398 (server) and S-399 (desktop). The iOS half is S-400, in its own repo.

"This item is wrong, and here is why" — from the track menu in the app,
landing on the admin review screen.

## Confirm first, then ask why

Reporting hides the item from the library until an admin clears it. That is a
large consequence for one tap in a menu whose neighbours are "Play next" and
"Add to queue", so it takes two steps: a confirmation that says plainly what
will happen, and only then the reason.

The confirmation is a plain `confirm()` on purpose. The answer to "are you
sure" should be the most boring, least accidentally-dismissable control
available, and the consequence is the first sentence rather than something
buried after the question:

> It will be hidden from your library until an admin has looked at it. Nothing
> is deleted — it comes back once the review is cleared.

Five reasons — wrong metadata, file problem, wrong cover, duplicate, something
else — each with a line saying what it covers, plus an optional note. The list
is deliberately short: twenty reasons is a list nobody reads, and the note
carries whatever the five cannot.

## A table, not a column

Reports live in `media_item_reports` rather than as columns on the item. Two
people on two profiles can hit the same broken file, and the second report
should add to the first — an admin wants to know two people noticed. A report
also has a life of its own: raised, looked at, resolved or dismissed, which
does not fit a nullable column.

Resolved and dismissed are kept apart rather than collapsed into one "closed",
so the panel can say "3 reports, 2 were nothing" instead of flattening that.

## Why the endpoint takes an id

`POST /api/v1/items/{id}/review` resolves the item explicitly rather than
through route-model binding, because the binding is scoped and a reported item
is hidden by definition (S-396). With a binding, the first report would work
and every one after it would 404 — silencing exactly the second voice this is
built to hear.

## Admin

Reported items appear on the existing Needs Review screen, which its own
comment already described as "where future review types land". A "Reported"
column shows the reason with the note as its description, not toggleable off
by default: a person took the trouble to report this, which outranks anything
the scanner guessed.

Marking an item reviewed now also closes its open reports. Without that the
item returns to the library while its report stays open, so the screen keeps
showing something already dealt with — and the next query for open reports
pulls it straight back.

## Testing

9 PHP tests and 9 JS tests, 1,090 and 127 passing overall.

The PHP ones pin what the confirmation promises: that reporting really does
hide the item, that a second person can still report an already-hidden one,
and that clearing the review puts it back. The JS ones pin the confirm — that
it happens before anything else, that it names the consequence, and that
declining sends nothing.

## Known gaps

- Nothing tells the reporter what came of their report.
- An admin cannot yet dismiss a single report from the table; marking the item
  reviewed closes all of them together.
- The reason and note are not shown in the item's own detail page, only in the
  review table.
