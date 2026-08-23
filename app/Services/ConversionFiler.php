<?php

namespace App\Services;

use App\Models\MediaItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Makes a browser-playable conversion the library item, and sets the original
 * aside.
 *
 * Conversions used to live in media/converted/, excluded from scans and
 * reachable only through a database column — so a rebuilt catalogue found the
 * unplayable original, pointed at that, and the 4.7 GB conversion sitting on
 * disk was invisible to everything that looks. That is exactly how one film
 * came to be unplayable while its playable copy existed.
 *
 * The conversion is filed like any other item. The original moves to the
 * archive, kept whole: video is already compressed, and on the library that
 * prompted this the HEVC source was smaller than its H.264 conversion, so
 * compressing would cost playability to save nothing.
 */
class ConversionFiler
{
    public function __construct(private LibraryOrganizer $organizer) {}

    /**
     * @return array{filed: string, archived: string}|null
     */
    public function promote(MediaItem $item, bool $dryRun = false): ?array
    {
        if (! $item->hasConvertedCopy()) {
            return null;
        }

        $original = $item->absoluteFilePath();
        $conversion = Storage::path($item->converted_path);

        // Without a conversion there is nothing to promote, and the item keeps
        // whatever it already had.
        if (! is_file($conversion)) {
            return null;
        }

        // A missing original is not a reason to refuse. It is the strongest
        // reason to act: the item is pointing at a file that is not there,
        // while a playable conversion sits in a folder the scanner skips.
        // File it and let the item point at something real.
        if ($original === null || ! is_file($original)) {
            return $this->rescue($item, $conversion, $dryRun);
        }

        $filed = $this->filedPathFor($item, $conversion);
        $archived = $this->archivePathFor($item, $original);

        // Filing must never make the arrangement worse. When the organizer
        // cannot derive a structure — a film whose year never arrived — the
        // fallback is the library root, and an item already sitting in a
        // proper folder would be moved out of it into a flat one.
        //
        // Leave it where it is and say so. A missing year is a metadata
        // problem to fix at the source, not something to paper over by
        // rearranging files.
        if ($this->wouldDemote($item, $filed)) {
            return null;
        }

        if ($dryRun) {
            return ['filed' => $filed, 'archived' => $archived];
        }

        // Ordered so a crash cannot leave the item with nothing playable.
        //
        // The conversion is moved into the library and verified before the
        // original is touched. A failure part way leaves a playable file and an
        // original in one place or the other — never neither.
        if (! $this->move($conversion, Storage::path($filed))) {
            Log::error('Could not file a converted copy', [
                'item' => $item->id,
                'from' => $conversion,
                'to' => $filed,
            ]);

            return null;
        }

        if (! is_file(Storage::path($filed))) {
            // The move reported success and the file is not there, which is
            // worth knowing about loudly: it means the check that follows is
            // the only thing standing between this and archiving an original
            // whose replacement does not exist.
            Log::error('A filed conversion was not where it was put', [
                'item' => $item->id,
                'expected' => $filed,
            ]);

            return null;
        }

        if (! $this->move($original, Storage::path($archived))) {
            // The conversion is filed and the original is where it was. Point
            // at the conversion anyway: it is playable, which the original is
            // not, and leaving the row on the old path would be worse.
            Log::warning('Filed a conversion but could not archive the original', [
                'item' => $item->id,
                'original' => $original,
                'archive' => $archived,
                'note' => 'the item plays; the original is still in the library tree',
            ]);
            $item->forceFill([
                'file_path' => Storage::path($filed),
                'converted_path' => null,
            ])->saveQuietly();

            return null;
        }

        $item->forceFill([
            'file_path' => Storage::path($filed),
            'archived_path' => $archived,
            // Cleared: the conversion is the item now, not a copy of it.
            'converted_path' => null,
        ])->saveQuietly();

        return ['filed' => $filed, 'archived' => $archived];
    }

