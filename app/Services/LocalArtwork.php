<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Cover art that arrived beside a media file, rather than being fetched.
 *
 * A library copied from Emby, Jellyfin or a plain folder structure brings its
 * artwork with it: a `poster.jpg` in each film's folder, a `folder.jpg` in each
 * album's, or an image named after the file itself. Enrichment would eventually
 * fetch a cover from TMDB or iTunes, but the one the files shipped with is
 * already correct, already local, and costs no API call — so it is used first,
 * exactly as a sidecar subtitle is.
 *
 * Nothing here overwrites: it only fills a cover that is still empty, so an
 * enrichment that already set one wins, and a re-fetch later can still replace
 * it. "Accept what the files brought, keep it re-enrichable" is the same
 * contract the rest of the import follows.
 *
 * ## Why generic names are treated differently from named ones
 *
 * `War Dogs (2016).jpg` beside `War Dogs (2016).mkv` is unambiguous: the name
 * ties the image to exactly one file, so it is safe wherever it sits.
 *
 * `poster.jpg` is only meaningful in a folder dedicated to one title — the Emby
 * convention. In a flat inbox where fifty films and their loose images share
 * one directory, a `poster.jpg` belongs to nothing in particular, and attaching
 * it to whichever film happened to be catalogued next would be worse than
 * ignoring it. So a generic name is accepted only inside a subfolder, never at
 * the top of a watched folder or the storage inbox.
 */
class LocalArtwork
{
    /** Where a copied sidecar cover is written on the public disk. */
    private const DIR = 'artwork/local';

    /**
     * Find a cover beside this item and store it, returning the public-disk
     * path, or null when there is nothing usable.
     *
     * The returned path is the same shape `cover_image_url` holds for art this
     * server extracted itself, so the caller stores it directly.
     */
    public function discover(MediaItem $item): ?string
    {
        if (! (bool) config('library.artwork_sidecars.enabled', true)) {
            return null;
        }

        $mediaPath = $item->absoluteFilePath();

        if ($mediaPath === null || ! is_file($mediaPath)) {
            return null;
        }

        $source = $this->findBeside($mediaPath);

        if ($source === null) {
            return null;
        }

        return $this->store($source);
    }

    /**
     * The best artwork file sitting next to the media file, or null.
     *
     * Named matches first — they are specific to this file — then generic
     * names, and only when the file is in its own folder.
     */
    private function findBeside(string $mediaPath): ?string
    {
        $directory = dirname($mediaPath);
        $base = pathinfo($mediaPath, PATHINFO_FILENAME);
        $extensions = array_map('strtolower', (array) config('library.artwork_sidecars.extensions', ['jpg', 'jpeg', 'png', 'webp']));

        // 1. Named after the media file: "<base>.jpg", "<base>-poster.png",
        //    "<base>.fanart.jpg". Unambiguous, so allowed anywhere.
        $named = $this->namedCandidate($directory, $base, $extensions);

        if ($named !== null) {
            return $named;
        }

        // 2. A generic cover name, but only inside a dedicated folder — never
        //    where many unrelated files share one directory.
        if ($this->isInboxRoot($directory)) {
            return null;
        }

        return $this->genericCandidate($directory, $extensions);
    }

    /**
     * An image whose name is the media file's own, with an optional artwork
     * suffix: "<base>.jpg", "<base>-poster.jpg", "<base>.poster.jpg".
     *
     * @param  list<string>  $extensions
     */
    private function namedCandidate(string $directory, string $base, array $extensions): ?string
    {
        $suffixes = (array) config('library.artwork_sidecars.named_suffixes', ['', '-poster', '.poster', '-cover', '.cover', '-fanart', '.fanart']);

        foreach ($suffixes as $suffix) {
            foreach ($extensions as $extension) {
                $candidate = $directory.DIRECTORY_SEPARATOR.$base.$suffix.'.'.$extension;

                if (is_file($candidate)) {
                    return $candidate;
                }

                // Case-insensitive fallback: Windows is forgiving, Linux is not,
                // and a library copied from a Mac mixes both.
                $found = $this->caseInsensitive($directory, $base.$suffix.'.'.$extension);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * A conventionally named cover in a dedicated folder: poster.jpg,
     * folder.jpg, cover.jpg, and the rest, in priority order.
     *
     * @param  list<string>  $extensions
     */
    private function genericCandidate(string $directory, array $extensions): ?string
    {
        $names = (array) config('library.artwork_sidecars.generic_names', ['poster', 'folder', 'cover', 'front', 'albumart', 'default', 'fanart']);

        foreach ($names as $name) {
            foreach ($extensions as $extension) {
                $found = $this->caseInsensitive($directory, $name.'.'.$extension);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * A file in the directory matching the name regardless of case.
     *
     * `is_file` is case-sensitive on Linux, and these filenames arrive from
     * every platform. Listing the directory once per lookup is acceptable: it
     * runs only when a media file has no cover yet.
     */
    private function caseInsensitive(string $directory, string $filename): ?string
    {
        $exact = $directory.DIRECTORY_SEPARATOR.$filename;

        if (is_file($exact)) {
            return $exact;
        }

        $entries = @scandir($directory);

        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if (strcasecmp($entry, $filename) === 0 && is_file($directory.DIRECTORY_SEPARATOR.$entry)) {
                return $directory.DIRECTORY_SEPARATOR.$entry;
            }
        }

        return null;
    }

    /**
     * Copy the artwork onto the public disk and return its relative path.
     *
     * Hashed by content, so the same poster shared across a season's episodes
     * is written once. A copy rather than a move: the original belongs to the
     * media folder, and the organiser may yet move the whole folder itself.
     */
    private function store(string $source): ?string
    {
        $bytes = @file_get_contents($source);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        // An image, not an HTML error page or a stray text file that happens to
        // carry a .jpg name. getimagesizefromstring returns false for anything
        // that is not a real image.
        if (@getimagesizefromstring($bytes) === false) {
            Log::warning('A sidecar cover was not a readable image', ['path' => $source]);

            return null;
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'jpg';
        $path = self::DIR.'/'.hash('xxh128', $bytes).'.'.$extension;

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $bytes);
        }

        return $path;
    }

    /**
     * Whether this directory is the top of a watched folder or the storage
     * inbox, where a generic cover name belongs to nothing in particular.
     *
     * Compared by resolved path, so a trailing slash or a `..` cannot slip a
     * subfolder past the check.
     */
    private function isInboxRoot(string $directory): bool
    {
        $resolved = realpath($directory);

        if ($resolved === false) {
            return true; // Can't prove it is safe, so treat it as the ambiguous case.
        }

        foreach ($this->inboxRoots() as $root) {
            if ($resolved === $root) {
                return true;
            }
        }

        return false;
    }

    /**
     * The directories that are inbox tops, resolved.
     *
     * @return list<string>
     */
    private function inboxRoots(): array
    {
        $roots = (array) config('library.watch_folders', []);
        $roots[] = Storage::path('media/unsorted');
        $roots[] = Storage::path('media');

        return array_values(array_filter(array_map(
            fn (string $path): ?string => realpath($path) ?: null,
            $roots,
        )));
    }
}
