<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use App\Http\Controllers\HlsController;
use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AlbumController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaCenterController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\ProfileSettingsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\SubtitleController;
use App\Http\Controllers\WatchController;
use App\Http\Controllers\WatchlistController;
use App\Http\Middleware\EnsureRegistrationIsOpen;
use App\Http\Middleware\RequireProfileUnlock;
use Illuminate\Support\Facades\Route;

// The front door. Laravel's welcome page advertised the framework and told a
// visitor nothing — on a publicly reachable URL, root should be the library
// for anyone signed in and the login screen for everyone else.
Route::get('/', fn () => redirect()->route(
    auth()->check() ? 'media.home' : 'login',
));

/*
| Proof that this address is a SoundChex server.
|
| The connect screen used a no-cors fetch of /login, which resolves for any
| response at all — a captive portal, a router's admin page, an unrelated
| server's 500. It meant "something answered", not "this is your library", and
| with the addresses raced the first thing to answer won regardless of what it
| was.
|
| Deliberately CORS-open and unauthenticated: it says only that SoundChex is
| here, which is what any client on the network can already tell by connecting.
*/
/*
| Every address this server can be reached on.
|
| The app only ever knew the address it happened to arrive on, so a device that
| connected through the public relay had nothing to compare it against and
| stayed there — a second and a third on every page, for the rest of the
| session, with no way out short of retyping the address.
|
| Signed-in only, unlike the identity endpoint below. The old comment argued
| that anyone who can reach the server already knows where it is — but this
| lists *every* address: reaching the tailnet name does not reveal the LAN IP,
| and reaching the LAN does not reveal the tailnet hostname. To an outsider on
| any one route, the rest of the list is a map of ways into the household.
|
| The only caller is `failover.js`, a same-origin fetch from pages that are
| themselves behind auth, so the session cookie is already on the request —
| and its `!response.ok` branch falls back to the stored list, so a signed-out
| fetch degrades rather than breaks. No CORS header: nothing cross-origin has
| any business asking.
*/
Route::get('/soundchex-addresses.json', fn () => response()
    ->json(['addresses' => app(App\Services\NetworkAddresses::class)->all()]))
    ->middleware('auth')
    ->name('addresses');

