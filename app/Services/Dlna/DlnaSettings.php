<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services\Dlna;

use App\Models\Profile;
use App\Services\SettingsService;

/**
 * Whether the DLNA server advertises, and whose library it shows (S-7).
 *
 * DLNA has no authentication — that is the protocol, not an oversight. Any
 * device on the LAN that speaks it sees whatever the server advertises, and it
 * cannot ask who is browsing. So the two things that matter are both settings:
 * whether to advertise at all, and which profile's view to serve.
 *
 * Off by default. A media server that starts announcing the household's
 * library to every device on the Wi-Fi the moment it is installed is not a
 * thing to opt out of afterwards.
 */
class DlnaSettings
{
    public const ENABLED = 'dlna.enabled';

    public const PROFILE = 'dlna.profile_id';

    public const NAME = 'dlna.friendly_name';

    public function __construct(private SettingsService $settings) {}

    /** Whether to advertise on the LAN at all. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED, false);
    }

    /**
     * The profile whose view of the library DLNA serves.
     *
     * A TV cannot say who is watching, so one profile stands for every device
     * on the network — point it at a capped profile and the cap holds on the
     * living-room TV. Null means no profile was chosen, which is treated as
     * "serve nothing" rather than "serve everything": an unset value must not
     * be the permissive one.
     */
    public function profile(): ?Profile
    {
        $id = $this->settings->get(self::PROFILE);

        if (blank($id)) {
            return null;
        }

        return Profile::find($id);
    }

    /** The name devices show in their media-player list. */
    public function friendlyName(): string
    {
        $name = (string) $this->settings->get(self::NAME, '');

        return $name !== '' ? $name : 'SoundChex';
    }

    /**
     * Whether the server should actually run.
     *
     * Both halves: switched on, and pointed at a profile. Advertising a server
     * that answers every browse with an empty list is worse than not
     * advertising — it looks broken rather than off.
     */
    public function shouldRun(): bool
    {
        return $this->enabled() && $this->profile() !== null;
    }
}
