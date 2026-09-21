<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Events\PlaybackCompleted;
use App\Events\PlaybackRecorded;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use App\Services\MediaBrowser;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The media center at /app — the catalog everyone uses.
 *
 * SoundChex is self-hosted: one server, one library, many accounts. Any
 * signed-in user sees the whole catalog. Filament owns /admin for management.
 */
class MediaCenterController extends Controller
{
    /**
     * The largest forward jump counted as listening rather than a seek.
     *
     * The player reports at most once every 10 seconds (`reportProgress()`),
     * so a normal step is ~10 and anything much larger is a scrubber drag or a
     * tab that was backgrounded and caught up in one go. 90 seconds is
     * deliberately generous against that 10: a slow request over a relay, or a
     * phone that suspended the webview for a minute, should still count what
     * was genuinely played. Beyond it, the movement is not listening and
     * banking it would let one drag of the scrubber claim a whole track.
     */
    private const LISTENED_MAX_STEP = 90;

    public function __construct(private readonly MediaBrowser $browser) {}

    /**
     * Landing page. Leads with a hero, then a rail per type — a mixed home
     * that shows the shape of the whole library at a glance.
     */
    public function home(): View
    {
        $rows = [];
        $hero = null;

        // Anything half-finished leads, the way a streaming app resumes rather
        // than making you find your place again. Watching comes first: a film
        // left mid-way is the most likely reason someone opened the app.
        $continueWatching = $this->browser->continueWatching();

        if ($continueWatching->isNotEmpty()) {
            $rows[] = [
                'key' => 'continue-watching',
                'title' => 'Continue Watching',
                'items' => $continueWatching,
                'viewAllUrl' => route('media.browse', MediaItemType::Movie->value),
            ];
        }

        // What this profile has deliberately set aside, before the generic
        // per-type rows — an explicit choice outranks "recently added".
        $watchlist = app(CurrentProfile::class)->get()?->watchlist()->limit(20)->get();

        if ($watchlist?->isNotEmpty()) {
            $rows[] = [
                'key' => 'watchlist',
                'title' => 'My List',
                'items' => $watchlist,
                'viewAllUrl' => null,
            ];
        }

        $continueReading = $this->browser->continueReading();

        if ($continueReading->isNotEmpty()) {
            $rows[] = [
                'key' => 'continue-reading',
                'title' => 'Continue Reading',
                'items' => $continueReading,
                'viewAllUrl' => route('media.browse', MediaItemType::Book->value),
            ];
        }

        foreach (MediaItemType::cases() as $type) {
            // Just this one rail. Asking for the whole browse page and keeping
            // its first row built four fixed rails and up to four genre rails
            // per type, then discarded seven of the eight.
            $items = $this->browser->recentlyAdded($type);

            if ($items->isEmpty()) {
                continue;
            }

            $hero ??= $this->browser->hero($type);

            $rows[] = [
                'key' => $type->value,
                'title' => str($type->label())->plural()->toString(),
                'items' => $items,
                'viewAllUrl' => route('media.browse', $type->value),
            ];
        }

        return view('media.home', [
            'counts' => $this->browser->counts(),
            'hero' => $hero,
            'rows' => $rows,
        ]);
    }

    /**
     * Movies and shows together, with a sub-nav to narrow to one.
     *
     * They're browsed as one thing — "what can I watch tonight" rarely starts
     * with deciding between a film and an episode — so All is the default and
     * the split is a filter rather than a separate page.
     */
    public function watch(Request $request): View
    {
        $video = [MediaItemType::Movie, MediaItemType::Show];

        $section = $request->query('section');
        $types = match ($section) {
            'movie' => [MediaItemType::Movie],
            'show' => [MediaItemType::Show],
            default => $video,
        };

        $filters = $request->only(['search', 'genre', 'owned', 'wishlist', 'service']);
        $isFiltered = collect($filters)->filter()->isNotEmpty();

        return view('media.watch-index', [
            'counts' => $this->browser->counts(),
            'section' => in_array($section, ['movie', 'show'], true) ? $section : 'all',
            'types' => $types,
            // Services span both types regardless of the active sub-nav, so
            // switching sections doesn't make the row jump around.
            'services' => $this->browser->servicesFor($video),
            'continue' => $isFiltered ? collect() : $this->browser->continueWatching(),
            'hero' => $isFiltered ? null : $this->browser->hero($types),
            'rows' => $isFiltered ? [] : $this->browser->rowsForType($types),
            'items' => $this->browser->grid($types, $filters),
            'genres' => $this->browser->genresFor($types),
            'filters' => $filters,
            'isFiltered' => $isFiltered,
        ]);
    }

