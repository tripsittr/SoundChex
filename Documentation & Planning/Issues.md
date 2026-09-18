# Issues — moved to the admin tracker

Issue and to-do tracking for **every SoundChex repo** now lives in the database,
managed from the SoundChex landing site's admin panel — not in this file.

- **Admin panel:** `/admin` on the SoundChex website → **Tracker**. Create, edit,
  filter (by platform, status, type, repo) and publish items.
- **From the console:** `php artisan track:issue` in the **SoundChexWebsite**
  repo — interactive, or with flags (`--platform --type --status --repo --ref
  --publish`).

This repo's historical `Issues.md` was imported into the tracker (keyed on its
original reference ids), and the full backlog is reproducible from
`SoundChexWebsite/database/seeders/data/items.json`. Nothing was lost.

> **Workflow:** log new work as a tracker item *before* starting it — the same
> discipline the Markdown tracker enforced, in the database now.
