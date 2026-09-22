# 154 — One "Why?", not a column and an action both explaining it

PR #151 (narrower review tables) added a **"Why"** column — an info icon whose
tooltip showed the one-line review reason. But the Metadata tab already had a
**"Why?"** record action — the *same* info-circle icon — that opens a modal with
the full, source-by-source provenance. Two info icons on the same row doing the
same job read as redundant and confusing.

## Changed

- **Removed the "Why" column.** Its one-line reason now rides as the **tooltip
  on the existing "Why?" action**: hover the info icon for the reason, click it
  for the full provenance. One icon, summary on hover and detail on click.
- Dropped the now-unused `IconColumn` import.

No behaviour change beyond the single icon; the reason and the provenance are
both still one gesture away.
