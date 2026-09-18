{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- The "Downloaded" filter toggle (S-115/S-116). `downloaded-filter.js` finds
     it by `[data-filter-downloaded]` and dims the rows that are not downloaded,
     reading IndexedDB — so the same control works on any surface with
     `li[data-long-press-menu]` rows (songs, albums, artists, playlists). This
     partial keeps the markup in one place across those surfaces. --}}
<label class="browse-filters__toggle">
    <input type="checkbox"
           data-filter-downloaded
           class="browse-filters__checkbox">
    Downloaded
</label>
