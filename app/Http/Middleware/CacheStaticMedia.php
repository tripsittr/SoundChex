<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells browsers they may keep artwork.
 *
 * Extracted cover art is served with no caching headers at all, so every one is
 * fetched again on every page load. Measured on this server: 6,958 artwork
 * requests in an afternoon, peaking at 1,146 in a single minute — a music page
 * showing forty covers asks for forty files, every time it is opened.
 *
 * On a LAN that is merely wasteful. Over a relayed connection at more than a
 * second a request it is the difference between a page that loads and one that
 * does not, which is what "music fails to load while books and films are fine"
 * turned out to be: books and films have a handful of covers between them.
 *
 * Safe to cache hard because the filename carries the media item's id, so a
 * different image is a different URL.
 */
class CacheStaticMedia
{
    /** A year, which is what "immutable" means in practice. */
    private const MAX_AGE = 31_536_000;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isStaticMedia($request)) {
            return $response;
        }

        // immutable stops a browser revalidating on reload, which is otherwise
        // a request per image to be told nothing changed.
        $response->headers->set('Cache-Control', 'public, max-age=' . self::MAX_AGE . ', immutable');

        return $response;
    }

    private function isStaticMedia(Request $request): bool
    {
        return str_starts_with($request->path(), 'storage/artwork/')
            || str_starts_with($request->path(), 'storage/avatars/')
            || str_starts_with($request->path(), 'storage/profile-photos/');
    }
}
