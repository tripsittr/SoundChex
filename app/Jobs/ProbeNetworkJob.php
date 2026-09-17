<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Jobs;

use App\Services\NetworkAddresses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Measures the server's own addresses, off the web request.
 *
 * `artisan serve` is single-threaded: a request made while serving a request
 * waits on the process that would answer it and times out. So "Retest" cannot
 * probe inline — it queues this and the page reads the result on its next
 * poll. The queue worker is a separate process, which is exactly what makes
 * the request answerable.
 */
class ProbeNetworkJob implements ShouldQueue
{
    use Queueable;

    /** Long enough for three addresses at a 5s timeout, and no longer. */
    public int $timeout = 30;

    public function handle(NetworkAddresses $network): void
    {
        $network->measure();
    }
}
