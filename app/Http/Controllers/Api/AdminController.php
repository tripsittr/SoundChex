<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use App\Models\MediaPlay;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin surface for the native app.
 *
 * A subset of the Filament panel — a read-only dashboard, item edit, and user /
 * profile management — for a profile that may administer. Gated by the same test
 * the web panel's door uses (owner or library-admin), applied per request in the
 * middleware alias below rather than trusting the client's `is_admin` flag.
 */
class AdminController extends Controller
{
    /**
     * Dashboard figures: what is in the library, and recent activity.
     *
     * Counts are exact and cheap (indexed group-by). Storage is deliberately
     * omitted here rather than sampled — S-119 in the server repo tracks adding a
     * real size column; a sampled figure on a dashboard reads as authoritative
     * when it is not.
     */
    public function stats(): JsonResponse
    {
        $counts = MediaItem::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $recentPlays = MediaPlay::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return response()->json([
            'library' => [
                'music' => (int) ($counts['music'] ?? 0),
                'movie' => (int) ($counts['movie'] ?? 0),
                'show' => (int) ($counts['show'] ?? 0),
                'book' => (int) ($counts['book'] ?? 0),
                'total' => (int) $counts->sum(),
            ],
            'accounts' => User::count(),
            'profiles' => Profile::count(),
            'plays_last_7_days' => $recentPlays,
            'top_items' => $this->topItems(),
        ]);
    }

