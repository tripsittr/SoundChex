<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Events\Concerns;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The shape every plugin-observable event shares (S-264 / S-276).
 *
 * A domain event a plugin can subscribe to is a small, immutable value object
 * with a stable `NAME` (dot notation, e.g. `transcode.finished`) and a
 * `readonly` payload. This trait carries the one piece they all share —
 * `Dispatchable`, so an event fires with `Event::dispatch(...)` — and marks the
 * family, so the catalogue and the docs can find them. Each event still declares
 * its own `NAME` and constructor.
 *
 * Firing an event is inert when no plugin listens, so a large surface costs a
 * plugin-less install nothing but the dispatch call.
 */
trait PluginEvent
{
    use Dispatchable;
}
