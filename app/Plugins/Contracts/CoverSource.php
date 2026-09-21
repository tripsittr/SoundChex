<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Contracts;

/**
 * A source of album cover art a plugin can contribute (S-264, #280).
 *
 * The built-in fetcher tries iTunes then Deezer; a plugin cover source is tried
 * after those, as a further fallback, so a plugin can reach an artwork provider
 * the core does not — Cover Art Archive, Fanart.tv, a private collection. It
 * returns a URL to a verified cover for the album, or null when it has none;
 * the fetcher downloads and stores whatever it returns.
 *
 * Cover sources are ordered by `priority()` (lower first) among themselves, and
 * as a group they run only when the built-in sources found nothing.
 */
interface CoverSource
{
    /** A human-readable name, for logs and the admin UI. */
    public function name(): string;

    /** Lower runs earlier among plugin cover sources. */
    public function priority(): int;

    /**
     * A verified cover-image URL for the album, or null.
     *
     * Must validate the result against the artist rather than return the first
     * loose match — a wrong cover is worse than none. Network failures should
     * return null, not throw; the fetcher moves on to the next source.
     */
    public function coverUrlFor(string $artist, ?string $album): ?string;
}