Route::get('/soundchex.json', function () {
    // The build manifest's digest, which changes exactly when the frontend
    // does. Clients compare it against what they loaded and reload themselves
    // when it moves, so a deploy reaches a phone without anyone reinstalling
    // anything — which matters most when the phone is not in the same building.
    $manifest = public_path('build/manifest.json');

    return response()
        ->json([
            'app' => 'soundchex',
            'version' => 1,
            'build' => is_file($manifest)
                ? substr(hash_file('sha1', $manifest), 0, 12)
                : null,
        ])
        ->header('Access-Control-Allow-Origin', '*');
})->name('identity');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    // Throttled per IP and per account — a tunnelled server is reachable from
    // anywhere, so login is the one route with unlimited free guesses.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Sign-up is gated by a setting rather than always available: this server
    // can be made publicly reachable, and open registration would then let
    // anyone who found the URL into the library.
    Route::middleware(EnsureRegistrationIsOpen::class)->group(function (): void {
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:register');
    });
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth'])->group(function (): void {

    Route::get('/dashboard', fn () => redirect()->route('media.home'))->name('dashboard');

    // A plugin's compiled stylesheet (S-350). The file sits in the plugin's own
    // directory under application support, which is not web-served, so it is
    // handed out here — signed in, and only for an enabled plugin.
    Route::get('/plugin-styles/{plugin}.css', \App\Http\Controllers\PluginStyleController::class)
        ->where('plugin', '[A-Za-z0-9._-]+')
        ->name('plugin.styles');

    /*
    |----------------------------------------------------------------------
    | Viewing profiles
    |----------------------------------------------------------------------
    |
    | A household shares one account but not one taste. Switching is a
    | convenience rather than a security boundary — permissions stay on the
    | account.
    |
    */
    Route::get('/profiles', [ProfileController::class, 'index'])->name('profiles.index');
    Route::post('/profiles/switch', [ProfileController::class, 'switch'])->name('profiles.switch');
    Route::post('/profiles', [ProfileController::class, 'store'])->name('profiles.store');
    Route::delete('/profiles/{profile}', [ProfileController::class, 'destroy'])->name('profiles.destroy');

    /*
    |----------------------------------------------------------------------
    | Media Center
    |----------------------------------------------------------------------
    |
    | The catalog everyone uses. Administration stays in the Filament panel
    | at /admin; this side is browse-only by design.
    |
    */
    Route::prefix('app')
        ->name('media.')
        // Sign-in is long-lived, so the session survives the app closing and
        // reopening — which means it is no longer evidence about who is holding
        // the phone. A profile with a PIN asks for it again here.
        ->middleware(RequireProfileUnlock::class)
        ->group(function (): void {
            Route::get('/', [MediaCenterController::class, 'home'])->name('home');
            Route::get('/search', [MediaCenterController::class, 'search'])->name('search');

            // Albums are derived from track tags rather than stored, so they
            // are addressed by artist and title in the query string. Album
            // names contain slashes and colons often enough that a path
            // segment would mean escaping every one of them.
            Route::get('/albums', [AlbumController::class, 'index'])->name('albums');
            Route::get('/album', [AlbumController::class, 'show'])->name('album');
            Route::get('/genres', [AlbumController::class, 'genres'])->name('genres');
            Route::get('/artists', [AlbumController::class, 'artists'])->name('artists');
            Route::get('/artist', [AlbumController::class, 'artist'])->name('artist');

            // A shuffled queue from the whole music library, built server-side
            // so the page never has to hold every row to pick from.
            Route::get('/shuffle', [AlbumController::class, 'shuffleAll'])->name('shuffle');

            // Every downloadable track, not just the page on screen: the songs
            // list is paginated, so a button built from it would quietly take
            // the first page and call it the library.
            Route::get('/downloadable', [AlbumController::class, 'downloadableAll'])->name('downloadable');

            // A token for the device to sync with.
            //
            // The API needs a bearer token, but signing in through the web
            // form only creates a session — so nothing ever issued one and the
            // mirror stayed permanently empty, which made every offline
            // feature dead weight. This mints one for the profile already
            // signed in, using the session as the proof.
            Route::post('/device-token', [MediaCenterController::class, 'deviceToken'])
                ->name('device-token');

            // Playlists. Listed before /{type} so "playlists" is not captured
            // as a media type.
            Route::get('/playlists', [PlaylistController::class, 'index'])->name('playlists');
            Route::post('/playlists', [PlaylistController::class, 'store'])->name('playlists.store');
            Route::get('/playlists/{collection}', [PlaylistController::class, 'show'])->name('playlist');
            Route::patch('/playlists/{collection}', [PlaylistController::class, 'update'])->name('playlists.update');
            Route::delete('/playlists/{collection}', [PlaylistController::class, 'destroy'])->name('playlists.destroy');
            Route::post('/playlists/{collection}/items', [PlaylistController::class, 'addItem'])->name('playlists.items.add');
            Route::delete('/playlists/{collection}/items/{item}', [PlaylistController::class, 'removeItem'])->name('playlists.items.remove');
            Route::post('/playlists/{collection}/reorder', [PlaylistController::class, 'reorder'])->name('playlists.reorder');

            // What this device is holding offline. The list itself lives in
            // the browser, so this only renders the shell.
            Route::get('/downloads', [MediaCenterController::class, 'downloads'])->name('downloads');

            // Settings for whoever is watching, as opposed to the admin panel's
            // server-wide configuration. Declared before the {type} wildcard, or
            // "settings" would be read as a media type.
            Route::get('/settings', [ProfileSettingsController::class, 'edit'])->name('settings');
            Route::patch('/settings', [ProfileSettingsController::class, 'update'])->name('settings.update');
            Route::patch('/settings/pin', [ProfileSettingsController::class, 'updatePin'])->name('settings.pin');

            // Movies and shows browse as one section, split by a sub-nav.
            // Declared before the {type} wildcard so "watch" isn't captured
            // as a media type.
            Route::get('/watch', [MediaCenterController::class, 'watch'])->name('watch.index');

            // Static segments must be declared before the wildcard {type},
            // or "search" would be captured as a media type.
            Route::get('/item/{item}', [MediaCenterController::class, 'show'])->name('show');

            // Full-screen video. Streaming and progress reuse the routes
            // above, so only the chrome is specific to watching.
            Route::get('/watch/{item}', [WatchController::class, 'show'])->name('watch');

            // Caption tracks. Served through the app because the files live on
            // the private disk with no public URL.
            Route::get('/item/{item}/subtitles', [SubtitleController::class, 'index'])
                ->name('subtitles.index');
            Route::post('/item/{item}/subtitles/scan', [SubtitleController::class, 'scan'])
                ->name('subtitles.scan');
            Route::get('/item/{item}/subtitles/search', [SubtitleController::class, 'search'])
                ->name('subtitles.search');
            Route::post('/item/{item}/subtitles/download', [SubtitleController::class, 'download'])
                ->name('subtitles.download');
            Route::delete('/item/{item}/subtitles/{subtitle}', [SubtitleController::class, 'destroy'])
                ->name('subtitles.destroy');

            // Listed last so "scan"/"search" aren't captured as a track id.
            Route::get('/item/{item}/subtitles/{subtitle}', [WatchController::class, 'subtitle'])
                ->whereNumber('subtitle')
                ->name('subtitle');

            // Reader: the page, the file it renders, and the resume position.
            Route::get('/read/{item}', [ReaderController::class, 'show'])->name('read');
            Route::get('/read/{item}/file', [ReaderController::class, 'file'])
                ->middleware('throttle:stream')
                ->name('read.file');
            Route::post('/read/{item}/progress', [ReaderController::class, 'saveProgress'])
                ->name('read.progress');

            // Recognised text for a scanned page, so it can be selected and
            // highlighted like any other.
            Route::get('/read/{item}/page/{page}/text', [ReaderController::class, 'pageText'])
                ->whereNumber('page')
                ->name('read.page-text');

            // A continuous run of reading text with its illustrations.
            Route::get('/read/{item}/text', [ReaderController::class, 'readingText'])
                ->name('read.text');

            // Illustrations, chapters, and search inside a book.
            Route::get('/read/{item}/contents', [ReaderController::class, 'contents'])
                ->name('read.contents');
            Route::get('/read/{item}/search', [ReaderController::class, 'search'])
                ->name('read.search');
            Route::get('/read/{item}/asset/{asset}', [ReaderController::class, 'asset'])
                ->whereNumber('asset')
                ->name('read.asset');

            // Highlights and margin notes, private to the signed-in reader.
            Route::get('/read/{item}/annotations', [AnnotationController::class, 'index'])
                ->name('read.annotations');
            Route::post('/read/{item}/annotations', [AnnotationController::class, 'store'])
                ->name('read.annotations.store');
            Route::patch('/read/{item}/annotations/{annotation}', [AnnotationController::class, 'update'])
                ->name('read.annotations.update');
            Route::delete('/read/{item}/annotations/{annotation}', [AnnotationController::class, 'destroy'])
                ->name('read.annotations.destroy');
            // Lyrics for the player panel. A session route rather than the
            // API one, which needs a token the web player does not have.
            Route::get('/item/{item}/lyrics', [MediaCenterController::class, 'lyrics'])
                ->name('lyrics');

            // Adaptive streaming (S-29). `decide` answers how to play an
            // item for this caller; the other two serve the stream when the
            // answer is "transcode".
            Route::get('/item/{item}/playback', [HlsController::class, 'decide'])
                ->name('hls.decide');
            Route::get('/item/{item}/hls.m3u8', [HlsController::class, 'playlist'])
                ->name('hls.playlist');
            Route::get('/hls/{session}/{file}', [HlsController::class, 'segment'])
                ->where(['session' => '[a-f0-9]{32}', 'file' => '[A-Za-z0-9._-]+'])
                ->name('hls.segment');

            Route::get('/item/{item}/stream', [MediaCenterController::class, 'stream'])
                ->middleware('throttle:stream')
                ->name('stream');

            // A profile's list of things to get to.
            Route::post('/item/{item}/watchlist', [WatchlistController::class, 'toggle'])
                ->name('watchlist.toggle');

            // Playback position, so a track or film resumes where it stopped.
            Route::post('/item/{item}/progress', [MediaCenterController::class, 'saveProgress'])
                ->name('progress');
            Route::get('/{type}', [MediaCenterController::class, 'browse'])->name('browse');
        });
});
