<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DeviceReportController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlaylistController;
use App\Http\Controllers\Api\PlaylistImportController;
use App\Http\Controllers\Api\PlaylistSourceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReaderController;
use App\Http\Controllers\Api\ServerHealthController;
use App\Http\Controllers\Api\SubtitleController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\Transfer\RequestController as TransferRequestController;
use App\Http\Controllers\Api\Transfer\SourceController as TransferSourceController;
use App\Http\Controllers\Api\UpdateController;
use App\Http\Middleware\EnsureApiAdmin;
use App\Http\Middleware\LoopbackOnly;
use Illuminate\Support\Facades\Route;

/*
 * The native app's API.
 *
 * Token-authenticated rather than session-based: the app runs from a tauri://
 * origin, so every request is cross-origin and a session cookie would be
 * subject to CORS and SameSite rules that are not reliable across platforms.
 *
 * Versioned from the start. The device stores what these return, so a shape
 * change has to be able to coexist with an older app that has not updated.
 */
Route::prefix('v1')->group(function (): void {

    /*
    | What the host application shows: is this working?
    |
    | Unauthenticated but loopback-only. It reports on the machine rather than
    | the library, and the server app has to reach it before anyone has signed
    | in — but there is no reason for it to answer the network.
    */
    /*
    | Where the desktop apps look for a new version.
    |
    | Unauthenticated: the updater runs before anyone has signed in, and the
    | signature is what makes a served bundle trustworthy rather than the
    | request being authorised.
    */
    Route::get('/updates/{target}/{arch}/{currentVersion}', [UpdateController::class, 'show'])
        ->name('api.updates');
    Route::get('/updates/{target}/{arch}/download/{file}', [UpdateController::class, 'download'])
        ->name('api.updates.download');

    /*
    | Diagnostics from a device.
    |
    | Unauthenticated, because the failures worth reporting include the ones
    | that stop a device signing in — a report needing a working session cannot
    | describe a broken one. Rate-limited instead, since that makes it writable
    | by anything that can reach the server.
    */
    Route::post('/device-reports', [DeviceReportController::class, 'store'])
        ->middleware('throttle:12,1')
        ->name('api.device-reports');

    Route::get('/server/health', [ServerHealthController::class, 'show'])
        ->middleware(LoopbackOnly::class)
        ->name('api.server.health');

    // The account's profiles, to pick one before a token is minted. Verifies
    // credentials, so it is throttled like the token endpoint — confirming a
    // password by returning profiles is as much a login as returning a token.
    Route::post('/profiles', [ProfileController::class, 'index'])
        ->middleware('throttle:10,1')
        ->name('api.profiles');

    // Issued with credentials and a profile; throttled in the controller as
    // well, because a token is longer-lived than a session and this endpoint
    // would otherwise be the softer way in.
    Route::post('/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.tokens.store');

    /*
    | Asking to copy this server.
    |
    | The only unauthenticated writes in the application, and they have to be:
    | a request is how authentication is obtained. Rate-limited hard because of
    | it, and they disclose nothing about the library — an unapproved requester
    | learns only that a SoundChex server answered.
    */
    Route::post('/transfer/requests', [TransferRequestController::class, 'store'])
        ->middleware('throttle:5,10')
        ->name('api.transfer.request');

    Route::get('/transfer/requests/{transferRequest}', [TransferRequestController::class, 'show'])
        ->middleware('throttle:120,1')
        ->name('api.transfer.request.show');

    Route::middleware('auth:sanctum')->group(function (): void {
        /*
        | Serving an approved receiver. Read-only: a transfer never writes to
        | the source, so a mistake at the far end cannot damage the machine
        | being copied.
        */
        Route::prefix('transfer')->group(function (): void {
            Route::get('/manifest', [TransferSourceController::class, 'manifest'])
                ->name('api.transfer.manifest');

            Route::get('/database', [TransferSourceController::class, 'database'])
                ->name('api.transfer.database');

            Route::get('/profiles', [TransferSourceController::class, 'profiles'])
                ->name('api.transfer.profiles');

            // Calling it off from the receiving end. Which request that is
            // comes from the token rather than the URL, so a receiver can
            // only end its own — see RequestController::cancel().
            Route::delete('/requests/mine', [TransferRequestController::class, 'cancel'])
                ->name('api.transfer.cancel');

            // The receiver reporting how far it has got. Neither machine could
            // see the other's progress, so both guessed and both were wrong -
            // see docs/WorkingWithTwoAgents.md. Which transfer this is comes
            // from the token rather than the body.
            Route::post('/progress', [TransferRequestController::class, 'progress'])
                ->name('api.transfer.progress');

            // Streaming, so not throttled with the rest — a transfer is
            // thousands of these by design.
            Route::get('/file/{item}', [TransferSourceController::class, 'file'])
                ->name('api.transfer.file');
        });

        Route::get('/me', [TokenController::class, 'show'])->name('api.me');
        Route::delete('/tokens/current', [TokenController::class, 'destroy'])->name('api.tokens.destroy');

        // The catalogue. Both go through ContentGate — see LibraryController.
        Route::get('/library', [LibraryController::class, 'index'])->name('api.library');

        // What has happened since this device last asked.
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('api.notifications');
        Route::match(['get', 'post'], '/library/delta', [LibraryController::class, 'delta'])
            ->name('api.library.delta');

        // Playback: the bytes (with Range support for seeking), and the resume
        // position. Streaming is throttled apart from the rest — a single film
        // is many ranged requests by design, so it uses the same lenient
        // `stream` limiter the web route does.
        Route::get('/items/{item}/stream', [MediaController::class, 'stream'])
            ->middleware('throttle:stream')
            ->name('api.items.stream');
        Route::get('/items/{item}/progress', [MediaController::class, 'progress'])
            ->name('api.items.progress');
        // Lyrics: fetched and cached from a provider (LRCLIB by default).
        Route::get('/items/{item}/lyrics', [MediaController::class, 'lyrics'])
            ->name('api.items.lyrics');
        Route::post('/items/{item}/progress', [MediaController::class, 'saveProgress'])
            ->name('api.items.progress.save');

        // Caption tracks for a video, and one track's WebVTT content — the
        // token-authed equivalent of the web's session-only subtitle routes, so
        // the native player can list and load subtitles (S-160).
        Route::get('/items/{item}/subtitles', [SubtitleController::class, 'index'])
            ->name('api.items.subtitles');
        Route::get('/items/{item}/subtitles/{subtitle}', [SubtitleController::class, 'show'])
            ->name('api.items.subtitle');

        // Book reading — the token-authed equivalent of the web reader's
        // session-only routes: a book's format + resume point, the file to
        // render, and saving the reading position (S-161).
        Route::get('/items/{item}/book', [ReaderController::class, 'file'])
            ->name('api.items.book');
        Route::get('/items/{item}/reader', [ReaderController::class, 'show'])
            ->name('api.items.reader');
        // The reflowable text of a book, for the Kindle-style reader (S-295).
        Route::get('/items/{item}/reader/content', [ReaderController::class, 'content'])
            ->name('api.items.reader.content');
        // One of a book's inline illustrations (S-295).
        Route::get('/items/{item}/reader/asset/{asset}', [ReaderController::class, 'asset'])
            ->name('api.items.reader.asset');
        Route::post('/items/{item}/reader/progress', [ReaderController::class, 'saveProgress'])
            ->name('api.items.reader.progress');

        // Library search, covering titles, people, dialogue and book text.
        Route::get('/search', [MediaController::class, 'search'])->name('api.search');

        // Switching profile from a signed-in device — no password, because the
        // token already proves the account. The list needs no password either.
        Route::get('/profiles/mine', [ProfileController::class, 'mine'])->name('api.profiles.mine');
        Route::post('/profiles/switch', [ProfileController::class, 'switch'])->name('api.profiles.switch');

        // Admin surface for the app — gated to an administering profile by
        // EnsureApiAdmin, not by anything the client sends.
        Route::middleware(EnsureApiAdmin::class)->prefix('admin')->group(function (): void {
            Route::get('/stats', [AdminController::class, 'stats'])
                ->name('api.admin.stats');
            // View/edit a media item's core fields and type metadata.
            Route::get('/items/{item}', [AdminController::class, 'item'])
                ->name('api.admin.item');
            Route::patch('/items/{item}', [AdminController::class, 'updateItem'])
                ->name('api.admin.item.update');
            // Manage the account's profiles (household members).
            Route::get('/profiles', [AdminController::class, 'profiles'])
                ->name('api.admin.profiles');
            Route::post('/profiles', [AdminController::class, 'storeProfile'])
                ->name('api.admin.profiles.store');
            Route::patch('/profiles/{profile}', [AdminController::class, 'updateProfile'])
                ->name('api.admin.profiles.update');
            Route::delete('/profiles/{profile}', [AdminController::class, 'destroyProfile'])
                ->name('api.admin.profiles.destroy');
            // Add media: queue a library scan of the watched folders.
            Route::post('/scan', [AdminController::class, 'scan'])
                ->name('api.admin.scan');
        });

        // Playlists (Collections), account-scoped and gated per track.
        Route::get('/playlists', [PlaylistController::class, 'index'])->name('api.playlists');
        Route::post('/playlists', [PlaylistController::class, 'store'])->name('api.playlists.store');

        // Porting a playlist in from a file (S-310). Declared before the
        // `{collection}` routes so "imports" is not read as a playlist id.
        Route::get('/playlists/imports', [PlaylistImportController::class, 'index'])->name('api.playlists.imports');
        Route::post('/playlists/imports', [PlaylistImportController::class, 'store'])->name('api.playlists.imports.store');
        Route::get('/playlists/imports/{import}', [PlaylistImportController::class, 'show'])->name('api.playlists.imports.show');
        Route::post('/playlists/imports/{import}/resolve', [PlaylistImportController::class, 'resolve'])->name('api.playlists.imports.resolve');

        // Porting from a connected streaming service (S-312). Also before the
        // `{collection}` routes so "sources" is not read as a playlist id.
        Route::get('/playlists/sources', [PlaylistSourceController::class, 'index'])->name('api.playlists.sources');
        Route::post('/playlists/sources/{source}/authorize', [PlaylistSourceController::class, 'authorize'])->name('api.playlists.sources.authorize');
        Route::post('/playlists/sources/{source}/callback', [PlaylistSourceController::class, 'callback'])->name('api.playlists.sources.callback');
        Route::delete('/playlists/sources/{source}', [PlaylistSourceController::class, 'disconnect'])->name('api.playlists.sources.disconnect');
        Route::get('/playlists/sources/{source}/playlists', [PlaylistSourceController::class, 'playlists'])->name('api.playlists.sources.playlists');
        Route::post('/playlists/sources/{source}/import', [PlaylistSourceController::class, 'import'])->name('api.playlists.sources.import');

        Route::get('/playlists/{collection}', [PlaylistController::class, 'show'])->name('api.playlists.show');
        Route::patch('/playlists/{collection}', [PlaylistController::class, 'update'])->name('api.playlists.update');
        Route::delete('/playlists/{collection}', [PlaylistController::class, 'destroy'])->name('api.playlists.destroy');
        Route::put('/playlists/{collection}/order', [PlaylistController::class, 'reorder'])->name('api.playlists.reorder');
        Route::post('/playlists/{collection}/cover', [PlaylistController::class, 'uploadCover'])->name('api.playlists.cover');
        Route::post('/playlists/{collection}/items', [PlaylistController::class, 'addItem'])->name('api.playlists.items.add');
        Route::delete('/playlists/{collection}/items/{item}', [PlaylistController::class, 'removeItem'])->name('api.playlists.items.remove');
    });
});
