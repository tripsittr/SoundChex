<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Contracts;

use App\Plugins\Registry;

/**
 * The one interface every plugin's entry class implements (S-264).
 *
 * Modelled on Filament's own `Plugin` contract, deliberately: authors who have
 * written a Filament plugin already know this shape, and it keeps the lifecycle
 * to two moments — one that always runs, one that runs only when the app is
 * actually serving a request.
 *
 * A plugin is instantiated through the container, so its constructor may type-
 * hint any bound service. It contributes nothing by existing; everything it
 * adds is pushed into the app through the `Registry` it is handed in `register`.
 */
interface SoundChexPlugin
{
    /**
     * A stable, unique identifier — reverse-DNS or a slug ("acme.discogs").
     *
     * This namespaces the plugin's registrations and is how the manifest, the
     * installed-plugins table and the registry all refer to it, so it must match
     * the manifest's `id` and never change across versions.
     */
    public function getId(): string;

    /**
     * Wire the plugin's contributions into the app.
     *
     * Runs once at boot for every enabled plugin, before any request is served.
     * Register metadata sources, event listeners, routes and admin pages on the
     * passed registry here — not in the constructor, which cannot see it.
     */
    public function register(Registry $registry): void;

    /**
     * Runtime wiring that should happen only when the app is actually serving.
     *
     * Optional in spirit — most plugins leave it empty. Kept separate from
     * `register()` so work that has no business running during a console command
     * or a queue worker does not.
     */
    public function boot(Registry $registry): void;
}
