<?php

namespace App\Services;

use App\Enums\MediaItemType;
use App\Enums\MediaTagSource;
use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use Illuminate\Support\LazyCollection;

/**
 * CSV export and import for the whole library.
 *
 * One column list drives both directions, so a round trip is lossless: what
 * comes out imports back in without hand-editing headers.
 */
class LibraryCsv
{
    /**
     * Columns common to every media type, followed by the union of the
     * type-specific ones. A movie row simply leaves the music columns empty.
     *
     * @var array<int, string>
     */
    public const COLUMNS = [
        'type', 'title', 'year', 'owned', 'wishlist', 'user_rating', 'genres', 'notes',
        // Music
        'artist', 'album', 'track_number', 'label', 'bpm', 'key', 'scale', 'isrc',
        // Movie
        'director', 'studio', 'runtime_minutes', 'imdb_id', 'tmdb_id',
        // Show
        'creator', 'network', 'season_count', 'episode_count', 'status',
        // Book
        'author', 'publisher', 'pages', 'isbn_13', 'series_name', 'series_position',
    ];

    /**
     * Streams the library as CSV rows.
     *
     * Yields rather than building a string so a large library doesn't have to
     * fit in memory twice.
     *
     * @return \Generator<int, array<int, string|null>>
     */
    public function export(?MediaItemType $type = null): \Generator
    {
        yield self::COLUMNS;

        $query = MediaItem::query()
            ->with(['tags', 'musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderBy('type')
            ->orderBy('title');

        foreach ($query->lazy(200) as $item) {
            yield $this->toRow($item);
        }
    }

    /**
     * @return array<int, string|null>
     */
    private function toRow(MediaItem $item): array
    {
        $music = $item->musicMetadata;
        $movie = $item->movieMetadata;
        $show  = $item->showMetadata;
        $book  = $item->bookMetadata;

        $values = [
            'type'            => $item->type->value,
            'title'           => $item->title,
            'year'            => $item->year(),
            'owned'           => $item->owned ? '1' : '0',
            'wishlist'        => $item->wishlist ? '1' : '0',
            'user_rating'     => $item->user_rating,
            'genres'          => $item->tags->where('type', 'genre')->pluck('value')->implode('; '),
            'notes'           => $item->notes,

            'artist'          => $music?->artist,
            'album'           => $music?->album,
            'track_number'    => $music?->track_number,
            'label'           => $music?->label,
            'bpm'             => $music?->bpm,
            'key'             => $music?->key,
            'scale'           => $music?->scale,
            'isrc'            => $music?->isrc,

            'director'        => $movie?->director,
            'studio'          => $movie?->studio,
            'runtime_minutes' => $movie?->runtime_minutes,
            'imdb_id'         => $movie?->imdb_id,
            'tmdb_id'         => $movie?->tmdb_id ?? $show?->tmdb_id,

            'creator'         => $show?->creator,
            'network'         => $show?->network,
            'season_count'    => $show?->season_count,
            'episode_count'   => $show?->episode_count,
            'status'          => $show?->status,

            'author'          => $book?->author,
            'publisher'       => $book?->publisher,
            'pages'           => $book?->pages,
            'isbn_13'         => $book?->isbn_13,
            'series_name'     => $book?->series_name,
            'series_position' => $book?->series_position,
        ];

        return array_map(
            fn (string $column) => $this->stringify($values[$column] ?? null),
            self::COLUMNS,
        );
    }

    private function stringify(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Imports rows from a CSV file.
     *
     * @param  callable|null  $onProgress  Called with each imported title.
     * @return array{imported: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $path, ?int $userId, ?callable $onProgress = null): array
    {
        // Silenced because the failure is handled on the next line. A missing
        // or unreadable upload is an ordinary outcome here, not an exception,
        // and the raw warning would surface as a stack trace instead of the
        // message below.
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Could not read the file.']];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return ['imported' => 0, 'skipped' => 0, 'errors' => ['The file is empty.']];
        }

        // Match on header names rather than position, so column order and any
        // extra columns from another tool don't matter.
        $index = array_flip(array_map(
            fn (string $name) => strtolower(trim($name)),
            $header,
        ));

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            $get = fn (string $column): ?string => $this->cell($row, $index, $column);

            $title = $get('title');
            $type = MediaItemType::tryFrom(strtolower((string) $get('type')));

            if (blank($title) || $type === null) {
                $skipped++;

                if (count($errors) < 10) {
                    $errors[] = "Line {$line}: missing a valid title or type.";
                }

                continue;
            }

            // Re-importing an export shouldn't duplicate the library.
            $exists = MediaItem::query()
                ->where('type', $type)
                ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            $item = MediaItem::create([
                'user_id'           => $userId,
                'type'              => $type,
                'title'             => $title,
                'notes'             => $get('notes'),
                'owned'             => $this->boolean($get('owned'), default: true),
                'wishlist'          => $this->boolean($get('wishlist'), default: false),
                'user_rating'       => $this->integer($get('user_rating')),
                'processing_status' => ProcessingStatus::Pending,
            ]);

            $this->writeMetadata($item, $type, $get);
            $this->writeGenres($item, $get('genres'));

            $imported++;

            if ($onProgress !== null) {
                $onProgress($title);
            }
        }

        fclose($handle);

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<int, string|null> $row
     * @param array<string, int> $index
     */
    private function cell(array $row, array $index, string $column): ?string
    {
        $position = $index[$column] ?? null;

        if ($position === null) {
            return null;
        }

        $value = trim((string) ($row[$position] ?? ''));

        return $value === '' ? null : $value;
    }

    private function writeMetadata(MediaItem $item, MediaItemType $type, callable $get): void
    {
        $year = $this->integer($get('year'));

        $attributes = match ($type) {
            MediaItemType::Music => [
                'artist'       => $get('artist'),
                'album'        => $get('album'),
                'track_number' => $this->integer($get('track_number')),
                'label'        => $get('label'),
                'bpm'          => $get('bpm') !== null ? (float) $get('bpm') : null,
                'key'          => $get('key'),
                'scale'        => $get('scale'),
                'isrc'         => $get('isrc'),
                'release_year' => $year,
            ],
            MediaItemType::Movie => [
                'director'        => $get('director'),
                'studio'          => $get('studio'),
                'runtime_minutes' => $this->integer($get('runtime_minutes')),
                'imdb_id'         => $get('imdb_id'),
                'tmdb_id'         => $this->integer($get('tmdb_id')),
                'release_year'    => $year,
            ],
            MediaItemType::Show => [
                'creator'        => $get('creator'),
                'network'        => $get('network'),
                'season_count'   => $this->integer($get('season_count')),
                'episode_count'  => $this->integer($get('episode_count')),
                'status'         => $get('status'),
                'tmdb_id'        => $this->integer($get('tmdb_id')),
                'first_air_year' => $year,
            ],
            MediaItemType::Book => [
                'author'          => $get('author'),
                'publisher'       => $get('publisher'),
                'pages'           => $this->integer($get('pages')),
                'isbn_13'         => $get('isbn_13'),
                'series_name'     => $get('series_name'),
                'series_position' => $this->integer($get('series_position')),
                'publish_year'    => $year,
            ],
        };

        // The relation row must exist even when empty — enrichment writes into
        // it rather than creating it.
        $item->metadata()->create(array_filter($attributes, fn ($value) => filled($value)));
    }

    private function writeGenres(MediaItem $item, ?string $genres): void
    {
        if (blank($genres)) {
            return;
        }

        // Semicolons separate genres so a comma inside one survives the CSV.
        foreach (preg_split('/[;,]/', $genres) as $genre) {
            $value = trim($genre);

            if ($value === '') {
                continue;
            }

            $item->tags()->create([
                'type'   => 'genre',
                'value'  => $value,
                'source' => MediaTagSource::Manual,
            ]);
        }
    }

    private function boolean(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'y'], true);
    }

    private function integer(?string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
