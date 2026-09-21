<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\TelegramNotify;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;

/**
 * Registers a Telegram notification target (S-264, #281). The worked example of
 * a `notification-target` plugin that also needs settings — a bot token and a
 * chat id, read by the target.
 */
class Plugin implements SoundChexPlugin
{
    /** The setting keys the target reads. */
    public const TOKEN_KEY = 'telegram_bot_token';

    public const CHAT_KEY = 'telegram_chat_id';

    public function getId(): string
    {
        return 'soundchex.telegram-notify';
    }

    public function register(Registry $registry): void
    {
        $registry->notificationTarget(TelegramTarget::class);
    }

    public function boot(Registry $registry): void
    {
        //
    }
}
