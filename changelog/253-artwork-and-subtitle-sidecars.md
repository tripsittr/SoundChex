# Accept the artwork and captions that arrive beside a file

A library copied from Emby, Jellyfin or a plain folder structure brings more
than the media: a `poster.jpg` in each film's folder, a `folder.jpg` in each
album's, an image named after the file, and `.srt` captions beside each video.
The scanner took the media and left the rest. A film then showed a placeholder
until enrichment fetched a cover from TMDB — which, on a machine with only a
TMDB key and no others, often never happened for music at all.

Now the cover the files shipped with is used first. It is already correct, it is
local, and it costs no API call. Nothing is overwritten: a cover already set
stays, and a later re-fetch can still replace a local one, so the usual "accept
what arrived, keep it re-enrichable" contract holds.

## Artwork

`LocalArtwork` looks beside a newly catalogued file for an image and, finding
one, copies it onto the public disk and sets it as the cover — the same shape
and the same place as art the server extracts itself.

The one real hazard is attaching a generic `poster.jpg` to the wrong title, so
names are treated in two classes:

- **Named after the file** — `War Dogs (2016).jpg`, `War Dogs (2016)-poster.png`
  — are matched anywhere, because the name ties the image to exactly one file.
- **Generic** — `poster.jpg`, `folder.jpg`, `cover.jpg` and the rest — are
  matched only inside a dedicated subfolder, the Emby/Jellyfin convention. In a
  flat inbox where many films and loose images share one directory, a
  `poster.jpg` belongs to nothing, and is left alone.

Checked by disabling that guard, which lets a loose inbox poster be claimed by a
random film and fails the test that exists to prevent it.

A file that only *looks* like an image — a mislabelled `.jpg` that is really
text or an HTML error page — is rejected by reading its header, not its
extension.

## Subtitles

Sidecar `.srt`/`.vtt`/`.ass` import already existed, but only fired for films.
Television episodes carry captions beside them too, and were getting none. The
dispatch now covers both; the importer already read the item's own path, so it
needed no change.

## Still to do

- **`.nfo` files are not parsed.** Emby and Kodi write a per-item XML with title,
  year, plot and provider ids. Reading it would seed enrichment with better
  data than a filename, but it is a larger piece — an XML parser and a mapping
  onto the metadata columns — and belongs in its own change.
- **Artwork other than the cover** — fanart, banners, disc art — is discovered
  (fanart.jpg is in the generic list) but only ever used as the cover. There is
  nowhere else to put it yet.

## Config

`library.artwork_sidecars` controls it: an `enabled` flag
(`LIBRARY_ARTWORK_SIDECARS`), the extensions and generic names recognised, and
the suffixes allowed after a file's own name. On by default.
