# 133 — Title Case example plugin, and the title filter for every type (S-264)

A second worked-example plugin, and a small platform fix it needed: the
`metadata.title` filter now runs for all media types, not only music.

## The plugin

**Title Case** (`plugins/examples/title-case`) rewrites item titles to a case
style the admin chooses — Title Case, Sentence case, UPPERCASE, lowercase, or
Start Case. It is the worked example of a *configurable* behaviour plugin: one
`metadata.title` filter, one setting (`title_case_style`) it reads to decide how
to behave. Title Case is the interesting style — it capitalises the words that
matter and leaves minor joining words (a, of, the) lowercase unless they lead.

The case rules live in a separate `TitleCaser` so they are testable on their own,
the pattern an author should copy: keep the logic out of the plugin shell.

## The platform fix

The `metadata.title` filter was only applied during the music-only title-tidy
step, so a filter plugin could never touch a film or book title. The enrich job
now applies the filter for **every** media type — the music artist-strip stays
music-only, but the plugin filter runs after it whatever the type. Inert with no
plugin registered, so a plugin-less install is unchanged.

## Tests

- `TitleCasePluginTest` — each style transforms correctly (Title Case keeps minor
  words low but capitalises the leading word); defaults to Title Case with no
  setting; is inert until the plugin is enabled.
- Full suite: 782 passed.

## Related

Logged as follow-ups (#279–282): shipping TitleTidier, the cover pipeline, the
webhook destinations, and the built-in metadata sources as plugins — turning the
app's own hardcoded opinions into the platform's first real plugins. Two need new
registry seams (cover sources, notification targets); two use seams that already
exist.
