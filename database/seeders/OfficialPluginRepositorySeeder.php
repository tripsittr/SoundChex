<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Database\Seeders;

use App\Models\PluginRepository;
use Illuminate\Database\Seeder;

/**
 * The official plugin repository (S-321).
 *
 * With no bundled plugins, a fresh install discovers plugins through
 * repositories. This registers the project's own curated one — the catalogue
 * that lists the first-party plugins — so the Browse Plugins page has something
 * to offer out of the box. Idempotent, and a no-op until a URL is configured.
 */
class OfficialPluginRepositorySeeder extends Seeder
{
    public function run(): void
    {
        $url = (string) config('soundchex.plugins.official_repository');

        if ($url === '') {
            return;
        }

        PluginRepository::query()->updateOrCreate(
            ['url' => $url],
            ['name' => 'SoundChex Official', 'official' => true],
        );
    }
}
