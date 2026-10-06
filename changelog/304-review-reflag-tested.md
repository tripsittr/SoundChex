# Reporting an item puts it back in review — asserted

No behaviour change. `sendForReview()` has always set `NeedsReview` and
cleared `reviewed_at`; nothing tested that it does.

That second half is the one worth pinning. An item reviewed once carries a
stamp, and a sweep that skips already-reviewed items would quietly pass over
the very thing somebody just reported. The status alone would not save it.

Seven tests: the status is set, the stamp is cleared, the chosen reason and
note are stored, every reason the apps offer is accepted, an unknown one is
refused, authentication is required, and an item already in review can be
reported again — two complaints are kept rather than collapsed, because an
item wrong in two ways is wrong twice.

Both halves confirmed to fail when removed.

Written because the iOS app is gaining "Mark for review" on films, shows and
episodes, and the guarantee it depends on should not rest on reading the
model.
