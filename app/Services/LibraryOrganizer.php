<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Moves catalogued files into an organized Artist/Album/## Track tree.
 *
 * This is the only part of the app that relocates a user's files, so it errs
 * heavily toward doing nothing: an item with no resolved artist or album is
 * left exactly where it is rather than filed under "Unknown".
 */
class LibraryOrganizer
{
    /**
     * Whether an item is ready to be filed.
     *
     * Enrichment has to have produced a real artist and album first — moving a
     * file before then would scatter the library under placeholder folders and
     * then need moving again.
     */
    public function canOrganize(MediaItem $item): bool
    {
        if (! $item->hasReadableFile()) {
            return false;
        }

        // Renaming acts on resolved metadata, so a wrong match doesn't just
        // mislabel a row — it refiles the user's file under the wrong author.
        // Anything reached by loosening the match stays where it is.
        if (! $this->isConfidentEnoughToMove($item)) {
            return false;
        }

        // Each type needs whatever its folder structure is built from. Without
        // that, the file would land under a placeholder and need moving again.
        return match ($item->type) {
            // Album is optional: plenty of tracks are singles that never
            // appeared on one, and holding them in the inbox forever would
            // mean they never got filed at all.
            MediaItemType::Music => filled($item->musicMetadata?->artist),

            // A film only needs a year to be filed unambiguously.
            MediaItemType::Movie => filled($item->movieMetadata?->release_year),

            // An episode needs its numbering, the same way a film needs its
            // year. A series row has no file of its own to move.
            MediaItemType::Show => filled($item->showMetadata?->season_number)
                && filled($item->showMetadata?->episode_number),

            MediaItemType::Book => filled($item->bookMetadata?->author),
        };
    }

    /**
     * Whether this item's metadata is trustworthy enough to rename its file.
     *
     * Music is exempt: its artist and album come from the file's own embedded
     * tags, which are authoritative about the file regardless of what any
     * online source thinks. Everything else is filed from an API match, so it
     * has to have matched exactly.
     */
    private function isConfidentEnoughToMove(MediaItem $item): bool
    {
        if ($item->type === MediaItemType::Music) {
            return true;
        }

        return $item->match_confidence?->allowsFileMove() ?? false;
    }

    /**
     * Whether this item is already sitting at its own target path.
     *
     * `organize()` returns null both for "already filed" and for "tried and
     * failed", which are opposite outcomes. Callers that report failures need
     * to tell them apart, so the benign case is answerable on its own.
     */
    public function isAlreadyFiled(MediaItem $item): bool
    {
        $source = $item->absoluteFilePath();
        $target = $this->targetPath($item);

        if ($source === null || $target === null) {
            return false;
        }

        $absoluteTarget = Storage::path($target);

        if ($source === $absoluteTarget) {
            return true;
        }

        // A distinct recording that already took a suffix because its ideal
        // name was occupied is settled. Without this it asks to be moved on
        // every run, gets suffixed again, and never stops being "pending".
        return dirname($source) === dirname($absoluteTarget)
            && $this->isSuffixedForm($source, $absoluteTarget)
            && file_exists($absoluteTarget);
    }

    /**
     * Whether $source is the "Name (2).ext" form of $target's "Name.ext".
     */
    private function isSuffixedForm(string $source, string $target): bool
    {
        $base = pathinfo($target, PATHINFO_FILENAME);
        $extension = pathinfo($target, PATHINFO_EXTENSION);
        $actual = pathinfo($source, PATHINFO_FILENAME);

        return strcasecmp(pathinfo($source, PATHINFO_EXTENSION), $extension) === 0
            && preg_match('/^' . preg_quote($base, '/') . ' \(\d+\)$/', $actual) === 1;
    }

    /**
     * Files an item into the library tree.
     *
     * @return string|null The new relative path, or null when nothing moved.
     */
    public function organize(MediaItem $item, bool $dryRun = false): ?string
    {
        $storedSource = $item->file_path;

        if (! $this->canOrganize($item)) {
            return null;
        }

        $source = $item->absoluteFilePath();
        $target = $this->targetPath($item);

        if ($source === null || $target === null) {
            return null;
        }

        $absoluteTarget = Storage::path($target);

        // Already filed — nothing to do.
        if ($source === $absoluteTarget) {
            return null;
        }

        if ($dryRun) {
            return $target;
        }

        $directory = dirname($absoluteTarget);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            // A permissions problem or a full disk, and previously silent: the
            // item simply stayed in the inbox with nothing saying why.
            Log::warning('Could not create a library folder', [
                'item' => $item->id,
                'directory' => $directory,
            ]);

            return null;
        }

