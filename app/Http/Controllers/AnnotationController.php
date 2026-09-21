<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Enums\MediaItemType;
use App\Models\Annotation;
use App\Models\MediaItem;
use App\Services\CurrentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Highlights and margin notes.
 *
 * Every action is scoped to the signed-in reader. Annotations are private, so
 * ownership is enforced on read as well as write — a note is only ever
 * addressable by the person who wrote it.
 */
class AnnotationController extends Controller
{
    /**
     * Every annotation this reader has on this book.
     *
     * Loaded once when the reader opens, then kept in memory — paging through
     * a book shouldn't cost a request per page.
     */
    public function index(MediaItem $item): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $annotations = Annotation::query()
            ->forViewer($item->id)
            ->orderBy('page')
            ->orderBy('id')
            ->get(['id', 'kind', 'location', 'page', 'excerpt', 'note', 'color', 'updated_at']);

        return response()->json(['annotations' => $annotations]);
    }

    public function store(Request $request, MediaItem $item): JsonResponse
    {
        abort_unless($item->type === MediaItemType::Book, 404);

        $data = $request->validate([
            // Opaque per-format position blob — validated for shape, not
            // contents, because the reader is what interprets it.
            'location' => ['required', 'array'],
            'page' => ['nullable', 'integer', 'min:1'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:10000'],
            'color' => ['nullable', 'string', 'in:'.implode(',', Annotation::COLORS)],
        ]);

        $annotation = new Annotation([
            'media_item_id' => $item->id,
            'user_id' => (int) Auth::id(),
            'profile_id' => app(CurrentProfile::class)->id(),
            'location' => $data['location'],
            'page' => $data['page'] ?? null,
            'excerpt' => $data['excerpt'] ?? null,
            'note' => $data['note'] ?? null,
            'color' => $data['color'] ?? 'yellow',
        ]);

        $annotation->syncKind();
        $annotation->save();

        return response()->json(['annotation' => $annotation], 201);
    }

    /**
     * Edits the note text or colour. Position is never updated — a highlight
     * that moved is a different highlight.
     */
    public function update(Request $request, MediaItem $item, Annotation $annotation): JsonResponse
    {
        $this->authorizeAnnotation($item, $annotation);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:10000'],
            'color' => ['nullable', 'string', 'in:'.implode(',', Annotation::COLORS)],
        ]);

        if (array_key_exists('note', $data)) {
            $annotation->note = $data['note'];
        }

        if (filled($data['color'] ?? null)) {
            $annotation->color = $data['color'];
        }

        $annotation->syncKind();
        $annotation->save();

        return response()->json(['annotation' => $annotation]);
    }

    public function destroy(MediaItem $item, Annotation $annotation): JsonResponse
    {
        $this->authorizeAnnotation($item, $annotation);

        $annotation->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * A 404 rather than a 403: someone else's private note shouldn't be
     * confirmed to exist.
     */
    private function authorizeAnnotation(MediaItem $item, Annotation $annotation): void
    {
        $profileId = app(CurrentProfile::class)->id();

        abort_unless(
            $annotation->media_item_id === $item->id
                && $annotation->user_id === (int) Auth::id()
                // A note made under another profile on the same account is
                // still not this reader's to edit.
                && ($annotation->profile_id === null || $annotation->profile_id === $profileId),
            404,
        );
    }
}
