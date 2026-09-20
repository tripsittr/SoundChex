<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Models\Notification;
use App\Services\WebhookNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one recorded notification to the configured webhooks, off the request
 * (S-262/S-263), so a slow or unreachable endpoint never blocks the scan,
 * download or job that recorded the event.
 */
class SendWebhookNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public int $notificationId) {}

    public function handle(WebhookNotifier $notifier): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification !== null) {
            $notifier->send($notification);
        }
    }
}