        // A file already sitting at the target may be this exact recording,
        // catalogued twice. Suffixing it would keep a byte-identical copy
        // forever and re-offer it for filing on every run, so an identical
        // file is treated as "already filed here" instead.
        if ($this->isSameFile($source, $absoluteTarget)) {
            return $this->adoptExisting($item, $source, $absoluteTarget);
        }

        // Never overwrite: a same-named file with different content really is a
        // different recording, so it gets a suffix rather than clobbering what's
        // already filed.
        $absoluteTarget = $this->uniquePath($absoluteTarget);

        if (! @rename($source, $absoluteTarget)) {
            // rename() fails across filesystems (an external drive, say), so
            // fall back to copy-then-delete.
            if (! @copy($source, $absoluteTarget)) {
                Log::error('Could not file a media file', [
                    'item' => $item->id,
                    'from' => $source,
                    'to' => $absoluteTarget,
                    'reason' => 'rename and copy both failed',
                ]);

                return null;
            }

            // Verify the copy landed before removing the original — a partial
            // copy plus an eager delete would lose the file outright.
            if (filesize($absoluteTarget) !== filesize($source)) {
                @unlink($absoluteTarget);

                // The partial copy is removed and the original kept, which is
                // the right call — but a truncated copy usually means a full
                // disk, and that will happen again on the next file.
                Log::error('A filed copy was short and was discarded', [
                    'item' => $item->id,
                    'from' => $source,
                    'expected' => filesize($source),
                    'copied' => filesize($absoluteTarget),
                ]);

                return null;
            }

            @unlink($source);
        }

        $relative = $this->toRelative($absoluteTarget);

        $item->file_path = $relative;
        $item->saveQuietly();

        $this->repointPeersForSharedSource($item, $storedSource, $relative);

        $this->pruneEmptyParents(dirname($source));

