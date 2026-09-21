<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\TitleCase;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;
use App\Services\SettingsService;

/**
 * A worked-example plugin: rewrites titles to a case style the admin chooses
 * (S-264).
 *
 * It shows the two things a configurable behaviour plugin needs — a
 * `metadata.title` filter (the seam that changes a value passing through), and a
 * setting the plugin reads to decide how to behave. The whole plugin is one
 * filter and one settings read; the case logic lives in TitleCaser so it stays
 * testable on its own.
 */
class Plugin implements SoundChexPlugin
{
    /** The setting key the admin sets to choose the style. */
    public const STYLE_KEY = 'title_case_style';

    public function getId(): string
    {
        return 'soundchex.title-case';
    }

    public function register(Registry $registry): void
    {
        $registry->filter('metadata.title', function (string $title): string {
            $style = (string) app(SettingsService::class)->get(self::STYLE_KEY, TitleCaser::TITLE);

            return (new TitleCaser)->apply($title, $style);
        });
    }

    public function boot(Registry $registry): void
    {
        //
    }
}
