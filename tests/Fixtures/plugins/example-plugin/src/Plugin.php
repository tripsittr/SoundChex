<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\Example;

use App\Plugins\Contracts\SoundChexPlugin;
use App\Plugins\Registry;

/**
 * A minimal plugin the loader tests exercise. It registers a metadata source and
 * records that its lifecycle methods ran through a shared cache the test can read
 * without naming this class at compile time (its class is only autoloadable at
 * runtime, via the loader under test). Deliberately trivial — the loader, not
 * the plugin, is what these tests cover.
 */
class Plugin implements SoundChexPlugin
{
    /** Where lifecycle calls are recorded, so a test reads them by key. */
    public const CALLS_KEY = 'plugin-test.example.calls';

    public function getId(): string
    {
        return 'soundchex.example';
    }

    public function register(Registry $registry): void
    {
        $this->recordCall('register');
        $registry->metadataSource('music', ExampleSource::class, 50);
    }

    public function boot(Registry $registry): void
    {
        $this->recordCall('boot');
    }

    private function recordCall(string $name): void
    {
        $calls = cache()->get(self::CALLS_KEY, []);
        $calls[] = $name;
        cache()->forever(self::CALLS_KEY, $calls);
    }
}
