<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Plugins\Contracts;

use App\Models\Notification;

/**
 * A destination a plugin can send server notifications to (S-264, #281).
 *
 * The built-in webhook notifier reaches Discord, Slack and a generic hook; a
 * plugin notification target reaches somewhere else — Telegram, ntfy, Pushover,
 * a home dashboard. Every recorded notification (a finished scan, a new episode,
 * a failed download) is offered to each active target alongside the built-ins.
 *
 * `isConfigured()` is the cheap gate — a target with no credentials returns
 * false and is skipped, so an installed-but-unconfigured plugin costs nothing.
 * `send()` must swallow its own failures: a dead endpoint cannot be allowed to
 * break the scan or download that recorded the notification.
 */
interface NotificationTarget
{
    /** A human-readable name, for logs and the admin UI. */
    public function name(): string;

    /** Whether this target has what it needs to send (a URL, a token). */
    public function isConfigured(): bool;

    /** Deliver one notification. Must not throw on a delivery failure. */
    public function send(Notification $notification): void;
}
