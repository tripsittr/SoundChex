<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gzips large JSON responses.
 *
 * The library sync is the app's biggest transfer by far — every item the
 * profile may see, sent whenever the device has nothing to build a delta on.
 * For 1,458 items that is 603 KB uncompressed and 94 KB gzipped, so six times
 * more was crossing the wire than needed. On a LAN that is invisible; over a
 * relay, or on a phone with a weak signal, it is the difference between a page
 * that appears and one that hangs.
 *
 * PHP's own zlib.output_compression does not apply here — the built-in server
 * does not enable it, and in production the app may sit behind a proxy that
 * does not either. Doing it in the response means the saving holds whatever is
 * in front.
 */
class CompressJsonResponses
{
    /**
     * Below this, compression costs more than it saves: the CPU time and the
     * header overhead outweigh a few hundred bytes.
     */
    private const THRESHOLD = 4096;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $compressed = gzencode($response->getContent(), 6);

        // A failed gzip returns false; the uncompressed response is still
        // perfectly valid, so this degrades rather than erroring.
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        // Caches keyed only on the URL would otherwise hand a gzipped body to a
        // client that cannot read one.
        $response->headers->set('Vary', 'Accept-Encoding');

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! str_contains((string) $request->header('Accept-Encoding'), 'gzip')) {
            return false;
        }

        // Already encoded by something else in the chain.
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        // Streamed and binary responses are excluded: media is already
        // compressed, and a streamed response has no content to read here
        // without buffering the whole thing into memory.
        if (! $response->headers->contains('Content-Type', 'application/json')) {
            return false;
        }

        return strlen((string) $response->getContent()) >= self::THRESHOLD;
    }
}
