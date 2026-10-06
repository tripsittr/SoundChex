<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use App\Enums\FileMoveKind;
use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves catalogued files into an organized Artist/Album/## Track tree.
 *
 * This is the only part of the app that relocates a user's files, so it errs
 * heavily toward doing nothing: an item with no resolved artist or album is
 * left exactly where it is rather than filed under "Unknown".
 */
class LibraryOrganizer
{
    public function __construct(
        private MediaTrash $trash,
        private FileMoveJournal $journal,
    ) {}

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
     * Everything is filed from an API match unless something better is known,
     * so by default it has to have matched exactly.
     *
     * Music has a looser rule, and the reason is the file's own tags: they are
     * authoritative about the file whatever an online source thinks (AGENTS.md
     * rule 2). But the exemption used to be unconditional -- `true` for all
     * music -- while MusicBrainz enrichment *writes* `artist` and `album`. So a
     * file whose tags said nothing was filed under an API guess, which is the
     * one thing the gate exists to prevent (#489).
     *
     * The rule is now what the exemption always meant: music may be filed when
     * its own embedded tags named the artist its path is built from, or when
     * the match is exact. API data alone never moves a file.
     */
    private function isConfidentEnoughToMove(MediaItem $item): bool
    {
        // An item the user has been asked to judge must not be moved out from
        // under them -- unless they have already judged it, in which case
        // holding it back forever is the opposite mistake (S-302).
        if ($item->processing_status === ProcessingStatus::NeedsReview
            && $item->reviewed_at === null) {
            return false;
        }

        if ($item->match_confidence?->allowsFileMove()) {
            return true;
        }

        return $item->type === MediaItemType::Music && $item->hasTaggedArtist();
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

        // By identity, for the same reason organize() does (#489): on a
        // case-insensitive volume a re-cased name is the same file, and
        // reporting it as unfiled is what sent it down the delete path.
        if ($this->isSamePath($source, $absoluteTarget)) {
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
            && preg_match('/^'.preg_quote($base, '/').' \(\d+\)$/', $actual) === 1;
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

        // Already filed — nothing to do. Compared by identity, not by string:
        // on a case-insensitive volume "03 Chicago.mp3" and "03 CHICAGO.mp3"
        // are different strings naming one file, and a string compare here
        // reported that file as unfiled (#489).
        if ($this->isSamePath($source, $absoluteTarget)) {
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

        // The same file reached by two spellings — a case-only difference on a
        // case-insensitive volume, or a symlinked library root. There is one
        // file and nothing to adopt or delete: rename it to the canonical
        // spelling and keep it.
        //
        // This case used to fall into isSameFile() below, which hashed both
        // paths, found equal hashes *because they are one file*, and deleted
        // "the duplicate" — the user's only copy (#489).
        if ($this->isSameInode($source, $absoluteTarget)) {
            return $this->recaseInPlace($item, $source, $absoluteTarget);
        }

        // A file already sitting at the target may be this exact recording,
        // catalogued twice. Suffixing it would keep a byte-identical copy
        // forever and re-offer it for filing on every run, so an identical
        // file is treated as "already filed here" instead.
        //
        // Reached only for two genuinely distinct files (different inodes), so
        // deleting the redundant one is safe.
        if ($this->isSameFile($source, $absoluteTarget)) {
            return $this->adoptExisting($item, $source, $absoluteTarget);
        }

        // Never overwrite: a same-named file with different content really is a
        // different recording, so it gets a suffix rather than clobbering what's
        // already filed.
        //
        // Choosing the name and taking it must be one step. Two workers filing
        // different recordings with the same ideal name would otherwise both
        // see the name free, both pick it, and the second rename would replace
        // the first worker's file (#489). The lock is per directory, so filing
        // into different albums still runs in parallel.
        $lock = Cache::lock('library-file:'.md5(dirname($absoluteTarget)), 30);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            // Another worker is filing into this folder. Leaving the item for
            // the next pass is correct: nothing is lost and nothing is raced.
            Log::info('Deferred filing: another worker holds this folder', [
                'item' => $item->id,
                'directory' => dirname($absoluteTarget),
            ]);

            return null;
        }

        try {
            $absoluteTarget = $this->uniquePath($absoluteTarget);
        } catch (\RuntimeException $e) {
            $lock->release();

            Log::error('Could not find a free name to file an item under', [
                'item' => $item->id,
                'target' => $absoluteTarget,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! @rename($source, $absoluteTarget)) {
            // rename() fails across filesystems (an external drive, say), so
            // fall back to copy-then-delete.
            if (! @copy($source, $absoluteTarget)) {
                $lock->release();

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
            //
            // By content, not by size: a copy interrupted and resumed, or one
            // written to a failing disk, can be the right length and the wrong
            // bytes. Size is checked first because it is free and rules out the
            // common truncation without hashing a 40 GB remux twice.
            if (! $this->copyIsFaithful($source, $absoluteTarget)) {
                @unlink($absoluteTarget);

                // The partial copy is removed and the original kept, which is
                // the right call — but a truncated copy usually means a full
                // disk, and that will happen again on the next file.
                $lock->release();

                Log::error('A filed copy did not match its source and was discarded', [
                    'item' => $item->id,
                    'from' => $source,
                    'expected' => @filesize($source),
                    'copied' => @filesize($absoluteTarget),
                ]);

                return null;
            }

            // Checked, because an unchecked unlink is how the library ends up
            // with two copies and no record of it: the row points at the new
            // path, the old file is still on disk, and the next scan catalogues
            // it as a second item.
            if (! @unlink($source)) {
                Log::warning('Filed a copy but could not remove the original', [
                    'item' => $item->id,
                    'original' => $source,
                    'filed' => $absoluteTarget,
                    'note' => 'the item points at the filed copy; the original is still on disk',
                ]);
            }
        }

        // The name is taken now, so the critical section is over -- the row
        // update and the peer repointing cannot race another worker's choice.
        $lock->release();

        $relative = $this->toRelative($absoluteTarget);

        $item->file_path = $relative;

        // The stored hash described the file at the old path. Clearing it means
        // a row can never carry a hash for a file it no longer points at, which
        // is what left byte-identical detection unable to pair two rows on one
        // file (S-346).
        $item->content_hash = null;

        $item->saveQuietly();

        $this->repointPeersForSharedSource($item, $storedSource, $relative);

        // Subtitles and artwork that shipped beside the file follow it. Nothing
        // moved them before, so filing a film orphaned its captions: the player
        // looks for them next to the video, and they stayed in the inbox
        // (#489). Done after the row is saved, because a sidecar that fails to
        // move is a missing subtitle rather than a lost film.
        $this->moveSidecars($item, $source, $absoluteTarget);

        $this->pruneEmptyParents(dirname($source));

        return $relative;
    }

    /**
     * Extensions that belong to the file beside them rather than standing alone.
     *
     * Subtitles and lyrics are named for their media file; `.nfo` and `.cue`
     * describe it. All of them are found by stem, so they have to travel with
     * it or the player stops finding them.
     */
    private const SIDECAR_EXTENSIONS = ['srt', 'ass', 'ssa', 'vtt', 'sub', 'idx', 'lrc', 'nfo', 'cue'];

    /**
     * Moves anything named after the file that just moved.
     *
     * Matched on the **exact stem**, optionally followed by a language or flag
     * suffix: `Alien.srt`, `Alien.en.srt`, `Alien.en.forced.srt`. Deliberately
     * not a prefix glob -- `SubtitleImporter` uses `base*.srt` and that lets
     * `Alien.mkv` claim `Aliens.en.srt`, which is the bug this must not repeat.
     *
     * Never overwrites: a sidecar already at the target is left alone, because
     * it is more likely to be the right one than the stray this is carrying.
     *
     * Journalled, like the media move itself. a5 noted on review that raw
     * `rename()` here left an asymmetry: a crash between the journalled media
     * move and these would orphan a sidecar in the old folder -- present and
     * logged, not lost, but invisible to the reconciler. Recording them means
     * the sweeper can finish or reverse them like anything else.
     *
     * The journal rows carry the media item's id, because that is what relates
     * a subtitle to its film -- and `FileMoveKind::Sidecar` is why that does
     * not make the reconciler repoint `file_path` at a `.srt`.
     */
    private function moveSidecars(MediaItem $item, string $source, string $target): void
    {
        $directory = dirname($source);
        $stem = pathinfo($source, PATHINFO_FILENAME);

        if ($stem === '' || ! is_dir($directory)) {
            return;
        }

        $targetDirectory = dirname($target);
        $targetStem = pathinfo($target, PATHINFO_FILENAME);

        // One batch for the whole set, so `library:undo-moves --batch` puts a
        // film's captions back with it rather than one at a time.
        $batchId = (string) Str::uuid();

        foreach ((array) scandir($directory) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

            if (! in_array($extension, self::SIDECAR_EXTENSIONS, true)) {
                continue;
            }

            // The part between the stem and the extension: "" for Alien.srt,
            // ".en" for Alien.en.srt. Anything else is a different file.
            $entryStem = pathinfo($entry, PATHINFO_FILENAME);

            if ($entryStem !== $stem && ! str_starts_with($entryStem, $stem.'.')) {
                continue;
            }

            $qualifier = substr($entryStem, strlen($stem));
            $from = $directory.'/'.$entry;
            $to = $targetDirectory.'/'.$targetStem.$qualifier.'.'.$extension;

            if (! is_file($from) || file_exists($to)) {
                continue;
            }

            // The journal logs its own failures with both paths, which is the
            // clue behind "it was there before I organised".
            $this->journal->move($item, $from, $to, FileMoveKind::Sidecar, $batchId);
        }
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

        $typeFolder = config('library.type_folders.'.$item->type->value)
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
        $suffix = $extension !== '' ? '.'.$extension : '';

        return match ($item->type) {
            MediaItemType::Music => $this->musicSegments($item, $title, $suffix),
            MediaItemType::Movie => $this->movieSegments($item, $title, $suffix),
            MediaItemType::Show => $this->showSegments($item, $title, $suffix),
            MediaItemType::Book => $this->bookSegments($item, $title, $suffix),
        };
    }

    /**
     * @return array<int, string>|null
     */
    private function musicSegments(MediaItem $item, string $title, string $suffix): ?array
    {
        $meta = $item->musicMetadata;

        // The ALBUM artist decides the folder, falling back to the track's own
        // when there is none. A compilation's tracks each have a different
        // performer but one shelf, and using the track artist scattered them:
        // measured on this library, 160 albums would spread across 429 folders,
        // and the Stranger Things soundtrack split into 14 folders for 14
        // tracks -- one per track (#489).
        //
        // The fallback is why most files are unaffected: a single artist's
        // album has no album-artist tag and needs none.
        $artist = $this->segment($meta?->album_artist)
            ?? $this->segment($meta?->artist);

        if ($artist === null) {
            return null;
        }

        // Tracks with no album are singles; grouping them under one folder per
        // artist keeps them filed rather than stranded in the inbox.
        $album = $this->segment($meta?->album) ?? 'Singles';

        // A zero-padded track number keeps an album in playing order when the
        // folder is browsed or copied to a device.
        $track = $meta?->track_number
            ? str_pad((string) $meta->track_number, 2, '0', STR_PAD_LEFT).' '
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
        // Stripped against the TRACK artist, not the folder's album artist: on
        // a compilation the performer is the one thing the filename should
        // keep, and stripping the album artist would leave every file named
        // after the compilation.
        return [$artist, $album, $track.$this->withoutArtist($title, $meta?->artist).$suffix];
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
            $suffix = $separator.$artist;

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
        $folder = $this->segment($item->title.' ('.$year.')') ?? $title;

        return [$folder, $folder.$suffix];
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

        $name = $series.' - '.$code;

        // The episode title is appended when known, since "S01E02" alone tells
        // you nothing when browsing the folder directly.
        $episodeTitle = $this->segment($meta?->episode_title);

        if ($episodeTitle !== null && $episodeTitle !== $series) {
            $name .= ' - '.$episodeTitle;
        }

        return [
            $series,
            sprintf('Season %02d', $season),
            $name.$suffix,
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

        return [$author, $title.$suffix];
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

        $clean = $this->escapeReservedName($clean);

        return $this->truncateBytes($clean, 120);
    }

    /**
     * Windows refuses these names outright, with or without an extension.
     *
     * `CON`, `NUL`, `PRN`, `AUX`, `COM1`–`COM9` and `LPT1`–`LPT9` are device
     * names reserved since DOS. A file called `NUL.mp3` cannot be created on
     * Windows at all -- the call fails rather than producing a badly-named
     * file -- so a band called AUX or a track called Con would simply never
     * file, with the failure logged as a permissions problem.
     *
     * An underscore is appended rather than the name replaced, so the result
     * is still recognisable as what it was.
     */
    private function escapeReservedName(string $value): string
    {
        $stem = pathinfo($value, PATHINFO_FILENAME);

        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $stem) !== 1) {
            return $value;
        }

        $extension = pathinfo($value, PATHINFO_EXTENSION);

        return $stem.'_'.($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Truncates to a byte budget, not a character count.
     *
     * The limit filesystems impose per path component is **bytes** (255 on
     * ext4, APFS and NTFS), and `mb_substr` counts characters. A 120-character
     * CJK or emoji title is 360 bytes -- measured -- which is past the limit,
     * so the move failed with an error that named permissions rather than
     * length.
     *
     * Cut on a character boundary so the result is still valid UTF-8, then
     * re-trimmed: truncation can leave a trailing space or dot, which Windows
     * rejects for its own separate reason.
     */
    private function truncateBytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        // mb_strcut cuts by bytes without splitting a character in half.
        $cut = mb_strcut($value, 0, $maxBytes, 'UTF-8');

        return rtrim($cut, ". \t") ?: $cut;
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/').substr($path, 1);
        }

        return $path;
    }

    /**
     * Whether the file is already exactly where it belongs.
     *
     * Delegates to {@see FileIdentity}: the question cannot be answered by
     * comparing path strings, and getting it wrong deleted files (#489).
     */
    private function isSamePath(string $a, string $b): bool
    {
        return FileIdentity::same($a, $b);
    }

    /**
     * Whether two paths are one file reached by two spellings.
     *
     * Distinguished from `isSamePath()` because this case has its own correct
     * action — rename to the canonical spelling — rather than "do nothing".
     */
    private function isSameInode(string $a, string $b): bool
    {
        return FileIdentity::sameInode($a, $b);
    }

    /**
     * Renames a file to the canonical spelling of its own name.
     *
     * One file, two spellings. A direct `rename()` between them is a no-op on
     * some filesystems and an error on others, so it goes via a temporary name
     * in the same directory: `a.mp3` → `a.mp3.sc-tmp` → `A.mp3`. Both steps are
     * within one directory, so neither can cross a volume.
     *
     * If either step fails the file keeps a valid name — the original on the
     * first, the temporary on the second — and the row is pointed at whichever
     * exists, so nothing is ever left pointing at nothing.
     */
    private function recaseInPlace(MediaItem $item, string $source, string $absoluteTarget): ?string
    {
        $storedSource = $item->file_path;
        $temporary = $absoluteTarget.'.sc-tmp';

        if (file_exists($temporary) && ! @unlink($temporary)) {
            Log::warning('Could not clear a stale temporary name for a case-only rename', [
                'item' => $item->id,
                'temporary' => $temporary,
            ]);

            return null;
        }

        if (! @rename($source, $temporary)) {
            Log::warning('Could not stage a case-only rename', [
                'item' => $item->id,
                'from' => $source,
                'to' => $temporary,
            ]);

            return null;
        }

        if (! @rename($temporary, $absoluteTarget)) {
            // The file is at the temporary name and the row must point there,
            // or the catalogue loses the file entirely. The next run retries.
            Log::error('A case-only rename stalled at its temporary name', [
                'item' => $item->id,
                'temporary' => $temporary,
                'intended' => $absoluteTarget,
            ]);

            $item->file_path = $this->toRelative($temporary);
            $item->content_hash = null;
            $item->saveQuietly();

            return null;
        }

        $relative = $this->toRelative($absoluteTarget);

        $item->file_path = $relative;
        // The path changed, so the stored hash describes a path this row no
        // longer points at (AGENTS.md rule 2).
        $item->content_hash = null;
        $item->saveQuietly();

        $this->repointPeersForSharedSource($item, $storedSource, $relative);

        return $relative;
    }

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

        // The same algorithm the catalogue stores content hashes in, so a
        // file this decides is a duplicate is one DuplicateDetector agrees on.
        $hashA = @hash_file(DuplicateDetector::HASH, $a);
        $hashB = @hash_file(DuplicateDetector::HASH, $b);

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
        $item->content_hash = null; // Describes the old path — see above (S-346).
        $item->saveQuietly();

        $this->repointPeersForSharedSource($item, $storedSource, $relative);

        // Trashed, not unlinked: this is the branch that deleted the only copy
        // of a file when two spellings of one path were read as two files
        // (#489). The identity check above now prevents that, and this makes
        // the next mistake of its kind recoverable rather than final (#489).
        $this->trash->discard($source, reason: 'identical copy already filed');

        $this->pruneEmptyParents(dirname($source));

        return $relative;
    }

    /**
     * Appends a counter until the path is free.
     */
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

        // Returning $path here -- which is occupied, that being why we are in
        // this method -- handed the caller a path it would then overwrite
        // (#489). A thousand same-named files in one folder is a real problem
        // worth surfacing, not something to paper over by destroying one.
        throw new \RuntimeException("No free filename after 999 attempts: {$path}");
    }

    /**
     * Whether a copy holds the same bytes as its source.
     *
     * Size first because it is free and rules out the common truncation, then
     * the content hash -- a copy interrupted and resumed, or written to a
     * failing disk, can be the right length and the wrong bytes, and this
     * decides whether the original is deleted.
     */
    private function copyIsFaithful(string $source, string $copy): bool
    {
        $sourceSize = @filesize($source);
        $copySize = @filesize($copy);

        if ($sourceSize === false || $copySize === false || $sourceSize !== $copySize) {
            return false;
        }

        $sourceHash = @hash_file(DuplicateDetector::HASH, $source);
        $copyHash = @hash_file(DuplicateDetector::HASH, $copy);

        return $sourceHash !== false && $sourceHash === $copyHash;
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

        MediaItem::unresolved()
            ->where('id', '!=', $item->id)
            ->where('file_path', $from)
            ->update(['file_path' => $to]);
    }

    /**
     * Converts an absolute path back to one relative to the storage disk, so
     * the stored value keeps working through Storage::.
     */
    public function toRelative(string $absolute): string
    {
        $root = realpath(Storage::path(''));
        $real = realpath($absolute) ?: $absolute;

        if ($root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
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
                fn (string $folder) => realpath($this->expandPath($folder)) ?: null,
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

            @unlink($real.'/.DS_Store');

            if (! @rmdir($real)) {
                return;
            }

            $directory = dirname($real);
        }
    }
}
