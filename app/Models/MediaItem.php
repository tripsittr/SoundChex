<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Models;

use App\Enums\DuplicateMatch;
use App\Enums\DuplicateStatus;
use App\Enums\MatchConfidence;
use App\Enums\MediaItemType;
use App\Enums\PipelineStage;
use App\Enums\PipelineState;
use App\Enums\ProcessingStatus;
use App\Enums\ReviewReason;
use App\Models\Scopes\ResolvedScope;
use App\Observers\MediaItemObserver;
use App\Plugins\Registry;
use App\Services\CurrentProfile;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

#[ObservedBy(MediaItemObserver::class)]
#[ScopedBy(ResolvedScope::class)]
class MediaItem extends Model
{
    /**
     * Every item, including the ones the library is hiding (S-396).
     *
     * For the admin panel, the maintenance commands and jobs, the transfer
     * endpoints and the health counts — everything whose job is to *find* the
     * unresolved items rather than to present a library. Reads as an
     * intention: `MediaItem::unresolved()` at a call site says "yes, I meant
     * to see these".
     */
    public static function unresolved(): Builder
    {
        return static::query()->withoutGlobalScope(ResolvedScope::class);
    }

    /**
     * Re-reads this row, hidden or not.
     *
     * `refresh()` and `fresh()` go through a scoped query, so an item the
     * library is hiding cannot re-read itself — and enrichment does exactly
     * that, eight times, on an item it has just set to `processing`. Without
     * this the pipeline throws on every item it touches.
     */
    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            static::unresolved()->findOrFail($this->getKey())->attributes,
        );

        $this->load(collect($this->relations)->reject(
            fn ($relation) => $relation instanceof Pivot,
        )->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /** As `refresh()`: a hidden item must still be able to re-read itself. */
    public function fresh($with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        return static::unresolved()
            ->with(is_string($with) ? func_get_args() : $with)
            ->find($this->getKey());
    }

    /**
     * Reports raised against this item.
     *
     * @return HasMany<MediaItemReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(MediaItemReport::class)->latest();
    }

    /**
     * Sends this item back for review, hiding it from the library (S-398).
     *
     * The hide is the point, not a side-effect: the owner's rule is that
     * anything uncertain leaves the library until it is settled (S-396), and
     * an item somebody has just called wrong is exactly that. The apps say so
     * plainly and make the user confirm before the reason is even asked for,
     * so this is never a surprise.
     *
     * Idempotent on the item: a second report on an already-hidden item adds
     * the report and leaves the status alone. `reviewed_at` is cleared because
     * an admin's earlier "I have looked at this" no longer holds once someone
     * has said it is still wrong.
     */
    public function sendForReview(
        ReviewReason $reason,
        ?string $note = null,
        ?int $userId = null,
        ?int $profileId = null,
    ): MediaItemReport {
        $report = $this->reports()->create([
            'reason' => $reason,
            'note' => $note,
            'user_id' => $userId,
            'profile_id' => $profileId,
        ]);

        $this->forceFill([
            'processing_status' => ProcessingStatus::NeedsReview,
            'reviewed_at' => null,
        ])->save();

        return $report;
    }

    /** Whether this item is hidden from the library. */
    public function isUnresolved(): bool
    {
        return $this->processing_status !== ProcessingStatus::Complete
            || (bool) $this->file_missing;
    }

    /**
     * A specific reason a source flagged this item for review, carried from the
     * source to the pipeline's report within one enrichment run.
     *
     * Transient — not a column. A source (MusicBrainz's compilation/undated
     * check) sets it when it flags the item, and MetadataPipeline reads it when
     * assembling the run's `review_reason`, so the recorded reason names the real
     * cause instead of a generic "ambiguous match" guess. Null when no source set
     * one.
     */
    public ?string $reviewReasonHint = null;

    protected $fillable = [
        'user_id',
        'parent_id',
        'type',
        'title',
        'external_id',
        'external_source',
        'cover_image_url',
        'file_path',
        'file_size',
        'converted_path',
        'archived_path',
        'transcode_status',
        'transcode_percent',
        'processing_status',
        'file_missing',
        'file_missing_at',
        'reviewed_at',
        'match_confidence',
        'matched_by',
        'source_service',
        'intro_start_seconds',
        'intro_end_seconds',
        'credits_start_seconds',
        'user_rating',
        'owned',
        'wishlist',
        'notes',
    ];

    protected $casts = [
        'type' => MediaItemType::class,
        'processing_status' => ProcessingStatus::class,
        'file_missing' => 'boolean',
        'file_missing_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'review_snoozed_until' => 'datetime',
        'match_confidence' => MatchConfidence::class,
        'duplicate_status' => DuplicateStatus::class,
        'duplicate_match' => DuplicateMatch::class,
        'duplicate_detected_at' => 'datetime',
        'needs_cover_review' => 'boolean',
        'owned' => 'boolean',
        'wishlist' => 'boolean',
        'file_size' => 'integer',
        // Written by the pipeline, not a form — like duplicate state, it is kept
        // out of $fillable and set with forceFill/saveQuietly.
        'enrichment_report' => 'array',
        // Pipeline state, likewise written only by the stage jobs (#489).
        'pipeline_stage' => PipelineStage::class,
        'pipeline_state' => PipelineState::class,
        'pipeline_attempts' => 'integer',
        'pipeline_updated_at' => 'datetime',
    ];

    /**
     * The earlier-catalogued item this one duplicates.
     *
     * Deliberately absent from $fillable: duplicate state is decided by the
     * detector after comparing bytes, never by a form submission.
     */
    public function duplicateOf(): BelongsTo
    {
        // Outside ResolvedScope on purpose. A duplicate's original is very
        // often unresolved itself — freshly imported, waiting on enrichment —
        // and the scope would hand back null for a row that plainly exists.
        // That is not a hidden item, it is a broken relation: the merge screen
        // passed the null straight into decideKeeper() and the page died with
        // "Argument #1 ($a) must be of type MediaItem, null given".
        //
        // Nothing user-facing reads this. Duplicate state is admin and
        // maintenance machinery — the detector, the merge screen, the
        // find-duplicates and reconcile commands — and every one of them needs
        // to see unresolved items, which is exactly what the scope's own
        // documentation says such callers must opt out of.
        return $this->belongsTo(self::class, 'duplicate_of_id')
            ->withoutGlobalScope(ResolvedScope::class);
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of_id');
    }

    /**
     * The series an episode belongs to. Null for everything else.
     */
    public function parent(): BelongsTo
    {
        // Same reasoning as `duplicateOf`: a series row carries no file of its
        // own and may sit unresolved for as long as its episodes take to
        // enrich. Hiding it would orphan every episode under it.
        return $this->belongsTo(self::class, 'parent_id')
            ->withoutGlobalScope(ResolvedScope::class);
    }

    /**
     * A series' episodes.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function subtitles(): HasMany
    {
        return $this->hasMany(Subtitle::class);
    }

    public function pageTexts(): HasMany
    {
        return $this->hasMany(PageText::class);
    }

    /** Reflowable text units for the reader (S-295). */
    public function bookContents(): HasMany
    {
        return $this->hasMany(BookContent::class);
    }

    /**
     * Size in bytes of the file that would actually be played or read.
     *
     * Prefers the converted copy, since that is what streaming serves — the
     * original may be an MKV several times larger that no browser decodes.
     * Used to warn before a download that won't fit.
     */
    public function playbackSize(): ?int
    {
        $path = $this->playbackPath();

        if ($path === null) {
            return null;
        }

        $absolute = Storage::path($path);

        if (! is_file($absolute)) {
            $absolute = $this->absoluteFilePath();
        }

        if ($absolute === null || ! is_file($absolute)) {
            return null;
        }

        $size = @filesize($absolute);

        return $size === false ? null : $size;
    }

    /**
     * The series an episode belongs to.
     *
     * Null on everything else. An episode is a MediaItem in its own right — it
     * has a file, a playback position and its own subtitles — so this links
     * the two rather than flattening a show into one row.
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Episodes of this series, in broadcast order. */
    public function episodes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->join('show_metadata', 'show_metadata.media_item_id', '=', 'media_items.id')
            ->orderBy('show_metadata.season_number')
            ->orderBy('show_metadata.episode_number')
            ->select('media_items.*');
    }

    /** Whether this row is an episode rather than a series or a film. */
    public function isEpisode(): bool
    {
        return $this->type === MediaItemType::Show
            && $this->showMetadata?->episode_number !== null;
    }

    /** Illustrations extracted from a book file. */
    public function bookAssets(): HasMany
    {
        return $this->hasMany(BookAsset::class);
    }

    /** The publisher's chapter outline. */
    public function bookChapters(): HasMany
    {
        return $this->hasMany(BookChapter::class)->orderBy('sort_order');
    }

    /** Past states of this item's metadata, newest first. */
    public function metadataVersions(): HasMany
    {
        return $this->hasMany(MetadataVersion::class)->latest('id');
    }

    /**
     * Admin edit URL for this item.
     *
     * Each type has its own Filament resource, so the slug has to follow the
     * type — a hardcoded one sent every film and book to the music resource,
     * which then 404s on an id it doesn't own.
     */
    public function adminEditUrl(): string
    {
        $slug = match ($this->type) {
            MediaItemType::Music => 'music',
            MediaItemType::Movie => 'movies',
            MediaItemType::Show => 'shows',
            MediaItemType::Book => 'books',
        };

        return url("/admin/{$slug}/{$this->id}/edit");
    }

    /** Flagged, and still awaiting a decision. */
    public function isPendingDuplicate(): bool
    {
        return $this->duplicate_status === DuplicateStatus::Pending;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(MediaTag::class);
    }

    public function plays(): HasMany
    {
        return $this->hasMany(MediaPlay::class);
    }

    /** Every user's place in this book. */
    public function readingProgress(): HasMany
    {
        return $this->hasMany(ReadingProgress::class);
    }

    /** Where this title can be streamed, rented, or bought right now. */
    public function availability(): HasMany
    {
        return $this->hasMany(MediaAvailability::class);
    }

    /**
     * The service this copy came from, resolved against the provider config.
     *
     * @return array{slug: string, name: string, color: string}|null
     */
    public function sourceService(): ?array
    {
        if (blank($this->source_service)) {
            return null;
        }

        $service = config("providers.services.{$this->source_service}");

        if ($service === null) {
            return null;
        }

        return [
            'slug' => $this->source_service,
            'name' => $service['name'],
            'color' => $service['color'],
        ];
    }

    /**
     * The signed-in user's place in this book, if they've opened it.
     */
    public function progressFor(?int $userId = null): ?ReadingProgress
    {
        $profileId = app(CurrentProfile::class)->id();

        if ($profileId !== null && $userId === null) {
            return $this->readingProgress()
                ->where('profile_id', $profileId)
                ->first();
        }

        $userId ??= Auth::id();

        if ($userId === null) {
            return null;
        }

        return $this->readingProgress()
            ->where('user_id', $userId)
            ->first();
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'media_item_person')
            ->withPivot(['role', 'character', 'sort_order'])
            ->orderByPivot('sort_order');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_media_item')
            ->withPivot('sort_order');
    }

    public function musicMetadata(): HasOne
    {
        return $this->hasOne(MusicMetadata::class);
    }

    public function movieMetadata(): HasOne
    {
        return $this->hasOne(MovieMetadata::class);
    }

    public function showMetadata(): HasOne
    {
        return $this->hasOne(ShowMetadata::class);
    }

    public function bookMetadata(): HasOne
    {
        return $this->hasOne(BookMetadata::class);
    }

    /** Convenience accessor to get the correct type-specific metadata. */
    public function metadata(): HasOne
    {
        return match ($this->type) {
            MediaItemType::Music => $this->musicMetadata(),
            MediaItemType::Movie => $this->movieMetadata(),
            MediaItemType::Show => $this->showMetadata(),
            MediaItemType::Book => $this->bookMetadata(),
        };
    }

    /**
     * The line shown beneath a title in the media center — artist for music,
     * director for a movie, author for a book, and so on. Keeps per-type
     * branching out of the views.
     */
    public function subtitle(): ?string
    {
        $subtitle = match ($this->type) {
            MediaItemType::Music => $this->musicMetadata?->artist,
            MediaItemType::Movie => $this->movieMetadata?->director,
            MediaItemType::Show => $this->showMetadata?->creator ?? $this->showMetadata?->network,
            MediaItemType::Book => $this->bookMetadata?->author,
        };

        // The one line under a title, everywhere a row is drawn — so a plugin
        // that wants "Artist · Album" instead of the artist alone changes it
        // once here rather than in every view (S-317).
        return app(Registry::class)->apply('item.subtitle', $subtitle, $this);
    }

    /** The release year, wherever it lives for this type. */
    /**
     * How far through this the viewing profile is, as a whole percent.
     *
     * Null for anything never started, anything finished, and anything whose
     * length is unknown -- a fraction of an unknown duration is a guess
     * presented as fact, and a full bar on a finished item says the opposite
     * of what it means.
     *
     * Reads the loaded `plays` relation and returns null when it is absent,
     * rather than querying: this is called once per tile on a page of them.
     */
    public function resumePercent(): ?int
    {
        if (! $this->relationLoaded('plays')) {
            return null;
        }

        $profileId = app(CurrentProfile::class)->id();

        // Never the unfiltered set: two people share a login, and that would
        // draw one person's progress on the other's screen.
        if ($profileId === null) {
            return null;
        }

        $seconds = $this->plays
            ->where('profile_id', $profileId)
            ->where('completed', false)
            ->sortByDesc('updated_at')
            ->first(fn ($play) => $play->position_seconds > 0)
            ?->position_seconds;

        // Same rule as `plays` above: a probe that was not eager-loaded is
        // answered as "unknown" rather than fetched. This runs once per tile
        // on a page of them, and a lazy `probe` here was a query per card --
        // caught by the home-page cost guard, which is what it is for.
        if ($seconds === null || ! $this->relationLoaded('probe')) {
            return null;
        }

        $ms = $this->probe?->duration_ms;

        if ($ms === null || $ms <= 0) {
            return null;
        }

        return min(100, (int) round($seconds / ($ms / 1000) * 100));
    }

    public function year(): ?int
    {
        return match ($this->type) {
            MediaItemType::Music => $this->musicMetadata?->release_year,
            MediaItemType::Movie => $this->movieMetadata?->release_year,
            MediaItemType::Show => $this->showMetadata?->first_air_year,
            MediaItemType::Book => $this->bookMetadata?->publish_year,
        };
    }

    /**
     * Music is shown as square album art; everything else uses a 2:3 poster.
     */
    public function artworkAspect(): string
    {
        return $this->type === MediaItemType::Music
            ? 'aspect-square-art'
            : 'aspect-poster';
    }

    /**
     * The URL to render for this item's artwork.
     *
     * `cover_image_url` holds one of two things: a full URL for artwork hosted
     * elsewhere (TMDB, Open Library, iTunes), or a path relative to the public
     * disk for art we extracted ourselves. Relative paths are resolved against
     * the *current* request host, so the same library works on localhost, a
     * LAN IP, and a tunnel without rewriting a single row.
     *
     * Storing an absolute URL for local art would bake one hostname into the
     * database and break every other way of reaching the server.
     */
    /**
     * The title as a filename a `Content-Disposition` header can carry.
     *
     * Symfony refuses a filename containing `/` or `\` outright — it throws
     * rather than escaping — so a track called "AM/PM" made `response()->file()`
     * raise a 500 before a single byte was sent, and every client reported it
     * as simply not playing (S-393). 33 tracks in one real library were
     * affected.
     *
     * Separators become a dash rather than being dropped: "AM/PM" reads as
     * "AM-PM", where "AMPM" reads as a typo. A title that sanitises to nothing
     * falls back to the id, because an empty filename is its own kind of
     * broken.
     */
    public function downloadFilename(): string
    {
        $title = str_replace(['/', '\\'], '-', (string) $this->title);

        // Control characters would break the header in a different way; the
        // header is assembled from a value this server does not control.
        $title = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $title));

        return $title !== '' ? $title : 'media-'.$this->id;
    }

    public function coverUrl(): ?string
    {
        $value = $this->cover_image_url;

        if (blank($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        // Path segments may contain spaces and commas from artist/album names.
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($value, '/'))));

        return url('storage/'.$encoded);
    }

    /**
     * Absolute path to the item's file on disk, or null if there isn't one.
     *
     * Two kinds of path are stored. Uploads land on the storage disk and are
     * kept relative to it; the folder importer registers files where they
     * already live and stores an absolute path, so a large collection isn't
     * duplicated. Everything that touches the file resolves it through here.
     */
    public function absoluteFilePath(): ?string
    {
        if (blank($this->file_path)) {
            return null;
        }

        if ($this->isAbsolutePath($this->file_path)) {
            return is_readable($this->file_path) ? $this->file_path : null;
        }

        $path = Storage::path($this->file_path);

        return is_readable($path) ? $path : null;
    }

    /**
     * Where the item's file is *supposed* to be, readable or not.
     *
     * `absoluteFilePath()` answers null when the file cannot be read, which
     * makes it useless for the one question the missing-file sweep needs to
     * ask: given that this file is gone, was its whole volume gone too, or
     * just the file? That needs the intended location (S-389).
     */
    public function expectedFilePath(): ?string
    {
        if (blank($this->file_path)) {
            return null;
        }

        return $this->isAbsolutePath($this->file_path)
            ? $this->file_path
            : Storage::path($this->file_path);
    }

    /**
     * Whether a stored path is absolute.
     *
     * A leading separator is the whole test on Unix and misses every Windows
     * path: "C:\\Users\\..." does not start with one, so an absolute path
     * would be treated as relative, prefixed with the storage root, and found
     * unreadable — every file in the library at once.
     */
    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        // A drive letter, and a UNC share for a library on a NAS.
        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    /**
     * Everything needing a person about this item (#489).
     *
     * One record per reason, replacing review-as-a-status: five columns and a
     * reports table could say THAT something was wrong but not why, what the
     * evidence was, or what to do -- so a new failure kind had nowhere to go
     * and went nowhere.
     */
    public function reviewItems(): HasMany
    {
        return $this->hasMany(ReviewItem::class);
    }

    /**
     * What ffprobe found in this file (#489).
     *
     * One probe per item, replaced when re-run: a probe measures the bytes as
     * they are now, and after a remux the old numbers describe a file that no
     * longer exists.
     */
    public function probe(): HasOne
    {
        return $this->hasOne(MediaProbe::class);
    }

    /**
     * Everything wrong with this file.
     *
     * A list rather than one verdict, because a file can be several kinds of
     * wrong at once and each is separately resolvable.
     */
    public function qualityFindings(): HasMany
    {
        return $this->hasMany(QualityFinding::class);
    }

    /**
     * The edition this title names, if it names one.
     *
     * A `" - "` suffix on a music title is usually an **edition marker**, not
     * noise: "Psycho Killer - Acoustic", "1979 - Remastered 2012", "Goodbye
     * Horses - Single". 772 titles in this library carry one, and three of them
     * are in the review queue right now.
     *
     * Surfaced rather than stripped, because stripping it merges recordings
     * that must stay apart — the rule the user set for versions (#489): if
     * Spotify shows fifteen versions of a song, so should we. Shown as evidence
     * on the review screen so a person can see what distinguishes two copies.
     *
     * Deliberately conservative: it only reads a suffix that follows a spaced
     * hyphen or en dash, which is the convention streaming services use, and
     * it never rewrites the title.
     */
    public function editionSuffix(): ?string
    {
        if ($this->type !== MediaItemType::Music) {
            return null;
        }

        if (preg_match('/\s[-\x{2013}]\s(.+)$/u', (string) $this->title, $match) !== 1) {
            return null;
        }

        $suffix = trim($match[1]);

        if ($suffix === '') {
            return null;
        }

        // A suffix that is just the artist's own name is not an edition -- it
        // is the scanner having written the credit into the title (#489).
        // Measured: 126 of this library's 772 suffixed titles are this, so
        // treating them as editions would invent 126 editions that do not
        // exist and then group real versions under them.
        //
        // Compared without spaces or punctuation, because the title's copy of
        // the name is rarely spelled the way the artist field spells it:
        // "R E M" against "R.E.M.", "Portugal The Man" against
        // "Portugal. The Man".
        $artist = $this->musicMetadata?->primary_artist ?: $this->musicMetadata?->artist;

        if (filled($artist) && $this->squash($suffix) === $this->squash((string) $artist)) {
            return null;
        }

        return $suffix;
    }

    /** Lower-cased with spaces and punctuation removed, for comparing names. */
    private function squash(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($value));
    }

    /**
     * Whether the file's own embedded tags named its artist.
     *
     * Recorded by `FileTagger`, the one source that can tell an embedded tag
     * from a filename guess or a later API match. It gates filing for music
     * (`LibraryOrganizer::isConfidentEnoughToMove()`): tags are authoritative
     * about the file, API data is not, and before #489 the two were
     * indistinguishable once a run had finished.
     *
     * Absent for anything enriched before this was recorded, which reads as
     * false — such an item simply needs `Exact` to be filed, which is the
     * cautious answer.
     */
    public function hasTaggedArtist(): bool
    {
        return (bool) ($this->enrichment_report['tagged_artist'] ?? false);
    }

    /** Whether the underlying file is present and readable. */
    public function hasReadableFile(): bool
    {
        return $this->absoluteFilePath() !== null;
    }

    /**
     * Whether this item can be watched in the browser.
     *
     * Container support is the browser's decision, not ours, but MKV and AVI
     * effectively never play — so they're offered as a download instead of a
     * player that shows a black rectangle.
     */
    public function isPlayableVideo(): bool
    {
        if (! in_array($this->type, [MediaItemType::Movie, MediaItemType::Show], true)) {
            return false;
        }

        // A converted copy exists precisely because the original wasn't
        // playable, so its presence settles the question.
        if ($this->hasConvertedCopy()) {
            return true;
        }

        $extension = strtolower(pathinfo((string) $this->file_path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'm4v', 'webm', 'mov', 'ogv'], true);
    }

    /** Whether a browser-playable conversion has been produced and still exists. */
    public function hasConvertedCopy(): bool
    {
        return filled($this->converted_path)
            && file_exists(Storage::path($this->converted_path));
    }

    /**
     * The source this item was made from, if it was replaced by a conversion.
     *
     * A film whose playable version was filed in its place keeps its original
     * in the archive — the better quality, and what makes a bad transcode
     * recoverable. Downloads offer this when it exists.
     */
    public function originalPath(): ?string
    {
        if (blank($this->archived_path)) {
            return $this->absoluteFilePath();
        }

        $path = Storage::path($this->archived_path);

        // Falls back rather than returning a path to nothing: an archive
        // someone has moved or pruned should not break the download button.
        return file_exists($path) ? $path : $this->absoluteFilePath();
    }

    /** Whether an original is set aside in the archive. */
    public function hasArchivedOriginal(): bool
    {
        return filled($this->archived_path)
            && file_exists(Storage::path($this->archived_path));
    }

    /**
     * The file playback should actually stream.
     *
     * Prefers the converted copy when there is one; the original stays the
     * file offered for download, since it's the better quality.
     */
    public function playbackPath(): ?string
    {
        $direct = $this->directReadablePlaybackPath();

        if ($direct !== null) {
            return $direct;
        }

        // Historical duplicate merges could leave the "original" row pointing
        // at a path that no longer exists while a linked duplicate still points
        // at the surviving file. Repoint once here so stream/download do not
        // require a manual reconciliation run.
        if ($this->repointToReadableLinkedFile()) {
            return $this->directReadablePlaybackPath();
        }

        return $this->fallbackReadablePlaybackPath();
    }

    /**
     * Repoints this row to a readable linked file when its own path is stale.
     */
    private function repointToReadableLinkedFile(): bool
    {
        if ($this->absoluteFilePath() !== null) {
            return false;
        }

        foreach ($this->readableLinkedCandidates() as $candidate) {
            $candidatePath = $candidate->absoluteFilePath();

            if ($candidatePath === null || blank($candidate->file_path)) {
                continue;
            }

            $this->forceFill(['file_path' => $candidate->file_path])->saveQuietly();

            return true;
        }

        return false;
    }

    /**
     * The item's own readable playback file, without consulting duplicates.
     */
    private function directReadablePlaybackPath(): ?string
    {
        if ($this->hasConvertedCopy()) {
            return Storage::path($this->converted_path);
        }

        return $this->absoluteFilePath();
    }

    /**
     * A readable playback file from a linked duplicate/original row.
     *
     * A stale "original" can survive while one of its duplicate rows still
     * points at the real filed copy. Falling back here keeps stream/download
     * working until reconciliation repoints the stale row.
     */
    private function fallbackReadablePlaybackPath(): ?string
    {
        foreach ($this->readableLinkedCandidates() as $candidate) {
            $path = $candidate->directReadablePlaybackPath();

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    /**
     * The memoised result of `readableLinkedCandidates()` for this instance.
     *
     * Not a relation — it is a union of three of them — so Eloquent cannot
     * eager-load it and the guard has to live here.
     */
    private ?EloquentCollection $readableLinkedCandidates = null;

    /**
     * Linked candidates that may hold the surviving readable copy.
     *
     * Three ways a row can be linked to the same underlying file: the original
     * it was filed under, that original's other duplicates (its siblings), and
     * its own duplicates.
     *
     * @return EloquentCollection<int, self>
     */
    private function readableLinkedCandidates(): EloquentCollection
    {
        // Memoised per instance: a stale path sends both the repoint attempt
        // and the fallback through here, and the album page asks for a
        // playback path several times per track while building its payload.
        // Unmemoised that was six identical duplicate look-ups a track — 84
        // queries on a 14-track album (S-362).
        if ($this->readableLinkedCandidates !== null) {
            return $this->readableLinkedCandidates;
        }

        $candidateIds = collect();

        if ($this->duplicate_of_id !== null) {
            $candidateIds->push((int) $this->duplicate_of_id);

            $candidateIds = $candidateIds->merge(
                static::query()
                    ->where('duplicate_of_id', $this->duplicate_of_id)
                    ->where('id', '!=', $this->id)
                    ->pluck('id'),
            );
        }

        $candidateIds = $candidateIds
            // Through the relation, not a fresh query: the listing pages eager
            // load `duplicates`, and `->duplicates()->pluck()` would ignore
            // that and ask again once per row (S-362).
            ->merge($this->duplicates->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($candidateIds->isEmpty()) {
            return $this->readableLinkedCandidates = new EloquentCollection;
        }

        return $this->readableLinkedCandidates = static::query()->whereIn('id', $candidateIds)->get();
    }

    /**
     * Skip windows the player offers a button for.
     *
     * Only whole, sensible ranges are returned — a marker pair that's missing
     * an end, or ends before it starts, would produce a button that skips
     * backwards or nowhere.
     *
     * @return array<string, array{start: int, end: int|null, label: string}>
     */
    public function skipMarkers(): array
    {
        $markers = [];

        $introStart = $this->intro_start_seconds;
        $introEnd = $this->intro_end_seconds;

        if ($introEnd !== null && $introEnd > ($introStart ?? 0)) {
            $markers['intro'] = [
                'start' => $introStart ?? 0,
                'end' => $introEnd,
                'label' => 'Skip Intro',
            ];
        }

        if ($this->credits_start_seconds !== null) {
            $markers['credits'] = [
                'start' => $this->credits_start_seconds,
                // No end: credits run to the file's end, so the button seeks
                // there rather than to a fixed timestamp.
                'end' => null,
                'label' => 'Skip Credits',
            ];
        }

        return $markers;
    }

    /**
     * The shape the front-end player expects for one queue entry.
     *
     * Built here rather than in Blade so every play button — poster, row,
     * detail page — sends an identical payload.
     *
     * @return array<string, mixed>
     */
    public function playerPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle(),
            'album' => $this->musicMetadata?->album,
            'artwork' => $this->coverUrl(),
            'src' => route('media.stream', $this),
            'url' => route('media.show', $this),
            'type' => $this->type->value,
            // Anything effectively finished starts over rather than resuming
            // three seconds from the end.
            'resumeAt' => $this->resumePosition(),
        ];
    }

    /**
     * The album this track belongs to, in playing order.
     *
     * Pressing play on one track should continue through the record rather
     * than stopping after it. A track with no album queues just itself.
     *
     * @return \Illuminate\Support\Collection<int, MediaItem>
     */
    public function albumQueue(): \Illuminate\Support\Collection
    {
        $meta = $this->musicMetadata;

        if ($this->type !== MediaItemType::Music || blank($meta?->album)) {
            return collect([$this]);
        }

        return static::query()
            ->where('type', MediaItemType::Music)
            ->whereNotNull('file_path')
            ->whereHas('musicMetadata', fn ($query) => $query
                ->where('album', $meta->album)
                // Same album title by a different artist is a different record.
                ->when(filled($meta->artist), fn ($q) => $q->where('artist', $meta->artist)))
            // `plays` too: the detail page turns this into a player payload
            // per track, and each one asks for a resume position.
            ->with(['musicMetadata', 'plays'])
            ->get()
            // Sorted in PHP because track_number lives on the joined table and
            // is frequently null for singles.
            //
            // Bulk-download tools often write a playlist position into the
            // track tag, so values run into the hundreds. Those still order an
            // album correctly relative to each other, but anything above a
            // plausible disc length is treated as unreliable and falls back to
            // title so the queue never looks arbitrary.
            // `sortBy` with an array of closures treats them as key/direction
            // pairs rather than successive comparators, so an explicit
            // comparison is the only way to get numeric ordering here.
            ->sort(function (MediaItem $a, MediaItem $b): int {
                // Untracked singles sort after everything numbered.
                $left = $a->musicMetadata?->track_number ?? PHP_INT_MAX;
                $right = $b->musicMetadata?->track_number ?? PHP_INT_MAX;

                return $left === $right
                    ? strcmp((string) $a->title, (string) $b->title)
                    : $left <=> $right;
            })
            ->values();
    }

    /**
     * Seconds to resume from, or null to start at the beginning.
     */
    public function resumePosition(): ?int
    {
        $profileId = app(CurrentProfile::class)->id();

        // Answered from an already-loaded `plays` relation when there is one,
        // so a list can eager-load once instead of querying per row. A songs
        // page builds a payload for every track, and each one asking for
        // itself was 121 queries on a 48-song page.
        //
        // The filtering is duplicated rather than shared because the two run
        // in different places — SQL there, PHP here — and keeping them in one
        // expression would mean loading every play row to filter it in memory.
        if ($this->relationLoaded('plays')) {
            $play = $this->plays
                ->when($profileId, fn ($plays) => $plays->where('profile_id', $profileId))
                ->when(! $profileId, fn ($plays) => $plays->where('user_id', Auth::id()))
                ->where('completed', false)
                ->sortByDesc('id')
                ->first();

            return $play?->position_seconds ?: null;
        }

        $play = $this->plays()
            // Scoped to the profile, not the account: two people sharing a
            // login must not resume into each other's film.
            ->when($profileId, fn ($query) => $query->where('profile_id', $profileId))
            ->when(! $profileId, fn ($query) => $query->where('user_id', Auth::id()))
            ->where('completed', false)
            ->latest('id')
            ->first();

        return $play?->position_seconds ?: null;
    }

    /** Fallback glyph when an item has no artwork. */
    public function typeGlyph(): string
    {
        return match ($this->type) {
            MediaItemType::Music => '♪',
            MediaItemType::Movie => '▶',
            MediaItemType::Show => '📺',
            MediaItemType::Book => '📖',
        };
    }
}