        return $relative;
    }

    /**
     * Builds the destination path for an item.
     *
     *   Music   library/Music/Artist/Album/## Track.ext
     *   Movies  library/Movies/Title (Year)/Title (Year).ext
     *   TV      library/TV/Show/Season 01/Show - S01E02.ext
     *   Books   library/Books/Author/Title.ext
     */
    public function targetPath(MediaItem $item): ?string
    {
        $source = $item->absoluteFilePath();

        if ($source === null) {
            return null;
        }

        $extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $segments = $this->segmentsFor($item, $extension);

        if ($segments === null) {
            return null;
        }

        $typeFolder = config('library.type_folders.' . $item->type->value)
            ?? ucfirst($item->type->value);

        return implode('/', [
            trim((string) config('library.library_root', 'media/library'), '/'),
            $typeFolder,
            ...$segments,
        ]);
    }

    /**
     * The per-type folder and filename parts, or null when the item lacks the
     * metadata its structure depends on.
     *
     * @return array<int, string>|null
     */
    private function segmentsFor(MediaItem $item, string $extension): ?array
    {
        $title = $this->segment($item->title) ?? 'Untitled';
        $suffix = $extension !== '' ? '.' . $extension : '';

        return match ($item->type) {
            MediaItemType::Music => $this->musicSegments($item, $title, $suffix),
            MediaItemType::Movie => $this->movieSegments($item, $title, $suffix),
            MediaItemType::Show  => $this->showSegments($item, $title, $suffix),
            MediaItemType::Book  => $this->bookSegments($item, $title, $suffix),
        };
    }

    /**
     * @return array<int, string>|null
     */
    private function musicSegments(MediaItem $item, string $title, string $suffix): ?array
    {
        $meta = $item->musicMetadata;

        $artist = $this->segment($meta?->artist);

        if ($artist === null) {
            return null;
        }

        // Tracks with no album are singles; grouping them under one folder per
        // artist keeps them filed rather than stranded in the inbox.
        $album = $this->segment($meta?->album) ?? 'Singles';

        // A zero-padded track number keeps an album in playing order when the
        // folder is browsed or copied to a device.
        $track = $meta?->track_number
            ? str_pad((string) $meta->track_number, 2, '0', STR_PAD_LEFT) . ' '
            : '';

        // The artist is already the folder, so repeating it in the filename
        // gives "Imagine Dragons/Night Visions/09 Gold - Imagine Dragons.mp3".
        // Worse, the scanner reads a filename back as a title when cataloguing,
        // so a name written that way becomes a title carrying its own artist —
        // which is how 4,243 tracks came to print their artist twice.
        //
        // Stripped here as well as fixed at the source, because a title that
        // arrives in that shape from anywhere should not be written to disk in
        // it.
        return [$artist, $album, $track . $this->withoutArtist($title, $meta?->artist) . $suffix];
    }

    /**
     * A track title with a trailing " - Artist" removed.
     *
     * Only this track's own artist, and only at the end. "Gold - Sia" on an
     * Imagine Dragons record is part of the title; so is "Sing - Sing - Sing".
     */
    private function withoutArtist(string $title, ?string $artist): string
    {
        $artist = trim((string) $artist);

        if ($artist === '') {
            return $title;
        }

        foreach ([' - ', ' — ', ' – '] as $separator) {
            $suffix = $separator . $artist;

            if (str_ends_with($title, $suffix)) {
                $stripped = trim(mb_substr($title, 0, -mb_strlen($suffix)));

                // Never to nothing: a track actually called "- Artist" keeps
                // the name it has rather than becoming an empty filename.
                return $stripped === '' ? $title : $stripped;
            }
        }

        return $title;
    }

    /**
     * @return array<int, string>|null
     */
    private function movieSegments(MediaItem $item, string $title, string $suffix): ?array
    {
        $year = $item->movieMetadata?->release_year;

        if (blank($year)) {
            return null;
        }

        // "Title (Year)" is the convention Plex, Jellyfin, and Emby all expect,
        // so the same tree stays readable by other tools.
        $folder = $this->segment($item->title . ' (' . $year . ')') ?? $title;

        return [$folder, $folder . $suffix];
    }

    /**
     * Episodes file into season folders; a series row has no file of its own.
     *
     * Without a season and episode number every episode of a show resolved to
     * the same path and collided, so the second became "Show (2).mkv" —
     * numbered by import order rather than by episode. A file that cannot be
     * placed stays in the inbox, matching how a film behaves without a year.
     *
     * "Show - S01E02" is what Plex, Jellyfin and Emby all expect, so the tree
     * stays readable by other tools.
     *
     * @return array<int, string>|null
     */
    private function showSegments(MediaItem $item, string $title, string $suffix): ?array
    {
        $meta = $item->showMetadata;

        $season = $meta?->season_number;
        $episode = $meta?->episode_number;

        if ($season === null || $episode === null) {
            return null;
        }

        // The series name comes from the parent when there is one: an episode
        // title is "Brat", and filing that as a folder would scatter a series
        // across one folder per episode.
        $series = $this->segment($item->series?->title ?? $item->title);

        if ($series === null) {
            return null;
        }

        // Zero-padded so a file browser sorts S01E02 before S01E10. Unpadded
        // numbers sort lexically and interleave the season.
        $code = sprintf('S%02dE%02d', $season, $episode);

        $name = $series . ' - ' . $code;

        // The episode title is appended when known, since "S01E02" alone tells
        // you nothing when browsing the folder directly.
        $episodeTitle = $this->segment($meta?->episode_title);

        if ($episodeTitle !== null && $episodeTitle !== $series) {
            $name .= ' - ' . $episodeTitle;
        }

        return [
            $series,
            sprintf('Season %02d', $season),
            $name . $suffix,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function bookSegments(MediaItem $item, string $title, string $suffix): ?array
    {
        $author = $this->segment($item->bookMetadata?->author);

        if ($author === null) {
            return null;
        }

        return [$author, $title . $suffix];
    }

    /**
     * Makes a metadata value safe as a single path segment.
     *
     * Separators and reserved characters would create stray nesting or fail
     * outright on some filesystems; a leading dot would hide the folder.
     */
    private function segment(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $clean = preg_replace('/[\/\\\\:*?"<>|]+/', '-', $value) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');
        $clean = ltrim($clean, '.');
        // Trailing dots and spaces are invalid on Windows and confuse rsync.
        $clean = rtrim($clean, ". \t");

        if ($clean === '') {
            return null;
        }

        // 120 keeps the full path clear of the 255-byte limit most
        // filesystems impose per component.
        return mb_substr($clean, 0, 120);
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/') . substr($path, 1);
        }

        return $path;
    }

    /**
     * Appends a counter until the path is free.
     */
    /**
     * Whether two paths hold byte-identical content.
     *
     * This decides whether a file gets deleted, so a size match alone isn't
     * enough — two different recordings can share a byte count. The hash only
     * runs when the sizes already agree, which keeps it off the common path.
     */
    private function isSameFile(string $a, string $b): bool
    {
        if (! is_file($a) || ! is_file($b)) {
            return false;
        }

        $sizeA = @filesize($a);
        $sizeB = @filesize($b);

        if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) {
            return false;
        }

        $hashA = @hash_file('xxh128', $a);
        $hashB = @hash_file('xxh128', $b);

        return $hashA !== false && $hashA === $hashB;
    }

    /**
     * Points an item at the copy already filed at its target and drops the
     * redundant one.
     *
     * Reached only when the two files are byte-identical, so nothing unique is
     * lost — the item ends up describing the file that was already there.
     */
    private function adoptExisting(MediaItem $item, string $source, string $absoluteTarget): string
    {
        $storedSource = $item->file_path;
        $relative = $this->toRelative($absoluteTarget);

        $item->file_path = $relative;
        $item->saveQuietly();

        $this->repointPeersForSharedSource($item, $storedSource, $relative);

        @unlink($source);

        $this->pruneEmptyParents(dirname($source));

        return $relative;
    }

    private function uniquePath(string $path): string
    {
        if (! file_exists($path)) {
            return $path;
        }

        $directory = dirname($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = pathinfo($path, PATHINFO_FILENAME);

        for ($i = 2; $i < 1000; $i++) {
            $candidate = "{$directory}/{$base} ({$i}).{$extension}";

            if (! file_exists($candidate)) {
                return $candidate;
            }
        }

        return $path;
    }

    /**
     * Repoints rows that still reference the same original source path.
     *
     * Organizing one row moves the underlying file once. If another row pointed
     * at that same source path (common after duplicate scans/imports), it would
     * otherwise keep the now-missing path and become unplayable.
     */
    private function repointPeersForSharedSource(MediaItem $item, ?string $from, string $to): void
    {
        if (blank($from) || $from === $to) {
            return;
        }

        MediaItem::query()
            ->where('id', '!=', $item->id)
            ->where('file_path', $from)
            ->update(['file_path' => $to]);
    }

    /**
     * Converts an absolute path back to one relative to the storage disk, so
     * the stored value keeps working through Storage::.
     */
    private function toRelative(string $absolute): string
    {
        $root = realpath(Storage::path(''));
        $real = realpath($absolute) ?: $absolute;

        if ($root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);

            // Forward slashes, whatever the platform. `realpath()` hands back
            // `media\library\…` on Windows, and that value is then compared
            // against other stored paths and passed to `Storage::` — which is
            // how the scanner came to catalogue one film nine times, once per
            // scan, because the two spellings never matched (S-98).
            return str_replace('\\', '/', $relative);
        }

        return $real;
    }

    /**
     * Removes directories left empty by the move.
     *
     * Walks up only while directories are genuinely empty, and stops at the
     * user's home or the storage root so it can never climb somewhere it
     * shouldn't.
     */
    private function pruneEmptyParents(string $directory, int $maxDepth = 3): void
    {
        $stopAt = array_filter([
            realpath(Storage::path('')),
            realpath((string) getenv('HOME')),
            realpath(base_path()),
            // A watch folder must survive being emptied, or the next scheduled
            // scan has nothing left to watch.
            ...array_map(
                fn(string $folder) => realpath($this->expandPath($folder)) ?: null,
                (array) config('library.watch_folders', []),
            ),
        ]);

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $real = realpath($directory);

            if ($real === false || in_array($real, $stopAt, true)) {
                return;
            }

            $entries = @scandir($real);

            if ($entries === false) {
                return;
            }

            // Ignore . and .. plus macOS's .DS_Store, which would otherwise
            // keep an obviously-empty folder alive forever.
            $meaningful = array_values(array_diff($entries, ['.', '..', '.DS_Store']));

            if ($meaningful !== []) {
                return;
            }

            @unlink($real . '/.DS_Store');

            if (! @rmdir($real)) {
                return;
            }

            $directory = dirname($real);
        }
    }
}
