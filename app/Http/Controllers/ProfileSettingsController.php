<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Http\Controllers;

use App\Models\Profile;
use App\Services\CurrentProfile;
use App\Services\MediaBrowser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Settings for whoever is watching.
 *
 * Distinct from the admin panel, which configures the server: scanning
 * intervals, duplicate handling, OCR, who may register. Those are one answer
 * for the whole installation. These belong to a profile — two people sharing a
 * device should not share an autoplay preference or each other's PIN.
 *
 * Reached from the media centre rather than the admin panel, because most
 * profiles have no admin access at all and would otherwise have nowhere to
 * change anything about their own experience.
 */
class ProfileSettingsController extends Controller
{
    public function __construct(private MediaBrowser $browser) {}

    public function edit(): View
    {
        $profile = $this->profile();

        return view('media.settings', [
            'counts' => $this->browser->counts(),
            'profile' => $profile,
            'preferences' => $profile?->preferences() ?? Profile::PREFERENCE_DEFAULTS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $this->profile();

        if ($profile === null) {
            return back()->with('settings_error', 'No profile is selected.');
        }

        $data = $request->validate([
            'autoplay_next' => ['boolean'],
            'remember_position' => ['boolean'],
            'prefer_downloaded' => ['boolean'],
            // Zero means off. Capped because a crossfade longer than the gap
            // between two short tracks would overlap a third.
            'crossfade_seconds' => ['integer', 'min:0', 'max:12'],
            'notifications_enabled' => ['boolean'],
            'notify_download_complete' => ['boolean'],
            'notify_scan_complete' => ['boolean'],
            'confirm_download_removal' => ['boolean'],
            'reduce_motion' => ['boolean'],
        ]);

        // Checkboxes send nothing when unticked, so anything absent from the
        // request is false rather than unchanged — otherwise a toggle could be
        // turned on but never off.
        foreach (array_keys(Profile::PREFERENCE_DEFAULTS) as $key) {
            if (is_bool(Profile::PREFERENCE_DEFAULTS[$key])) {
                $data[$key] = $request->boolean($key);
            }
        }

        $profile->setPreferences($data);

        return back()->with('settings_status', 'Settings saved.');
    }

    /**
     * Sets, changes or clears the PIN on this profile.
     *
     * Separate from the preferences form: a PIN is a credential rather than a
     * setting, and mixing it in would mean re-entering it to change an unrelated
     * toggle.
     */
    public function updatePin(Request $request): RedirectResponse
    {
        $profile = $this->profile();

        if ($profile === null) {
            return back()->with('pin_error', 'No profile is selected.');
        }

        $request->validate([
            'current_pin' => ['nullable', 'string'],
            'pin' => ['nullable', 'digits_between:4,8', 'confirmed'],
        ]);

        // Proving the old one first. Without this, anyone who reached an
        // unlocked session could lock a profile they do not own out of it, or
        // quietly replace the PIN protecting someone else's.
        if ($profile->requiresPin() && ! $profile->verifyPin((string) $request->input('current_pin'))) {
            throw ValidationException::withMessages([
                'current_pin' => 'That is not the current PIN.',
            ]);
        }

        $pin = $request->input('pin');

        if (blank($pin)) {
            $profile->update(['pin_hash' => null, 'pin_attempts' => 0, 'pin_locked_until' => null]);

            return back()->with('pin_status', 'PIN removed.');
        }

        $profile->update([
            'pin_hash' => Hash::make($pin),
            'pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        return back()->with('pin_status', 'PIN updated.');
    }

    private function profile(): ?Profile
    {
        return app(CurrentProfile::class)->get();
    }
}
