<?php

namespace App\Services;

use App\Enums\MediaItemType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the library holds, and what has actually been listened to.
 *
 * Every figure here is a query rather than a stored counter, because a counter
 * drifts the moment anything writes a play row without going through it — and
 * three things already do. At this size the queries are cheap: the largest
 * scans 8,300 rows.
 *
 * **Two things to know before trusting any number.** Listening *time* only
 * exists from 11 September 2026, when `listened_seconds` was added (S-117);
 * rows before that are null and are excluded rather than counted as zero. And
 * `plays` counts sessions, not completions — a ten-second skip is a play.
 * Where the two disagree, listening time is the better number, and the page
 * says which it is showing.
 */
class LibraryStatistics
{
    /** Top-N default. The request was for hundreds, not tens. */
    public const TOP = 100;

    public function __construct(private ContentGate $gate) {}

    /**
     * Which media type every figure is about.
     *
     * Set once by the page from the open tab, rather than threaded through
     * fourteen method signatures. Music is the default because it is 8,319 of
     * the 8,338 items here and the page opens on it.
     */
    private MediaItemType $type = MediaItemType::Music;

    public function for(MediaItemType $type): static
    {
        $clone = clone $this;
        $clone->type = $type;

        return $clone;
    }

    public function type(): MediaItemType
    {
        return $this->type;
    }

    /**
     * Episodes roll up under their series.
     *
     * A show is watched an episode at a time, so "top shows" counting episodes
     * separately would list the same series twenty times. Films, books and
     * tracks are their own parent and are unaffected.
     */
    private function rollsUp(): bool
    {
        return $this->type === MediaItemType::Show;
    }

