<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Jobs\ExtractBookAssetsJob;
use App\Jobs\ImportSubtitlesJob;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Finds audio files in the watched folders that aren't catalogued yet.
 *
 * Shared by the scheduled `library:scan` command and the "Scan for new files"
 * button in the admin panel, so both behave identically — a file skipped for
 * being mid-copy on the schedule is skipped for the same reason in the UI.
 */
class LibraryScanner
{
    public function __construct(
        private DuplicateDetector $duplicates,
        private LibrarySettings $settings,
        private EpisodeParser $episodes,
    ) {}

    /**
     * @param array<int, string>|null $folders  Defaults to the configured watch list.
     * @return array{imported: int, unsettled: int, duplicates: int, folders: int, titles: array<int, string>}
     */
    public function scan(?array $folders = null, bool $dryRun = false, bool $enrich = true): array
    {
        $folders = $this->resolveFolders($folders);

        $result = [
            'imported' => 0,
            'unsettled' => 0,
            'duplicates' => 0,
            'folders' => count($folders),
            'titles' => [],
        ];

        if ($folders === []) {
            return $result;
        }

        $userId = User::query()->min('id');

        if ($userId === null && ! $dryRun) {
            return $result;
        }

        // One query beats a lookup per file; a few thousand paths is cheap to
        // hold and turns the duplicate check into a hash lookup.
        // Keyed on the resolved absolute path, not the stored string: uploads
        // store a disk-relative path while the importer stores an absolute
        // one, so comparing raw strings lets the same file be catalogued twice.
        $known = MediaItem::query()
            ->where(fn ($query) => $query->whereNotNull('file_path')->orWhereNotNull('converted_path'))
            ->get(['id', 'file_path', 'converted_path'])
            ->flatMap(fn (MediaItem $item) => array_filter([
                $item->absoluteFilePath() ?? $item->file_path,
                // A converted copy belongs to an item that already exists.
                // Without this, a conversion written anywhere the scanner
                // reaches becomes a second movie named after the output file.
                filled($item->converted_path) ? Storage::path($item->converted_path) : null,
            ]))
            ->mapWithKeys(fn (string $path) => [$path => true]);

        $settle = $this->settings->settleSeconds();
        $excluded = $this->excludedPaths();

        foreach ($folders as $folder) {
            foreach ($this->mediaFilesIn($folder, $excluded) as $file) {
                $path = $file->getRealPath();

                if ($path === false || isset($known[$path])) {
                    continue;
                }

                // A file still being copied would be catalogued half-written,
                // and getID3 would read whatever partial tags it found.
                if (time() - $file->getMTime() < $settle) {
                    $result['unsettled']++;

                    continue;
                }

                $type = $this->typeForExtension($file->getExtension());

                if ($type === null) {
                    continue;
                }

                $basename = $file->getBasename('.' . $file->getExtension());

                // Extension cannot separate a film from an episode — both are
                // .mkv — so the filename decides. A recognised SxxEyy makes
                // this a show rather than the movie it was classified as.
                $parsed = $type === MediaItemType::Movie
                    ? $this->episodes->parse($basename)
                    : null;

                if ($parsed !== null) {
                    $type = MediaItemType::Show;
                }

                $title = $parsed !== null
                    // The episode's own title is filled by enrichment; until
                    // then the code identifies it unambiguously.
                    ? sprintf('%s S%02dE%02d', $parsed['series'], $parsed['season'], $parsed['episode'])
                    : $this->cleanTitle($basename, $type);

                // A filename that carries no readable title — a temp-upload
                // hash, say — would only send noise to the metadata sources.
                if ($title === null) {
                    continue;
                }

                // The filename's author is the one piece of evidence about a
                // book that doesn't come from the lookup being checked, so it
                // is seeded before enrichment rather than discarded.
                $seed = [];

                if ($type === MediaItemType::Book) {
                    $author = $this->authorHintFrom($basename);

                    if ($author !== null) {
                        $seed['author'] = $author;
                    }
                }

                // Numbering comes from the filename and is authoritative about
                // which episode this file is — an online lookup can correct the
                // title but not which file you are holding.
                if ($parsed !== null) {
                    $seed['season_number'] = $parsed['season'];
                    $seed['episode_number'] = $parsed['episode'];
                }

                if (! $dryRun) {
                    $item = $this->catalog($path, $title, $type, $userId, $seed);

                    // Episodes hang off one series row, so a show is a single
                    // entry with children rather than ten unrelated items.
                    if ($parsed !== null) {
                        $this->attachToSeries($item, $parsed['series'], $userId);
                    }

                    // Checked before enrichment is queued: an identical copy
                    // doesn't need identifying a second time, and under the
                    // 'auto' action the file may not survive the check.
                    if ($this->duplicates->check($item) !== null) {
                        $result['duplicates']++;
                    }

                    if ($enrich) {
                        EnrichMediaItemJob::dispatch($item->id);
                    }

                    // Captions that shipped with the file — embedded streams
                    // and sidecar .srt files. Local only, so this costs
                    // nothing but a little CPU and needs no account.
                    if ($type === MediaItemType::Movie && config('subtitles.auto_import', true)) {
                        ImportSubtitlesJob::dispatch($item->id);
                    }

                    // Illustrations, the publisher's outline, and the text of
                    // every page for search — all already inside the file.
                    if ($type === MediaItemType::Book && config('books.auto_extract', true)) {
                        ExtractBookAssetsJob::dispatch($item->id);
                    }
                }

                $known[$path] = true;
                $result['imported']++;

                // Enough to name what was found without unbounded growth on a
                // first run over a large folder.
                if (count($result['titles']) < 5) {
                    $result['titles'][] = $title;
                }
            }
        }

        return $result;
    }