    /**
     * Per-type browse page: hero, rails, then a full grid.
     */
    public function browse(Request $request, string $type): View
    {
        $mediaType = MediaItemType::tryFrom($type)
            ?? throw new NotFoundHttpException("Unknown media type [{$type}].");

        $filters = $request->only(['search', 'genre', 'owned', 'wishlist']);
        $isFiltered = collect($filters)->filter()->isNotEmpty();

        return view('media.browse', [
            'counts' => $this->browser->counts(),
            'type' => $mediaType,
            // Rails are a discovery aid; once the user filters, they want the
            // grid to be the answer, so the rails step out of the way.
            //
            // Music has no hero at all. A hero sells one title, which is how a
            // film library works — a music library opens on what you were
            // listening to and what is new, and a full-screen image of a
            // single track pushes all of that below the fold.
            'hero' => $isFiltered || $mediaType === MediaItemType::Music
                ? null
                : $this->browser->hero($mediaType),
            'rows' => $isFiltered ? [] : $this->browser->rowsForType($mediaType),
            'items' => $this->browser->grid($mediaType, $filters),
            'genres' => $this->browser->genresFor($mediaType),
            'filters' => $filters,
            'isFiltered' => $isFiltered,
        ]);
    }

    /**
     * Full-bleed detail page for a single item.
     */
    public function show(MediaItem $item): View
    {
        // A restricted profile must not reach a capped title by direct link.
        abort_unless(app(ContentGate::class)->allows($item), 404);

        $item->load([
            'tags', 'people', 'collections',
            'musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata',
        ]);

        return view('media.show', [
            'counts' => $this->browser->counts(),
            'item' => $item,
            'related' => $this->relatedTo($item),
        ]);
    }