    /**
     * Plays within a window, optionally one profile's.
     *
     * Every figure on the page starts here, so the date range and the profile
     * filter are applied in exactly one place — two call sites disagreeing
     * about what "this month" means is how a statistics page loses trust.
     */
    private function plays(?Carbon $from = null, ?Carbon $to = null, ?int $profileId = null)
    {
        return DB::table('media_plays')
            ->join('media_items', 'media_items.id', '=', 'media_plays.media_item_id')
            ->where('media_items.type', $this->type->value)
            ->when($from, fn ($q) => $q->where('media_plays.created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('media_plays.created_at', '<=', $to))
            ->when($profileId, fn ($q) => $q->where('media_plays.profile_id', $profileId));
    }

    /**
     * How each type is grouped, and what its parts are called.
     *
     * A "top artist" has no equivalent for a film — the comparable question is
     * the director — so each type names its own two groupings and the metadata
     * table they live on. Kept here rather than branched at six call sites.
     *
     * @return array{table: string, primary: string, primary_label: string, secondary: ?string, secondary_label: ?string, duration: ?string, duration_unit: int}
     */
    private function shape(): array
    {
        return match ($this->type) {
            MediaItemType::Music => [
                'table' => 'music_metadata',
                // The primary artist, falling back to the raw credit — the
                // same COALESCE the browse pages group on, or one artist
                // appears once per collaborator they ever recorded with.
                'primary' => "COALESCE(NULLIF(music_metadata.primary_artist, ''), music_metadata.artist)",
                'primary_label' => 'Artist',
                'secondary' => 'music_metadata.album',
                'secondary_label' => 'Album',
                'duration' => 'music_metadata.duration_ms',
                // Milliseconds to seconds.
                'duration_unit' => 1000,
            ],
            MediaItemType::Movie => [
                'table' => 'movie_metadata',
                'primary' => 'movie_metadata.director',
                'primary_label' => 'Director',
                'secondary' => 'movie_metadata.studio',
                'secondary_label' => 'Studio',
                'duration' => 'movie_metadata.runtime_minutes',
                // Minutes to seconds — and the reason this is a column rather
                // than a constant: the three types measure length differently.
                'duration_unit' => 1 / 60,
            ],
            MediaItemType::Show => [
                'table' => 'show_metadata',
                'primary' => 'show_metadata.creator',
                'primary_label' => 'Creator',
                'secondary' => 'show_metadata.network',
                'secondary_label' => 'Network',
                'duration' => null,
                'duration_unit' => 1,
            ],
            MediaItemType::Book => [
                'table' => 'book_metadata',
                'primary' => 'book_metadata.author',
                'primary_label' => 'Author',
                'secondary' => 'book_metadata.publisher',
                'secondary_label' => 'Publisher',
                'duration' => null,
                'duration_unit' => 1,
            ],
        };
    }

    /** What this type's groupings are called, for the page to label them. */
    public function labels(): array
    {
        $shape = $this->shape();

        return [
            'primary' => $shape['primary_label'],
            'secondary' => $shape['secondary_label'],
            'item' => match ($this->type) {
                MediaItemType::Music => 'Track',
                MediaItemType::Movie => 'Film',
                MediaItemType::Show => 'Episode',
                MediaItemType::Book => 'Book',
            },
        ];
    }

    /**
     * The library itself: how much of it there is.
     *
     * @return array<string, mixed>
     */
    public function library(): array
    {
        $shape = $this->shape();

        // Left-joined: an item with no metadata row still counts as held. An
        // inner join silently dropped every film that enrichment had not
        // reached, which reads as a smaller library rather than a gap.
        $base = DB::table('media_items')
            ->leftJoin($shape['table'], $shape['table'] . '.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', $this->type->value);

        $seconds = 0;
        $untimed = 0;

        if ($shape['duration'] !== null) {
            $totals = (clone $base)
                ->selectRaw("COALESCE(SUM({$shape['duration']}), 0) as total")
                ->selectRaw("SUM(CASE WHEN {$shape['duration']} IS NULL OR {$shape['duration']} = 0 THEN 1 ELSE 0 END) as untimed")
                ->first();

            $seconds = (int) round($totals->total / $shape['duration_unit']);
            $untimed = (int) $totals->untimed;
        }

        return [
            'items' => (int) (clone $base)->count(),
            'seconds' => $seconds,
            // Named, because a total from a library where some items carry no
            // duration is a floor rather than a total.
            'untimed' => $untimed,
            'primary' => (int) (clone $base)
                ->whereNotNull(DB::raw($shape['primary']))
                ->whereRaw("{$shape['primary']} != ''")
                ->distinct()
                ->count(DB::raw($shape['primary'])),
            'secondary' => $shape['secondary'] === null ? 0 : (int) (clone $base)
                ->whereNotNull($shape['secondary'])
                ->where($shape['secondary'], '!=', '')
                ->distinct()
                ->count($shape['secondary']),
            'genres' => (int) DB::table('media_tags')
                ->join('media_items', 'media_items.id', '=', 'media_tags.media_item_id')
                ->where('media_items.type', $this->type->value)
                ->where('media_tags.type', 'genre')
                ->distinct()
                ->count('media_tags.value'),
            'playlists' => (int) DB::table('collections')->count(),
        ];
    }

    /**
     * Listening totals for a window.
     *
     * @return array<string, mixed>
     */
    public function listening(?Carbon $from = null, ?Carbon $to = null, ?int $profileId = null): array
    {
        $row = $this->plays($from, $to, $profileId)
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COUNT(DISTINCT media_plays.media_item_id) as tracks')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->selectRaw('SUM(CASE WHEN media_plays.completed = 1 THEN 1 ELSE 0 END) as completed')
            // Rows from before listening time was recorded. Counted so the
            // page can say the total is understated rather than implying the
            // listening simply did not happen.
            ->selectRaw('SUM(CASE WHEN media_plays.listened_seconds IS NULL THEN 1 ELSE 0 END) as untimed')
            ->first();

        $plays = (int) $row->plays;

        return [
            'plays' => $plays,
            'tracks' => (int) $row->tracks,
            'seconds' => (int) $row->seconds,
            'completed' => (int) $row->completed,
            'untimed' => (int) $row->untimed,
            // A skip is a play. This is the number that says how often one
            // was actually seen through.
            'completion_rate' => $plays > 0 ? round(((int) $row->completed / $plays) * 100, 1) : 0.0,
        ];
    }

    /**
     * The most-played items.
     *
     * @return Collection<int, object>
     */
    public function topItems(?Carbon $from, ?Carbon $to, ?int $profileId, int $limit = self::TOP): Collection
    {
        $shape = $this->shape();

        // A show's plays belong to its series, not to each episode — twenty
        // episodes of one show would otherwise fill the whole list.
        $id = $this->rollsUp()
            ? 'COALESCE(media_items.parent_id, media_items.id)'
            : 'media_items.id';

        $rows = $this->plays($from, $to, $profileId)
            ->leftJoin($shape['table'], $shape['table'] . '.media_item_id', '=', 'media_items.id')
            ->selectRaw("{$id} as id")
            ->selectRaw("{$shape['primary']} as grouping")
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->groupBy(DB::raw($id), DB::raw($shape['primary']))
            ->orderByDesc('plays')
            ->limit($limit)
            ->get();

        // Titles fetched separately rather than grouped on: grouping by title
        // merges two different films that share a name.
        $titles = DB::table('media_items')
            ->whereIn('id', $rows->pluck('id')->all())
            ->pluck('title', 'id');

        return $rows->map(function (object $row) use ($titles): object {
            $row->title = $titles[$row->id] ?? '—';

            return $row;
        });
    }

    /**
     * The most-played by this type's primary grouping.
     *
     * Artist for music, director for film, creator for a show, author for a
     * book — the same question asked of whatever the type is organised around.
     *
     * @return Collection<int, object>
     */
    public function topPrimary(?Carbon $from, ?Carbon $to, ?int $profileId, int $limit = self::TOP): Collection
    {
        $shape = $this->shape();

        return $this->plays($from, $to, $profileId)
            ->join($shape['table'], $shape['table'] . '.media_item_id', '=', 'media_items.id')
            ->whereNotNull(DB::raw($shape['primary']))
            ->whereRaw("{$shape['primary']} != ''")
            ->selectRaw("{$shape['primary']} as grouping")
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COUNT(DISTINCT media_plays.media_item_id) as items')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->groupBy(DB::raw($shape['primary']))
            ->orderByDesc('plays')
            ->limit($limit)
            ->get();
    }

    /** @deprecated Use topPrimary(); kept so existing callers keep working. */
    public function topArtists(?Carbon $from, ?Carbon $to, ?int $profileId, int $limit = self::TOP): Collection
    {
        return $this->topPrimary($from, $to, $profileId, $limit)
            ->map(function (object $row): object {
                $row->artist = $row->grouping;
                $row->tracks = $row->items;

                return $row;
            });
    }

    /**
     * The most-played genres.
     *
     * A track carries several, so the plays here sum to more than the total —
     * which is correct, and is why this is a share rather than a breakdown.
     *
     * @return Collection<int, object>
     */
    public function topGenres(?Carbon $from, ?Carbon $to, ?int $profileId, int $limit = self::TOP): Collection
    {
        return $this->plays($from, $to, $profileId)
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_tags.type', 'genre')
            ->selectRaw('media_tags.value as genre')
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->groupBy('media_tags.value')
            ->orderByDesc('plays')
            ->limit($limit)
            ->get();
    }

    /**
     * The most-played playlists.
     *
     * By the plays of the tracks on them, because nothing records that a play
     * *came from* a playlist — that would need a source column on the play
     * row, which is worth having and is not there yet.
     *
     * @return Collection<int, object>
     */
    public function topPlaylists(?Carbon $from, ?Carbon $to, ?int $profileId, int $limit = self::TOP): Collection
    {
        return $this->plays($from, $to, $profileId)
            ->join('collection_media_item', 'collection_media_item.media_item_id', '=', 'media_items.id')
            ->join('collections', 'collections.id', '=', 'collection_media_item.collection_id')
            ->selectRaw('collections.id')
            ->selectRaw('collections.name')
            ->selectRaw('COUNT(*) as plays')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->groupBy('collections.id', 'collections.name')
            ->orderByDesc('plays')
            ->limit($limit)
            ->get();
    }

    /**
     * When listening happens — hour of day, and day of week.
     *
     * SQLite's strftime rather than a PHP fold, so a year of rows does not
     * have to travel into memory to be counted.
     *
     * @return array{hours: array<int, int>, days: array<int, int>}
     */
    public function clock(?Carbon $from, ?Carbon $to, ?int $profileId): array
    {
        $byHour = $this->plays($from, $to, $profileId)
            ->selectRaw("CAST(strftime('%H', media_plays.created_at) AS INTEGER) as slot")
            ->selectRaw('COUNT(*) as plays')
            ->groupBy('slot')
            ->pluck('plays', 'slot');

        // 0 = Sunday, which is what strftime('%w') returns.
        $byDay = $this->plays($from, $to, $profileId)
            ->selectRaw("CAST(strftime('%w', media_plays.created_at) AS INTEGER) as slot")
            ->selectRaw('COUNT(*) as plays')
            ->groupBy('slot')
            ->pluck('plays', 'slot');

        // Every slot present, so a chart draws a gap rather than skipping it —
        // 3am with no listening is information.
        return [
            'hours' => collect(range(0, 23))
                ->mapWithKeys(fn (int $h) => [$h => (int) ($byHour[$h] ?? 0)])
                ->all(),
            'days' => collect(range(0, 6))
                ->mapWithKeys(fn (int $d) => [$d => (int) ($byDay[$d] ?? 0)])
                ->all(),
        ];
    }

    /**
     * How much of the library has ever been played.
     *
     * The dark corners of a catalogue: 2,321 artists is a different statement
     * if eleven of them have ever been listened to.
     *
     * @return array<string, array{total: int, played: int}>
     */
    public function reach(?int $profileId = null): array
    {
        $shape = $this->shape();

        $played = DB::table('media_plays')
            ->when($profileId, fn ($q) => $q->where('profile_id', $profileId))
            ->select('media_item_id');

        $library = $this->library();

        $playedItems = (int) DB::table('media_items')
            ->where('type', $this->type->value)
            ->whereIn('id', $played)
            ->count();

        // Through the type's own metadata table, joined back to media_items so
        // one type's plays cannot count another's groupings.
        $playedPrimary = (int) DB::table($shape['table'])
            ->join('media_items', 'media_items.id', '=', $shape['table'] . '.media_item_id')
            ->where('media_items.type', $this->type->value)
            ->whereIn($shape['table'] . '.media_item_id', $played)
            ->whereNotNull(DB::raw($shape['primary']))
            ->whereRaw("{$shape['primary']} != ''")
            ->distinct()
            ->count(DB::raw($shape['primary']));

        $playedGenres = (int) DB::table('media_tags')
            ->join('media_items', 'media_items.id', '=', 'media_tags.media_item_id')
            ->where('media_items.type', $this->type->value)
            ->where('media_tags.type', 'genre')
            ->whereIn('media_tags.media_item_id', $played)
            ->distinct()
            ->count('media_tags.value');

        return [
            'items' => ['total' => $library['items'], 'played' => $playedItems],
            'primary' => ['total' => $library['primary'], 'played' => $playedPrimary],
            'genres' => ['total' => $library['genres'], 'played' => $playedGenres],
        ];
    }

    /**
     * First-time plays against re-listens, by day.
     *
     * A track is "new" on the day it was first played, whenever that was —
     * so a window shows discovery within it rather than re-counting a
     * favourite every time it comes round.
     *
     * @return Collection<int, object>
     */
    public function discovery(?Carbon $from, ?Carbon $to, ?int $profileId): Collection
    {
        // The day each track was first heard at all, which is what makes a
        // later play a repeat rather than a discovery.
        $firsts = DB::table('media_plays')
            ->when($profileId, fn ($q) => $q->where('profile_id', $profileId))
            ->selectRaw('media_item_id')
            ->selectRaw('MIN(created_at) as first_at')
            ->groupBy('media_item_id');

        return $this->plays($from, $to, $profileId)
            ->joinSub($firsts, 'firsts', 'firsts.media_item_id', '=', 'media_plays.media_item_id')
            ->selectRaw("date(media_plays.created_at) as day")
            ->selectRaw("SUM(CASE WHEN media_plays.created_at = firsts.first_at THEN 1 ELSE 0 END) as discovered")
            ->selectRaw("SUM(CASE WHEN media_plays.created_at > firsts.first_at THEN 1 ELSE 0 END) as repeated")
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * Days with any listening, and the longest unbroken run of them.
     *
     * @return array{days: int, longest_streak: int, current_streak: int, first: ?string, last: ?string}
     */
    public function streaks(?int $profileId = null): array
    {
        $days = $this->plays(null, null, $profileId)
            ->selectRaw('date(media_plays.created_at) as day')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('day')
            ->all();

        if ($days === []) {
            return ['days' => 0, 'longest_streak' => 0, 'current_streak' => 0, 'first' => null, 'last' => null];
        }

        $longest = $run = 1;
        $previous = Carbon::parse($days[0]);

        for ($i = 1; $i < count($days); $i++) {
            $day = Carbon::parse($days[$i]);

            // Consecutive calendar days, so a gap of one resets the run.
            //
            // Cast, because Carbon 3 returns a *float* from diffInDays — a
            // strict `=== 1` never matched and every streak was reported as 1,
            // which looked plausible enough to ship.
            $run = (int) $previous->diffInDays($day) === 1 ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $day;
        }

        $last = Carbon::parse($days[count($days) - 1]);

        // A run is only "current" if it reaches today or yesterday — a streak
        // that ended in March is not one someone is on.
        $current = (int) $last->diffInDays(Carbon::today()) <= 1 ? $run : 0;

        return [
            'days' => count($days),
            'longest_streak' => $longest,
            'current_streak' => $current,
            'first' => $days[0],
            'last' => $days[count($days) - 1],
        ];
    }

    /**
     * What the library costs, by artist and by genre.
     *
     * **Measured from disk, because no file size is stored anywhere.** There is
     * no `size_bytes` column — `MediaItem::playbackSize()` stats the file every
     * time it is asked, which is why it costs ~12ms per 48 tracks and why the
     * download endpoint's own comment calls it the slowest thing it does.
     *
     * So this is a sampled estimate rather than a total: the average size of a
     * sample, multiplied by the track count. Stating that plainly is better
     * than either an 8,300-stat page load or a made-up number, and the page
     * labels it as an estimate. A `size_bytes` column recorded at scan time
     * would make it exact and is worth doing (S-119).
     *
     * @return array{artists: Collection<int, object>, genres: Collection<int, object>, sampled: int, average: int}
     */
    public function storage(int $limit = 20, int $sample = 120): array
    {
        $shape = $this->shape();
        $primary = $shape['primary'];

        // One average for the whole library rather than per grouping: a
        // per-artist sample would be one or two files and would swing wildly.
        // Through the model rather than the raw column. Two kinds of path are
        // stored — uploads are relative to the storage disk, the folder
        // importer keeps absolute ones — and `absoluteFilePath()` is the only
        // thing that knows which is which. Reading the column directly found
        // zero files, because most of them are relative.
        $items = \App\Models\MediaItem::query()
            ->where('type', $this->type)
            ->whereNotNull('file_path')
            ->inRandomOrder()
            ->limit($sample)
            ->get(['id', 'file_path', 'converted_path']);

        $seen = 0;
        $bytes = 0;

        foreach ($items as $item) {
            $path = $item->absoluteFilePath();

            if ($path === null || ! is_file($path)) continue;

            $size = @filesize($path);

            if ($size === false) continue;

            $seen++;
            $bytes += $size;
        }

        $average = $seen > 0 ? (int) round($bytes / $seen) : 0;

        $artists = DB::table('media_items')
            ->leftJoin($shape['table'], $shape['table'] . '.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', $this->type->value)
            ->selectRaw("{$primary} as artist")
            ->selectRaw('COUNT(*) as tracks')
            ->whereNotNull(DB::raw($primary))
            ->whereRaw("{$primary} != ''")
            ->groupBy(DB::raw($primary))
            ->orderByDesc('tracks')
            ->limit($limit)
            ->get()
            ->map(function (object $row) use ($average): object {
                $row->bytes = $row->tracks * $average;

                return $row;
            });

        $genres = DB::table('media_items')
            ->join('media_tags', 'media_tags.media_item_id', '=', 'media_items.id')
            ->where('media_items.type', $this->type->value)
            ->where('media_tags.type', 'genre')
            ->selectRaw('media_tags.value as genre')
            ->selectRaw('COUNT(*) as tracks')
            ->groupBy('media_tags.value')
            ->orderByDesc('tracks')
            ->limit($limit)
            ->get()
            ->map(function (object $row) use ($average): object {
                $row->bytes = $row->tracks * $average;

                return $row;
            });

        return [
            'artists' => $artists,
            'genres' => $genres,
            'sampled' => $seen,
            'average' => $average,
        ];
    }

    /**
     * Every profile, so the page can offer them and compare.
     *
     * @return Collection<int, object>
     */
    public function profiles(): Collection
    {
        return DB::table('profiles')
            ->leftJoin('media_plays', 'media_plays.profile_id', '=', 'profiles.id')
            ->selectRaw('profiles.id')
            ->selectRaw('profiles.name')
            ->selectRaw('COUNT(media_plays.id) as plays')
            ->selectRaw('COALESCE(SUM(media_plays.listened_seconds), 0) as seconds')
            ->groupBy('profiles.id', 'profiles.name')
            ->orderByDesc('plays')
            ->get();
    }

    /**
     * Plays that no profile is named on.
     *
     * `recordPlay()` stamped only the account until 11 September 2026, so a
     * per-profile view of anything older is reading a fraction of the rows.
     * The page says how big that fraction is rather than quietly dropping it.
     */
    public function unattributedPlays(): int
    {
        return (int) DB::table('media_plays')->whereNull('profile_id')->count();
    }
}
