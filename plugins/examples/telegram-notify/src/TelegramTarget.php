<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\TelegramNotify;

use App\Models\Notification;
use App\Plugins\Contracts\NotificationTarget;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a notification to a Telegram chat via the Bot API (S-264, #281).
 *
 * A worked example of a real notification target: it reads its bot token and
 * chat id from settings, is "configured" only when both are present, and posts
 * the notification's title and body. Failures are logged and swallowed — a dead
 * Telegram must never break the scan that recorded the notification.
 */
class TelegramTarget implements NotificationTarget
{
    public function name(): string
    {
        return 'Telegram';
    }

    public function isConfigured(): bool
    {
        return filled($this->token()) && filled($this->chatId());
    }

    public function send(Notification $notification): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $text = trim($notification->title."\n".($notification->body ?? ''));

        try {
            Http::timeout(10)->post("https://api.telegram.org/bot{$this->token()}/sendMessage", [
                'chat_id' => $this->chatId(),
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram notification failed to send', ['error' => $e->getMessage()]);
        }
    }

    private function token(): ?string
    {
        return app(SettingsService::class)->get(Plugin::TOKEN_KEY);
    }

    private function chatId(): ?string
    {
        return app(SettingsService::class)->get(Plugin::CHAT_KEY);
    }
}
