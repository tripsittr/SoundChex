<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Services;

use RuntimeException;

/**
 * The catalogue, compressed to a file for another server to fetch.
 *
 * A class rather than a few lines inside the controller, so a test can run the
 * real thing. The first version compressed straight to the client with
 * `gzopen('php://output')`, which fails with "could not make seekable" — the
 * handle seeks and output does not — and the first real transfer got a 500
 * where the catalogue should have been.
 *
 * The test written for that fix re-implemented the compression instead of
 * calling it, so it passed whether the endpoint worked or not. Extracting it
 * is what makes the test able to fail.
 */
class CatalogueArchive
{
    /** 512 KB at a time, against a database measured at 24 MB. */
    private const CHUNK = 524288;

    /**
     * Compresses `$source`, returning the path it was written to.
     *
     * To a file, never to output. The saving is 85% — 24 MB becomes 3.6 — and
     * it costs a few seconds and a few megabytes of scratch disk, which is the
     * trade that makes the download work at all.
     *
     * @throws RuntimeException when either end cannot be opened, so a caller
     *                          cannot mistake a failed compression for an
     *                          empty catalogue
     */
    public function compress(string $source, ?string $destination = null): string
    {
        $destination ??= $source . '.gz';

        $in = @fopen($source, 'rb');

        if ($in === false) {
            throw new RuntimeException('Could not read the catalogue to compress it.');
        }

        $out = @gzopen($destination, 'wb6');

        if ($out === false) {
            fclose($in);

            throw new RuntimeException('Could not open the archive to write it.');
        }

        while (! feof($in)) {
            $chunk = fread($in, self::CHUNK);

            // A read failure part way is not an end of file, and treating it
            // as one would ship a truncated catalogue that unpacks cleanly.
            if ($chunk === false) {
                fclose($in);
                gzclose($out);
                @unlink($destination);

                throw new RuntimeException('The catalogue could not be read to the end.');
            }

            gzwrite($out, $chunk);
        }

        fclose($in);
        gzclose($out);

        return $destination;
    }
}
