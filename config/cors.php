<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

/*
 * Cross-origin access for the native app.
 *
 * The Tauri shell serves its own page from a tauri:// (iOS/macOS) or
 * http://tauri.localhost (Windows/Android) origin, so every API call it makes
 * is cross-origin. Without these headers the browser discards the response
 * before the app sees it — the same failure that made the connect screen
 * report every valid server as unreachable.
 *
 * Scoped to the API only. The web app is same-origin and needs none of this,
 * and widening it would hand any site the ability to make credentialed
 * requests against a user's library.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
     * The shells' own origins, not a wildcard.
     *
     * A wildcard cannot be combined with credentials, and more importantly it
     * would let any web page call this API with a user's token if one ever
     * leaked into a browser context.
     */
    'allowed_origins' => [
        'tauri://localhost',
        'http://tauri.localhost',
        'https://tauri.localhost',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'If-None-Match'],

    /*
     * ETag has to be readable by the client or conditional requests are
     * pointless: the browser can send If-None-Match but never learns what to
     * send without this.
     */
    'exposed_headers' => ['ETag'],

    'max_age' => 3600,

    /*
     * False deliberately. Auth is a bearer token in a header, not a cookie —
     * so credentialed requests are not needed, and allowing them would widen
     * the surface for no benefit.
     */
    'supports_credentials' => false,

];
