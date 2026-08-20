<?php

use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ServerHealthController;
use App\Http\Middleware\LoopbackOnly;
use App\Http\Controllers\Api\TokenController;
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
    Route::get('/server/health', [ServerHealthController::class, 'show'])
        ->middleware(LoopbackOnly::class)
        ->name('api.server.health');


    // Issued with credentials and a profile; throttled in the controller as
    // well, because a token is longer-lived than a session and this endpoint
    // would otherwise be the softer way in.
    Route::post('/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.tokens.store');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', [TokenController::class, 'show'])->name('api.me');
        Route::delete('/tokens/current', [TokenController::class, 'destroy'])->name('api.tokens.destroy');

        // The catalogue. Both go through ContentGate — see LibraryController.
        Route::get('/library', [LibraryController::class, 'index'])->name('api.library');

        // What has happened since this device last asked.
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('api.notifications');
        Route::match(['get', 'post'], '/library/delta', [LibraryController::class, 'delta'])
            ->name('api.library.delta');
    });
});