    /**
     * Streams an uploaded audio file.
     *
     * Media lives on the private disk with no public URL, so playback is
     * proxied through here behind the auth middleware.
     */
    public function stream(MediaItem $item): BinaryFileResponse
    {
        // The bytes themselves, so a capped title can't be fetched directly.
        abort_unless(app(ContentGate::class)->allows($item), 404);

        // Prefers a converted copy when one exists — the original may be a
        // container or codec no browser decodes.
        $path = $item->playbackPath();

        abort_unless($path !== null, 404);

        $this->recordPlay($item);

        // A real file response rather than a stream: it sets Accept-Ranges, so
        // the browser can seek within a track instead of refetching it.
        // `makeDisposition()` rather than quoting the title by hand.
        //
        // `addslashes()` escapes quotes and leaves CRLF untouched, and a title
        // comes from a file's own tags or a metadata provider — neither of
        // which this server controls. A newline in one would have ended the
        // header and begun another, which is header injection with the
        // library as the payload. Symfony percent-encodes the UTF-8 form and
        // supplies an ASCII fallback for clients that cannot read it.
        return response()->file($path, [
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                (string) $item->title,
                'media',
            ),
        ]);
    }

    /**
     * Records playback position so a track or film resumes where it stopped.
     *
     * Called every few seconds while playing, so it's deliberately cheap: one
     * upsert against the most recent play row, no events, no extra queries.
     */
    public function saveProgress(Request $request, MediaItem $item): JsonResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', 'min:0'],
            'duration' => ['nullable', 'integer', 'min:0'],
            // When the device recorded this. Offline writes are queued and
            // replayed later, so a request can arrive well after the moment it
            // describes — and must not overwrite progress made since.
            'recorded_at' => ['nullable', 'date'],
        ]);

        $userId = Auth::id();

        // Position belongs to the person watching, not the account — two
        // people sharing a login keep separate places in the same film.
        $profileId = app(CurrentProfile::class)->id();

        // One row per listening session rather than per position update: reuse
        // the recent row, otherwise a single film would write hundreds.
        $play = $item->plays()
            ->when($profileId, fn ($query) => $query->where('profile_id', $profileId))
            ->when(! $profileId, fn ($query) => $query->where('user_id', $userId))
            ->where('updated_at', '>=', now()->subHours(6))
            ->latest('id')
            ->first();

        $duration = $data['duration'] ?? 0;

        // Past 95% counts as finished — credits and trailing silence mean
        // almost nothing is ever played to its literal final second.
        $completed = $duration > 0 && $data['position'] >= $duration * 0.95;

        // A replayed write is only applied if it is newer than what is already
        // stored. Without this, reconnecting after a drive replays an hour-old
        // position over the one from the episode being watched now — the
        // device would silently undo the user's own progress.
        //
        // Checked before the row is created, not after: creating first stamps
        // updated_at with "now", which makes every queued write look stale
        // against a row this request just made.
        $recordedAt = isset($data['recorded_at'])
            ? Carbon::parse($data['recorded_at'])
            : now();

        if ($play !== null && $play->updated_at !== null && $recordedAt->lt($play->updated_at)) {
            return response()->json([
                'completed' => (bool) $play->completed,
                'stale' => true,
            ]);
        }

        if ($play === null) {
            $play = $item->plays()->create([
                'user_id' => $userId,
                'profile_id' => $profileId,
            ]);
        }

        // How much was actually listened, accumulated from the movement
        // between saves.
        //
        // `position_seconds` cannot answer this: it is overwritten every time,
        // so a track played twice to 3:00 is indistinguishable from one played
        // once. And plays × duration counts a ten-second skip as a full
        // listen, which is the number a statistics page must not get wrong.
        //
        // Only forward movement counts, and only movement small enough to be
        // playback rather than a seek: dragging the scrubber to the end would
        // otherwise bank the whole track as listened. The cap is generous
        // against the client's own save interval — a few seconds — so a slow
        // request or a backgrounded tab still counts, while a jump does not.
        $advanced = $data['position'] - (int) ($play->position_seconds ?? 0);
        $listened = ($advanced > 0 && $advanced <= self::LISTENED_MAX_STEP)
            ? $advanced
            : 0;

        $wasCompleted = (bool) $play->completed;

        $play->forceFill([
            'position_seconds' => $data['position'],
            // Null until something is actually listened, so a row that only
            // ever recorded a seek stays distinguishable from one that played
            // for no time — and from the 505 rows that predate the column.
            'listened_seconds' => ($play->listened_seconds ?? 0) + $listened,
            'completed' => $completed,
        ])->save();

        // The "watched to the end" signal, fired once at the crossing (S-276).
        if ($completed && ! $wasCompleted) {
            PlaybackCompleted::dispatch($item, app(CurrentProfile::class)->id());
        }

        return response()->json(['completed' => $completed]);
    }

    /**
     * Issues an API token for the signed-in profile.
     *
     * The sync API authenticates with a bearer token, and the web form only
     * creates a session — so without this nothing ever obtained one and the
     * device mirror stayed empty, leaving every offline feature unreachable.
     *
     * The session is the proof: this is the same person, on the same device,
     * already authenticated. The token names the current profile so it carries
     * exactly the same rating cap, and a profile switch issues a new one.
     */
    public function deviceToken(Request $request): JsonResponse
    {
        $profile = app(CurrentProfile::class)->get();

        abort_unless($profile !== null, 403);

        $user = Auth::user();

        // Replaced rather than accumulated: a browser that syncs on every
        // launch would otherwise leave a token per visit, and a list of
        // hundreds is impossible to audit or revoke meaningfully.
        $user->tokens()->where('name', 'device:'.$profile->id)->delete();

        $token = $user->createToken('device:'.$profile->id, ['profile:'.$profile->id]);

        return response()->json([
            'token' => $token->plainTextToken,
            'profile_id' => $profile->id,
        ]);
    }

    /**
     * Cross-type search results.
     */
    public function search(Request $request): View
    {
        $term = trim((string) $request->query('q', ''));

        return view('media.search', [
            'counts' => $this->browser->counts(),
            'term' => $term,
            // Everything, not just titles: dialogue from subtitles and text
            // from inside books are the whole point of a library that holds
            // the film, the book and the soundtrack at once.
            'search' => $term === ''
                ? ['query' => '', 'total' => 0, 'groups' => []]
                : app(SearchService::class)->search($term),
        ]);
    }

    /**
     * The offline downloads screen.
     *
     * Only the shell: what is stored lives in the browser's IndexedDB, and the
     * server has no way to know what any given device holds.
     */
    public function downloads(): View
    {
        return view('media.downloads', [
            'counts' => $this->browser->counts(),
        ]);
    }

    /**
     * Logs a play event.
     *
     * Browsers issue range requests while seeking, which would otherwise log a
     * play per chunk. Collapsing repeats within a short window keeps one
     * listening session as one play.
     */
    private function recordPlay(MediaItem $item): void
    {
        $userId = Auth::id();

        // Whose play this is. Stamped here as well as in `saveProgress()`,
        // because without it 84% of the rows in this table named an account
        // and not a person — and a household sharing one login is exactly the
        // case profiles exist for. Every per-profile statistic was reading
        // one sixth of the data.
        $profileId = app(CurrentProfile::class)->id();

        $recent = $item->plays()
            ->where('user_id', $userId)
            ->when($profileId, fn ($query) => $query->where('profile_id', $profileId))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recent) {
            return;
        }

        $item->plays()->create([
            'user_id' => $userId,
            'profile_id' => $profileId,
            'source' => $this->playSource(),
        ]);

        // A play was recorded. A scrobbler plugin (Last.fm, Trakt) subscribes to
        // this to report the listen (S-264 Phase 3).
        PlaybackRecorded::dispatch($item, $profileId);
    }

    /**
     * Where this play was started from (S-120), from the request's `from`
     * param, validated against the known set so a stray value records null
     * rather than polluting the column.
     */
    private function playSource(): ?string
    {
        $from = request()->query('from');

        return is_string($from) && in_array($from, MediaPlay::SOURCES, true) ? $from : null;
    }

    /**
     * Other items sharing a genre — the "more like this" rail.
     */
    private function relatedTo(MediaItem $item)
    {
        $genres = $item->tags->where('type', 'genre')->pluck('value');

        if ($genres->isEmpty()) {
            return collect();
        }

        return MediaItem::query()
            ->where('type', $item->type)
            ->whereKeyNot($item->getKey())
            ->whereHas('tags', fn ($q) => $q->where('type', 'genre')->whereIn('value', $genres))
            ->with(['musicMetadata', 'tags'])
            ->limit(20)
            ->get();
    }
}
