<?php

use App\Http\Controllers\Api\Transfer\RequestController as TransferRequestController;
use App\Http\Controllers\Api\Transfer\SourceController as TransferSourceController;
use App\Http\Controllers\Api\LibraryController;
use App\Http\Controllers\Api\DeviceReportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ServerHealthController;
use App\Http\Controllers\Api\UpdateController;
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
    });
});
