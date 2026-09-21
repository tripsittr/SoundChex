<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Support\DataPaths;
use Tests\TestCase;

/**
 * Plugins live in a dedicated data directory outside the install, resolved for
 * the platform, and overridable by an operator (S-286). A downloaded server
 * release must never keep plugins under its own source tree or show the build
 * machine's path.
 */
class PluginDataDirectoryTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SOUNDCHEX_PLUGINS_PATH');
        putenv('SOUNDCHEX_DATA_DIR');
        parent::tearDown();
    }

    public function test_the_default_plugins_path_is_outside_the_install(): void
    {
        putenv('SOUNDCHEX_PLUGINS_PATH');
        putenv('SOUNDCHEX_DATA_DIR');

        $path = DataPaths::plugins();

        $this->assertStringEndsWith('plugins', $path);
        // Not under the app's own source tree — that is the whole point.
        $this->assertStringNotContainsString(base_path(), $path);
    }

    public function test_an_explicit_plugins_path_overrides_everything(): void
    {
        putenv('SOUNDCHEX_PLUGINS_PATH=/srv/soundchex/plugins');

        $this->assertSame('/srv/soundchex/plugins', DataPaths::plugins());
    }

    public function test_a_data_dir_override_places_plugins_under_it(): void
    {
        putenv('SOUNDCHEX_PLUGINS_PATH');
        putenv('SOUNDCHEX_DATA_DIR=/var/lib/soundchex');

        $this->assertSame(
            '/var/lib/soundchex'.DIRECTORY_SEPARATOR.'plugins',
            DataPaths::plugins(),
        );
    }

    public function test_a_trailing_separator_on_an_override_is_trimmed(): void
    {
        putenv('SOUNDCHEX_PLUGINS_PATH=/srv/soundchex/plugins/');

        $this->assertSame('/srv/soundchex/plugins', DataPaths::plugins());
    }
}