    /**
     * Where the organiser would file this item, with the conversion's own
     * extension rather than the original's.
     */
    /**
     * Files a conversion whose original has gone missing.
     *
     * There is nothing to archive, so the archive step is skipped entirely.
     * What matters is that the item stops pointing at a file that is not there.
     *
     * @return array{filed: string, archived: string}|null
     */
    private function rescue(MediaItem $item, string $conversion, bool $dryRun): ?array
    {
        // targetPath() derives the destination from the file the item points
        // at, and that file is the missing one. Point it at the conversion
        // just long enough to plan, then put it back. Nothing is saved, so a
        // dry run stays a dry run and a failure leaves the row untouched.
        $missing = $item->file_path;
        $item->file_path = $conversion;

        try {
            $filed = $this->filedPathFor($item, $conversion);
        } finally {
            $item->file_path = $missing;
        }

        if ($dryRun) {
            return ['filed' => $filed, 'archived' => ''];
        }

        if (! $this->move($conversion, Storage::path($filed))) {
            return null;
        }

        $item->forceFill([
            'file_path' => Storage::path($filed),
            'converted_path' => null,
        ])->saveQuietly();

        return ['filed' => $filed, 'archived' => ''];
    }

    /**
     * Whether filing would move an item out of a structured folder into the
     * flat library root.
     */
    private function wouldDemote(MediaItem $item, string $filed): bool
    {
        // Only the unstructured fallback can demote; a real target is always
        // at least as organised as what came before.
        if ($this->organizer->targetPath($item) !== null) {
            return false;
        }

        $current = $item->absoluteFilePath();

        if ($current === null) {
            return false;
        }

        // One spelling before comparing. `Storage::path()` returns the root
        // with backslashes and the stored remainder with forward ones —
        // `C:\…\storage\app/private\media/library` — so a `DIRECTORY_SEPARATOR`
        // comparison never matched on Windows and this guard did nothing
        // there. It is the guard that stops the filer flattening a library
        // that was already organised.
        $current = str_replace('\\', '/', $current);
        $root = str_replace(
            '\\',
            '/',
            Storage::path(trim((string) config('library.library_root', 'media/library'), '/')),
        );

        // Already in the flat root, or outside the library altogether: filing
        // it to the root takes nothing away.
        if (! str_starts_with($current, $root . '/')) {
            return false;
        }

        $relative = substr($current, strlen($root) + 1);

        // A separator means it lives in a folder worth keeping.
        return str_contains($relative, '/');
    }

    private function filedPathFor(MediaItem $item, string $conversion): string
    {
        $target = $this->organizer->targetPath($item);

        if ($target === null) {
            // Nothing to derive a structure from — a film with no year, say.
            // Filed under the library root by title rather than left behind.
            $root = trim((string) config('library.library_root', 'media/library'), '/');
            $name = preg_replace('/[^\w\- .()]+/u', '', $item->title) ?: 'Untitled';

            return $root . '/' . trim($name) . '.' . pathinfo($conversion, PATHINFO_EXTENSION);
        }

        $extension = pathinfo($conversion, PATHINFO_EXTENSION);

        return preg_replace('/\.[^.]+$/', '', $target) . '.' . $extension;
    }

    /**
     * The archive mirrors the library's shape, so an original can be found by
     * the same path that finds the item.
     */
    private function archivePathFor(MediaItem $item, string $original): string
    {
        $root = trim((string) config('library.archive_root', 'media/archive'), '/');
        $target = $this->organizer->targetPath($item);
        $extension = pathinfo($original, PATHINFO_EXTENSION);

        if ($target === null) {
            $name = preg_replace('/[^\w\- .()]+/u', '', $item->title) ?: 'Untitled';

            return $root . '/' . trim($name) . '.' . $extension;
        }

        $withoutRoot = preg_replace(
            '#^' . preg_quote(trim((string) config('library.library_root', 'media/library'), '/'), '#') . '/#',
            '',
            $target,
        );

        return $root . '/' . preg_replace('/\.[^.]+$/', '', $withoutRoot) . '.' . $extension;
    }

    /** Moves a file, creating the directory it is going into. */
    private function move(string $from, string $to): bool
    {
        $directory = dirname($to);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        // rename() rather than copy-then-delete: it is atomic on one volume and
        // does not need room for a second copy of a film.
        if (@rename($from, $to)) {
            return true;
        }

        // Across volumes rename fails, so fall back to a copy that is verified
        // before the source is removed.
        if (! @copy($from, $to)) {
            return false;
        }

        if (filesize($to) !== filesize($from)) {
            @unlink($to);

            return false;
        }

        @unlink($from);

        return true;
    }
}
