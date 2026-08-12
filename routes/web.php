<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MediaCenterController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\SubtitleController;
use App\Http\Controllers\WatchController;
use App\Http\Controllers\WatchlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');

    // Throttled per IP and per account — a tunnelled server is reachable from
    // anywhere, so login is the one route with unlimited free guesses.
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth'])->group(function (): void {

    Route::get('/dashboard', fn () => redirect()->route('media.home'))->name('dashboard');

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
        ->group(function (): void {
            Route::get('/', [MediaCenterController::class, 'home'])->name('home');
            Route::get('/search', [MediaCenterController::class, 'search'])->name('search');

            // What this device is holding offline. The list itself lives in
            // the browser, so this only renders the shell.
            Route::get('/downloads', [MediaCenterController::class, 'downloads'])->name('downloads');

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
