<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Support;

/**
 * What this build of the server and desktop app is, in one place (S-402).
 *
 * SoundChex names every minor release. The phone uses **music** terms —
 * Overture, Crescendo, Coda — and the desktop and server use **film** terms,
 * because this is the half that serves films as well as music. Same scheme,
 * two vocabularies, so "Reprise" is always the phone and "Rough Cut" is
 * always the desktop and nobody has to ask which they are running.
 *
 * The mirror of this on iOS is `Sources/DesignSystem/AppRelease.swift`.
 *
 * ## Why this exists at all
 *
 * Until now the desktop app had no real version: `tauri.conf.json` sat at
 * `0.1.0` and the repository had no tags, so a running build could not name
 * itself. That is a licence problem, not only an untidiness — AGPL §13 asks a
 * network-hosted build to offer *its own* corresponding source, and a link to
 * `main` is not that (S-401). A version plus the commit it was built from is
 * what makes the offer real.
 */
class AppRelease
{
    /**
     * The version this build is. Read from `config/app.php`, which takes it
     * from the `APP_VERSION` env var written at build time — so a packaged
     * app carries its real version and a dev checkout says so.
     */
    public static function version(): string
    {
        return (string) config('app.version', '0.0.0-dev');
    }

    /**
     * The commit this build came from, short form, or null in a checkout that
     * was not built through the release script.
     *
     * The version alone is not enough to satisfy §13, because builds ship from
     * `main` between releases: two servers can both say 0.1.0 and be running
     * different code. The commit is what makes "the source of *this* build"
     * a question with one answer.
     */
    public static function commit(): ?string
    {
        $commit = config('app.commit');

        return filled($commit) ? (string) $commit : null;
    }

    /**
     * The minor → name map. A new minor adds a row here and to
     * `docs/Versioning.md`; a name that lives only in a changelog is not
     * shipped, which is the trap the phone hit (S-380).
     *
     * Film vocabulary, roughly in the order a film is made: what you shoot,
     * how you cut it, what you screen. `Feature` is held back for 1.0 the way
     * the phone holds `Encore`.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        '0.1' => 'Screening',
        '0.2' => 'Rough Cut',
        '1.0' => 'Feature',
    ];

    /** The release name for this build, or null for an un-named minor. */
    public static function name(): ?string
    {
        return self::nameFor(self::version());
    }

    /** The name for a full version string like "0.2.1" — keyed on its minor. */
    public static function nameFor(string $version): ?string
    {
        $parts = explode('.', ltrim($version, 'v'));

        if (count($parts) < 2) {
            return null;
        }

        return self::NAMES[$parts[0].'.'.$parts[1]] ?? null;
    }

    /** "0.2.0 “Rough Cut”", or just the number when the minor has no name. */
    public static function display(): string
    {
        $name = self::name();

        return $name === null
            ? self::version()
            : self::version().' “'.$name.'”';
    }

    /**
     * Where the source of *this* build lives.
     *
     * Pinned to the commit when there is one, so it is the corresponding
     * source rather than whatever `main` happens to be today. Falls back to
     * the repository root for a checkout with no stamp.
     *
     * An operator running a modified build must point this at their own fork
     * — that is the actual §13 obligation, and the reason this is a setting
     * rather than a constant.
     */
    public static function sourceUrl(): string
    {
        $repository = rtrim((string) config('app.source_url', 'https://github.com/tripsittr/SoundChex'), '/');
        $commit = self::commit();

        return $commit === null
            ? $repository
            : $repository.'/tree/'.$commit;
    }

    /**
     * Whether this build is running modified source.
     *
     * Set at build time when the working tree was dirty or the source URL has
     * been pointed elsewhere. An operator who has changed the code owes their
     * users *their* source, not ours, and the About page says so plainly
     * rather than leaving it to be inferred.
     */
    public static function isModified(): bool
    {
        return (bool) config('app.source_modified', false);
    }
}