    /**
     * One item's editable detail — the core fields plus the type's metadata.
     */
    public function item(MediaItem $item): JsonResponse
    {
        $item->loadMissing(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata']);

        return response()->json([
            'id' => $item->id,
            'type' => $item->type->value,
            'title' => $item->title,
            'artwork' => $item->coverUrl(),
            'user_rating' => $item->user_rating,
            'notes' => $item->notes,
            'meta' => $this->editableMeta($item),
        ]);
    }

    /**
     * Updates an item's core fields and its type metadata.
     *
     * Only the fields a phone edits are accepted — title/rating/notes and the
     * handful of naming fields per type — so this cannot be turned into a way to
     * rewrite `file_path` or the processing state. Each is optional; only what is
     * sent changes.
     */
    public function updateItem(Request $request, MediaItem $item): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'user_rating' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'meta' => ['sometimes', 'array'],
        ]);

        $item->fill(array_intersect_key($data, array_flip(['title', 'user_rating', 'notes'])));
        $item->save();

        if (isset($data['meta'])) {
            $this->updateMeta($item, $data['meta']);
        }

        return $this->item($item->refresh());
    }

    /**
     * Triggers a library scan — the phone-appropriate "add media".
     *
     * Uploading gigabyte media files from a phone is the wrong shape; adding
     * media on a self-hosted server means dropping files into the watched
     * folders and scanning. This queues that scan (via the same console command
     * the web uses) so the request returns at once rather than blocking on a
     * catalogue walk, and new files appear in the library as it runs.
     */
    public function scan(): JsonResponse
    {
        Artisan::queue('library:scan');

        return response()->json(['scanning' => true]);
    }

    // MARK: - Profiles (household members)

    /** The account's profiles, with their manageable fields. */
    public function profiles(Request $request): JsonResponse
    {
        $profiles = Profile::where('user_id', $request->user()->id)
            ->orderByDesc('is_owner')->orderBy('name')->get()
            ->map(fn (Profile $p): array => $this->profileArray($p));

        return response()->json([
            'profiles' => $profiles,
            'ratings' => Profile::RATING_ORDER,
        ]);
    }

    /** Creates a profile on the account. */
    public function storeProfile(Request $request): JsonResponse
    {
        $data = $this->validateProfile($request, creating: true);

        $profile = new Profile(['user_id' => $request->user()->id]);
        $this->applyProfile($profile, $data);

        return response()->json($this->profileArray($profile), 201);
    }

    /** Updates a profile's name, colour, kids flag, rating cap, or PIN. */
    public function updateProfile(Request $request, Profile $profile): JsonResponse
    {
        $this->ownProfile($request, $profile);
        $data = $this->validateProfile($request, creating: false);
        $this->applyProfile($profile, $data);

        return response()->json($this->profileArray($profile->refresh()));
    }

    /** Deletes a profile. The owner profile cannot be removed. */
    public function destroyProfile(Request $request, Profile $profile): JsonResponse
    {
        $this->ownProfile($request, $profile);

        abort_if($profile->is_owner, 422, 'The owner profile cannot be removed.');

        $profile->delete();

        return response()->json(['deleted' => true]);
    }

    private function validateProfile(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'color' => ['sometimes', 'nullable', 'string', 'max:9'],
            'is_kids' => ['sometimes', 'boolean'],
            'max_rating' => ['sometimes', 'nullable', 'string', Rule::in(Profile::RATING_ORDER)],
            // An empty string clears the PIN; a value sets it. Absent leaves it.
            'pin' => ['sometimes', 'nullable', 'string', 'max:6'],
        ]);
    }

    private function applyProfile(Profile $profile, array $data): void
    {
        $profile->fill(array_intersect_key($data, array_flip(['name', 'color', 'is_kids', 'max_rating'])));
        $profile->save();

        // PIN goes through the model so it is hashed and the lock state reset.
        if (array_key_exists('pin', $data)) {
            $profile->setPin($data['pin']);
        }
    }

    private function profileArray(Profile $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'color' => $p->color,
            'initial' => $p->initial(),
            'avatar_url' => $p->avatarUrl(),
            'is_owner' => (bool) $p->is_owner,
            'is_kids' => (bool) $p->is_kids,
            'requires_pin' => $p->requiresPin(),
            'max_rating' => $p->max_rating,
        ];
    }

    /** 404 for a profile on another account. */
    private function ownProfile(Request $request, Profile $profile): void
    {
        abort_unless($profile->user_id === $request->user()->id, 404);
    }

    /** The editable metadata for the item's type. @return array<string, mixed> */
    private function editableMeta(MediaItem $item): array
    {
        return match ($item->type->value) {
            'music' => [
                'artist' => $item->musicMetadata?->artist,
                'album' => $item->musicMetadata?->album,
                'release_year' => $item->musicMetadata?->release_year,
            ],
            'movie' => [
                'director' => $item->movieMetadata?->director,
                'release_year' => $item->movieMetadata?->release_year,
            ],
            'show' => [
                'episode_title' => $item->showMetadata?->episode_title,
                'season_number' => $item->showMetadata?->season_number,
                'episode_number' => $item->showMetadata?->episode_number,
            ],
            'book' => [
                'author' => $item->bookMetadata?->author,
                'publisher' => $item->bookMetadata?->publisher,
            ],
            default => [],
        };
    }

    /** Applies edited metadata to the item's type row, creating it if absent. */
    private function updateMeta(MediaItem $item, array $meta): void
    {
        // The keys a phone may edit, per type — anything else is ignored, so the
        // metadata row's scan-derived fields (ISRC, MusicBrainz ids, …) are safe.
        $allowed = match ($item->type->value) {
            'music' => ['artist', 'album', 'release_year'],
            'movie' => ['director', 'release_year'],
            'show' => ['episode_title', 'season_number', 'episode_number'],
            'book' => ['author', 'publisher'],
            default => [],
        };

        $fields = array_intersect_key($meta, array_flip($allowed));

        if ($fields === []) {
            return;
        }

        $relation = match ($item->type->value) {
            'music' => $item->musicMetadata(),
            'movie' => $item->movieMetadata(),
            'show' => $item->showMetadata(),
            'book' => $item->bookMetadata(),
            default => null,
        };

        $relation?->updateOrCreate(['media_item_id' => $item->id], $fields);
    }

    /**
     * The most-played items over the last 30 days — the "what's popular" rail a
     * dashboard leads with.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topItems(): array
    {
        return MediaPlay::query()
            ->select('media_item_id', DB::raw('count(*) as plays'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('media_item_id')
            ->orderByDesc('plays')
            ->limit(10)
            ->with('mediaItem')
            ->get()
            ->filter(fn ($row) => $row->mediaItem !== null)
            ->map(fn ($row): array => [
                'id' => $row->mediaItem->id,
                'title' => $row->mediaItem->title,
                'subtitle' => $row->mediaItem->subtitle(),
                'artwork' => $row->mediaItem->coverUrl(),
                'plays' => (int) $row->plays,
            ])
            ->values()
            ->all();
    }
}
