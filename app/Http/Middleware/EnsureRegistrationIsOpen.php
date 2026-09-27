<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Middleware;

use App\Filament\Pages\Settings;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks public sign-up when the household has closed it.
 *
 * The setting existed on the settings page but nothing read it, so
 * registration was always open — including to anyone who found the URL once
 * the server was made publicly reachable. Household members are added from the
 * admin panel instead.
 *
 * A 404 rather than a 403: a closed door should not advertise that there is a
 * door.
 */
class EnsureRegistrationIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(static::isOpen(), 404);

        return $next($request);
    }

    /**
     * Whether anyone may create their own account.
     *
     * Closed when the setting has never been saved: a server that might be
     * public should not accept sign-ups because nobody has visited the
     * settings page yet.
     *
     * That was the stated intent and it was not what happened. `isOpen()` read
     * `?? false`, but `Settings::getStoredSettings()` fills every key from its
     * own defaults and that one defaulted to `true` — so the `??` never saw a
     * null and never fired. A fresh install accepted sign-ups from anyone who
     * could reach it, which for a tunnelled server is the internet, and the
     * first account created is the `owner`.
     *
     * Except on first run, which is the reason the default was permissive. With
     * no users there is no admin panel to add anyone from, so the door has to
     * open wide enough to create the owner — and closes again the moment an
     * account exists. That keeps first-install working without leaving it open
     * afterwards, which the setting alone could not do.
     */
    public static function isOpen(): bool
    {
        if (User::query()->doesntExist()) {
            return true;
        }

        return (bool) (Settings::getStoredSettings()['allow_registration'] ?? false);
    }
}
