<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\ActivityLog;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;
use Illuminate\Support\Facades\View;
use SoundChex\ActivityLog\Filament\AuditLog;

/**
 * The unified activity/audit log, written as a first-party bundled plugin
 * (S-284).
 *
 * This is the flagship demonstration of the whole plugin platform in one place:
 * it subscribes to the *entire* event catalogue (S-276) and writes each event to
 * one timeline, and it contributes a whole page to the Filament admin through the
 * new admin-page seam (#284) — proving a plugin can add a screen, not just a
 * settings form. Auditing used to be scattered across MetadataVersion,
 * Notification, DeviceReport and MediaPlay; this puts one observable, filterable
 * stream on top of them, and does it as a plugin rather than as core code.
 *
 * Bundled and always on, so a fresh install has the timeline from day one.
 */
class Plugin implements SoundChexPlugin
{
    public function getId(): string
    {
        return 'soundchex.activity-log';
    }

    public function register(Registry $registry): void
    {
        // The page's blade lives in this plugin, under a `activity-log` view
        // namespace so `activity-log::audit-log` resolves.
        View::addNamespace('activity-log', __DIR__.'/../resources/views');

        // Add the Audit Log page to the admin nav. The page gates itself to
        // server admins via canAccess(); this only makes Filament aware of it.
        $registry->adminPage(AuditLog::class);

        // Subscribe to every built-in event and record it. Subscribing by the
        // catalogue rather than a hand-kept list means a new event is captured
        // the moment it is registered — the audit log never falls behind the
        // surface it audits.
        $recorder = app(AuditRecorder::class);

        foreach (Registry::builtInEvents() as $event) {
            $registry->on($event, function (object $payload) use ($recorder, $event): void {
                $recorder->record($event, $payload);
            });
        }
    }

    public function boot(Registry $registry): void
    {
        // Nothing to do at serving time — the recording is wired in register().
    }
}