    /**
     * Links an episode to its series, creating the series if it is new.
     *
     * The series row has no file: it exists so a show is one entry with
     * children rather than ten unrelated items scattered through the library.
     * Matching is on title within this account, which is what a second episode
     * of the same show arriving later needs to find.
     *
     * Failure here is not fatal — an unattached episode still plays and still
     * files correctly, so it must not abort the scan.
     */
    private function attachToSeries(MediaItem $episode, string $seriesTitle, ?int $userId): void
    {
        try {
            $series = MediaItem::firstOrCreate(
                [
                    'type' => MediaItemType::Show,
                    'title' => $seriesTitle,
                    'parent_id' => null,
                    // A series is a grouping, not a file. Distinguishing it
                    // from an episode that has yet to be filed.
                    'file_path' => null,
                ],
                [
                    'user_id' => $userId,
                    'processing_status' => ProcessingStatus::Pending,
                    'owned' => true,
                ],
            );

            if ($series->wasRecentlyCreated) {
                $series->metadata()->create([]);
            }

            $episode->forceFill(['parent_id' => $series->id])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $attributes Seed values for the metadata row.
     */
    /**
     * Rebuilds catalogue rows for media that is already filed.
     *
     * The scanner deliberately refuses to look inside its own output — filed
     * media is catalogued by definition, and re-scanning it would file it a
     * second time. That guard is right in normal operation and exactly wrong
     * after a catalogue is lost with the files intact: 1,300 tracks sat on disk
     * that no scan would ever look at.
     *
     * This walks that folder and writes a row per file **without moving
     * anything**. The organiser is never invoked, so a library that is already
     * correctly filed keeps its structure — which is the whole reason to
     * recover in place rather than tipping everything into an unsorted folder
     * and letting the filer sort it out from tags.
     *
     * @param array<int, string> $folders Absolute paths to walk.
     * @return array{recovered: int, skipped: int, titles: array<int, string>}
     */
    public function recover(array $folders, bool $dryRun = false, bool $enrich = false): array
    {
        $result = ['recovered' => 0, 'skipped' => 0, 'titles' => []];
        $userId = User::query()->min('id');

        if ($userId === null && ! $dryRun) {
            return $result;
        }

        // Same path-keyed lookup the scan uses, so running this twice does not
        // produce two rows for one file.
        $known = MediaItem::query()
            ->whereNotNull('file_path')
            ->get(['id', 'file_path'])
            ->mapWithKeys(fn (MediaItem $item) => [
                ($item->absoluteFilePath() ?? $item->file_path) => true,
            ]);

        foreach ($this->realPaths($folders) as $folder) {
            // No exclusion list: this is called *because* the target is
            // normally excluded.
            foreach ($this->mediaFilesIn($folder, []) as $file) {
                $path = $file->getRealPath();

                if ($path === false || isset($known[$path])) {
                    $result['skipped']++;

                    continue;
                }

                $type = $this->typeForExtension($file->getExtension());

                if ($type === null) {
                    continue;
                }

                $basename = $file->getBasename('.' . $file->getExtension());
                $parsed = $type === MediaItemType::Movie
                    ? $this->episodes->parse($basename)
                    : null;

                if ($parsed !== null) {
                    $type = MediaItemType::Show;
                }

                $title = $parsed !== null
                    ? sprintf('%s S%02dE%02d', $parsed['series'], $parsed['season'], $parsed['episode'])
                    : $this->cleanTitle($basename, $type);

                if ($title === null) {
                    $result['skipped']++;

                    continue;
                }

                $seed = [];

                if ($type === MediaItemType::Book) {
                    $author = $this->authorHintFrom($basename);

                    if ($author !== null) {
                        $seed['author'] = $author;
                    }
                }

                if ($parsed !== null) {
                    $seed['season_number'] = $parsed['season'];
                    $seed['episode_number'] = $parsed['episode'];
                }

                if (! $dryRun) {
                    $item = $this->catalog($path, $title, $type, $userId, $seed);

                    if ($parsed !== null) {
                        $this->attachToSeries($item, $parsed['series'], $userId);
                    }

                    // Opt-in, unlike a scan. Recovery is about getting the rows
                    // back; enrichment reads tags, extracts cover art and makes
                    // network requests, which is a separate decision from
                    // whether the library exists at all.
                    if ($enrich) {
                        EnrichMediaItemJob::dispatch($item->id);
                    }
                }

                $known[$path] = true;
                $result['recovered']++;

                if (count($result['titles']) < 10) {
                    $result['titles'][] = $title;
                }
            }
        }

        return $result;
    }

    private function catalog(string $path, string $title, MediaItemType $type, ?int $userId, array $attributes = []): MediaItem
    {
        $item = MediaItem::create([
            'user_id'           => $userId,
            'type'              => $type,
            // Enrichment promotes the real title once the file is identified.
            'title'             => $title,
            'file_path'         => $path,
            'processing_status' => ProcessingStatus::Pending,
            'owned'             => true,
        ]);

        // Sources write into this row rather than creating it, so it has to
        // exist before enrichment runs.
        $item->metadata()->create($attributes);

        return $item;
    }

    /**
     * Turns a filename into something a metadata source can actually match.
     *
     * Downloaded files carry a lot of noise — site stamps like "(z-lib.org)",
     * a trailing "by Author", quality tags, and separators. Passing the raw
     * filename through means the lookup searches for a title no book has, and
     * comes back empty.
     *
     * Returns null when nothing usable survives, so a temp-upload hash is
     * skipped rather than catalogued as a book named after its own hash.
     */
    /**
     * Pulls the author out of a "Title by Author Name" filename.
     *
     * `cleanTitle()` strips this so the title search isn't polluted, but the
     * name is the only independent evidence we have about who wrote the file.
     * A title-only lookup will happily return a different author's book of the
     * same name, and the organizer renames files from that result — so this is
     * kept as a hint for the metadata source to corroborate against.
     */
    private function authorHintFrom(string $filename): ?string
    {
        $name = preg_replace(
            '/[\(\[\{]\s*(z-?lib[^\)\]\}]*|pdf|epub|mobi|retail|ebook)\s*[\)\]\}]/i',
            ' ',
            $filename,
        ) ?? $filename;

        if (! preg_match('/\sby\s+([^-–—]+)$/i', $name, $match)) {
            return null;
        }

        $author = trim(preg_replace('/\s{2,}/', ' ', $match[1]) ?? $match[1]);
        $author = trim($author, " ._-–—");

        // A trailing "by" fragment that isn't a name (a stray word, a number)
        // would be worse than having no hint at all.
        return preg_match('/\p{L}/u', $author) && strlen($author) <= 80
            ? $author
            : null;
    }

    private function cleanTitle(string $filename, MediaItemType $type): ?string
    {
        $title = $filename;

        // Site stamps and bracketed noise: (z-lib.org), [PDF], {retail}…
        $title = preg_replace('/[\(\[\{]\s*(z-?lib[^\)\]\}]*|pdf|epub|mobi|retail|ebook)\s*[\)\]\}]/i', ' ', $title) ?? $title;

        // "Title by Author Name" — the author is dropped here because the
        // metadata source resolves it far more reliably from the title alone.
        $title = preg_replace('/\s+by\s+[^-–—]+$/i', '', $title) ?? $title;

        // Underscores and dots stand in for spaces in a lot of downloads.
        $title = str_replace(['_', '.'], ' ', $title);

        if (in_array($type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            $title = $this->stripReleaseTags($title);
        }

        // Leading track/index numbers: "130 No Surprises", "01 - Title".
        $title = preg_replace('/^\d{1,4}\s*[-–—.]?\s+/', '', $title) ?? $title;

        $title = trim(preg_replace('/\s{2,}/', ' ', $title) ?? $title);
        $title = trim($title, " -–—_");

        if ($title === '') {
            return null;
        }

        // A long unbroken run of mixed-case characters with no spaces is a
        // generated name (Livewire temp uploads look exactly like this), not
        // a title anyone would search for.
        if (! str_contains($title, ' ') && strlen($title) > 24) {
            return null;
        }

        return $title;
    }

    /**
     * Reduces a video filename to just its title, and a year when present.
     *
     * Video files arrive carrying resolution, codec, audio layout, source, and
     * a release-group suffix. Sending all of that to TMDB as a title matches
     * nothing, so everything from the first such marker onward is dropped —
     * the title is always what precedes them.
     */
    private function stripReleaseTags(string $title): string
    {
        // Anything from the first technical marker onward is not part of the
        // name. A four-digit year is included because it reliably terminates
        // the title, and it's put back below.
        $markers = '(?:19\d{2}|20\d{2}|480p|720p|1080p|1440p|2160p|4k|8k|'
            . 'x264|x265|h ?264|h ?265|hevc|avc|xvid|divx|10bit|8bit|hdr\d*|dv|'
            . 'bluray|blu ray|brrip|bdrip|webrip|web ?dl|hdtv|dvdrip|remux|cam|'
            . 'aac\d*|ac3|dts(?:[ -]?hd)?|truehd|atmos|ddp?5|dd\+?|flac|mp3|'
            . 'multi|dual|subbed|dubbed|repack|proper|extended|unrated|imax|'
            . 'season|s\d{1,2}e\d{1,2}|complete)';

        // Capture a year if one appears, so it isn't lost with the rest.
        preg_match('/\b(19\d{2}|20\d{2})\b/', $title, $yearMatch);

        $cleaned = preg_replace('/\s' . $markers . '\b.*$/i', '', $title) ?? $title;

        // A name that is entirely markers leaves nothing; keep the original
        // rather than returning an empty string.
        $cleaned = trim($cleaned) !== '' ? trim($cleaned) : $title;

        // Bracketed noise the pattern above wouldn't have reached.
        $cleaned = preg_replace('/[\[\(\{][^\]\)\}]*[\]\)\}]/', ' ', $cleaned) ?? $cleaned;

        $cleaned = trim(preg_replace('/\s{2,}/', ' ', $cleaned) ?? $cleaned);
        $cleaned = trim($cleaned, " -–—_");

        // The year disambiguates remakes, so it's appended back when the title
        // didn't already end with it.
        if (isset($yearMatch[1]) && ! str_ends_with($cleaned, $yearMatch[1])) {
            $cleaned .= ' ' . $yearMatch[1];
        }

        return $cleaned !== '' ? $cleaned : $title;
    }

    /**
     * Classifies a dropped file by extension.
     *
     * The inbox accepts anything, so this is what decides whether a file
     * becomes a track, a film, or a book. Unknown extensions are ignored
     * rather than guessed at.
     */
    private function typeForExtension(string $extension): ?MediaItemType
    {
        $extension = strtolower($extension);

        foreach ((array) config('library.type_extensions', []) as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return MediaItemType::tryFrom($type);
            }
        }

        return null;
    }

    /**
     * @param array<int, string>|null $folders
     * @return array<int, string>
     */
    private function resolveFolders(?array $folders): array
    {
        // An explicit --path overrides everything, including the storage sweep.
        if ($folders !== null && $folders !== []) {
            return $this->realPaths($folders);
        }

        $paths = (array) config('library.watch_folders', []);

        // Sweep the storage disk too, so uploads and hand-dropped files get
        // picked up wherever they landed rather than only in a watch folder.
        if ($this->settings->scanStorage()) {
            $paths[] = Storage::path('');
        }

        return $this->realPaths($paths);
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private function realPaths(array $paths): array
    {
        $resolved = array_map(
            function (string $path): ?string {
                $real = realpath($this->expandPath($path));

                return ($real !== false && is_dir($real)) ? $real : null;
            },
            $paths,
        );

        // A watch folder nested inside another would otherwise be walked twice.
        return array_values(array_unique(array_filter($resolved)));
    }

    /**
     * Absolute paths the scanner must not descend into.
     *
     * The organized library is the big one: its files are already catalogued,
     * so walking it would re-import the entire collection on every pass.
     *
     * @return array<int, string>
     */
    private function excludedPaths(): array
    {
        return array_values(array_filter(array_map(
            fn (string $relative): ?string => realpath(Storage::path($relative)) ?: null,
            (array) config('library.scan_exclude', []),
        )));
    }

    /**
     * Walks a folder and every subfolder for recognised media files.
     *
     * @param array<int, string> $excluded Absolute paths not to descend into.
     * @return iterable<SplFileInfo>
     */
    private function mediaFilesIn(string $folder, array $excluded): iterable
    {
        $extensions = collect(config('library.type_extensions', []))
            ->flatten()
            ->all();

        $finder = (new Finder())
            ->files()
            ->in($folder)
            ->followLinks()
            // macOS resource forks and hidden junk aren't media.
            ->notName('._*')
            ->ignoreDotFiles(true);

        // Skipping the sorted library matters most: walking it would re-import
        // the whole collection on every pass.
        foreach ($excluded as $path) {
            if (str_starts_with($path, $folder . DIRECTORY_SEPARATOR) || $path === $folder) {
                $finder->notPath(
                    ltrim(substr($path, strlen($folder)), DIRECTORY_SEPARATOR),
                );
            }
        }

        foreach ($finder as $file) {
            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                yield $file;
            }
        }
    }

    private function expandPath(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return rtrim((string) getenv('HOME'), '/') . substr($path, 1);
        }

        return $path;
    }
}
