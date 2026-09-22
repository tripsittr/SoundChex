<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Support\Facades\Route;
use SoundChex\PlaylistPorter\Http\PlaylistImportController;
use SoundChex\PlaylistPorter\Http\PlaylistSourceController;

/**
 * The Playlist Porter plugin's own API (S-315).
 *
 * Registered by the plugin through the routes seam (Registry::routes), which
 * wraps this file in the `api/v1` prefix and the `auth:sanctum` guard — so the
 * URLs and route names are exactly what core once declared, but the endpoints
 * are the plugin's and go away when it is disabled.
 *
 * These sit "before" the core `/playlists/{collection}` routes in the table so
 * "imports" and "sources" are not read as a playlist id — the plugin's route
 * file is loaded ahead of that catch-all.
 */

// Porting a playlist in from a file (S-310).
Route::get('/playlists/imports', [PlaylistImportController::class, 'index'])->name('api.playlists.imports');
Route::post('/playlists/imports', [PlaylistImportController::class, 'store'])->name('api.playlists.imports.store');
Route::get('/playlists/imports/{import}', [PlaylistImportController::class, 'show'])->name('api.playlists.imports.show');
Route::post('/playlists/imports/{import}/resolve', [PlaylistImportController::class, 'resolve'])->name('api.playlists.imports.resolve');

// Porting from a connected streaming service (S-312).
Route::get('/playlists/sources', [PlaylistSourceController::class, 'index'])->name('api.playlists.sources');
Route::post('/playlists/sources/{source}/authorize', [PlaylistSourceController::class, 'authorize'])->name('api.playlists.sources.authorize');
Route::post('/playlists/sources/{source}/callback', [PlaylistSourceController::class, 'callback'])->name('api.playlists.sources.callback');
Route::delete('/playlists/sources/{source}', [PlaylistSourceController::class, 'disconnect'])->name('api.playlists.sources.disconnect');
Route::get('/playlists/sources/{source}/playlists', [PlaylistSourceController::class, 'playlists'])->name('api.playlists.sources.playlists');
Route::post('/playlists/sources/{source}/import', [PlaylistSourceController::class, 'import'])->name('api.playlists.sources.import');
