<?php

namespace App\Console\Commands;

use App\Enums\MediaItemType;
use App\Enums\ProcessingStatus;
use App\Jobs\EnrichMediaItemJob;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Imports a folder of audio files into the library.
 *
 * The browser upload path is fine for a handful of tracks but falls over on a
 * real collection — this reads straight from disk, so there is no request
 * timeout, no per-request file cap, and no multipart overhead.
 */
class ImportMusicFolder extends Command
{
    protected $signature = 'library:import-music
        {path : Folder to scan for audio files}
        {--copy : Copy files into storage instead of referencing them in place}
        {--dry-run : List what would be imported without writing anything}
        {--no-enrich : Skip queueing metadata enrichment}';

    protected $description = 'Scan a folder and import its audio files into the library';

    /**
     * Extensions getID3 can read that make sense as library items.
     */
    private const AUDIO_EXTENSIONS = [
        'mp3', 'flac', 'm4a', 'aac', 'wav', 'aiff', 'aif',
        'ogg', 'oga', 'opus', 'wma', 'alac', 'ape', 'wv',
    ];

    public function handle(): int
    {
        $path = realpath($this->argument('path'));

        if ($path === false || ! is_dir($path)) {
            $this->error('Not a readable directory: ' . $this->argument('path'));

            return self::FAILURE;
        }

        $files = $this->findAudioFiles($path);

        if (empty($files)) {
            $this->warn('No audio files found in ' . $path);

            return self::SUCCESS;
        }

        $this->info(count($files) . ' audio ' . str('file')->plural(count($files)) . ' found in ' . $path);

        if ($this->option('dry-run')) {
            foreach ($files as $file) {
                $this->line('  ' . $file->getFilename());
            }

            $this->newLine();
            $this->comment('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $userId = User::query()->min('id');

        if ($userId === null) {
            $this->error('No users exist yet. Register an account first.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;

        $progress = $this->output->createProgressBar(count($files));
        $progress->start();

        foreach ($files as $file) {
            $storedPath = $this->option('copy')
                ? $this->copyIntoStorage($file)
                : $this->registerExternalPath($file);

            if ($storedPath === null) {
                $skipped++;
                $progress->advance();

                continue;
            }

            // Re-running the import shouldn't duplicate the library.
            if (MediaItem::where('file_path', $storedPath)->exists()) {
                $skipped++;
                $progress->advance();

                continue;
            }

            $item = MediaItem::create([
                'user_id'           => $userId,
                'type'              => MediaItemType::Music,
                // FileTagger promotes the real title once tags are read.
                'title'             => $file->getBasename('.' . $file->getExtension()),
                'file_path'         => $storedPath,
                'processing_status' => ProcessingStatus::Pending,
                'owned'             => true,
            ]);

            // FileTagger writes into this row, so it must exist first.
            $item->musicMetadata()->create([]);

            if (! $this->option('no-enrich')) {
                EnrichMediaItemJob::dispatch($item->id);
            }

            $imported++;
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        $this->info("Imported {$imported} " . str('track')->plural($imported));

        if ($skipped > 0) {
            $this->comment("Skipped {$skipped} (already in library or unreadable)");
        }

        if (! $this->option('no-enrich') && $imported > 0) {
            $this->newLine();
            $this->comment('Metadata enrichment queued. Run `php artisan queue:work` if no worker is running.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, SplFileInfo>
     */
    private function findAudioFiles(string $path): array
    {
        $finder = (new Finder())
            ->files()
            ->in($path)
            ->followLinks()
            // macOS resource forks and hidden junk aren't media.
            ->notName('._*')
            ->sortByName();

        $files = [];

        foreach ($finder as $file) {
            $extension = strtolower($file->getExtension());

            if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Copies the file onto the configured disk and returns its storage path.
     */
    private function copyIntoStorage(SplFileInfo $file): ?string
    {
        $stream = @fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            return null;
        }

        // Prefix keeps same-named files from different folders from colliding.
        $target = trim((string) config('library.inbox', 'media/unsorted'), '/')
            . '/' . Str::random(8) . '-' . $file->getFilename();

        Storage::put($target, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $target;
    }

    /**
     * Registers the file where it already lives.
     *
     * Nothing is copied, so a large collection doesn't get duplicated on disk —
     * but the library then depends on that folder staying put.
     */
    private function registerExternalPath(SplFileInfo $file): ?string
    {
        $real = $file->getRealPath();

        if ($real === false || ! is_readable($real)) {
            return null;
        }

        $root = realpath(Storage::path(''));

        // A file already inside the storage disk is addressed relative to it,
        // so the normal Storage::get()/response() paths keep working.
        if ($root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
        }

        return $real;
    }
}
