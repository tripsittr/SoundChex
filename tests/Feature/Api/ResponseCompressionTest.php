<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature\Api;

use App\Http\Middleware\CompressJsonResponses;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Gzipping the large JSON responses.
 *
 * The library sync is the app's biggest transfer: every item a profile may see,
 * sent whenever the device has nothing to build a delta on. For 1,458 items
 * that was 603 KB on the wire and 94 KB gzipped, so six times more was crossing
 * the network than needed — invisible on a LAN, and the difference between a
 * page appearing and hanging over a relay or a weak phone signal.
 */
class ResponseCompressionTest extends TestCase
{
    private function send(string $body, array $headers = [], string $type = 'application/json'): Response
    {
        $request = Request::create('/api/v1/library', 'GET', server: array_merge(
            ['HTTP_ACCEPT_ENCODING' => 'gzip'],
            $headers,
        ));

        return (new CompressJsonResponses())->handle(
            $request,
            fn () => new Response($body, 200, ['Content-Type' => $type]),
        );
    }

    public function test_a_large_json_response_is_gzipped(): void
    {
        $body = json_encode(['items' => array_fill(0, 500, ['title' => 'A track name'])]);

        $response = $this->send($body);

        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertSame($body, gzdecode($response->getContent()));
    }

    public function test_compression_actually_shrinks_the_payload(): void
    {
        $body = json_encode(['items' => array_fill(0, 500, ['title' => 'A track name'])]);

        $response = $this->send($body);

        $this->assertLessThan(
            strlen($body) / 2,
            strlen($response->getContent()),
            'the point is a materially smaller transfer, not merely a header',
        );
    }

    public function test_a_client_that_cannot_gzip_gets_plain_json(): void
    {
        $body = json_encode(['items' => array_fill(0, 500, ['title' => 'A track name'])]);

        $response = $this->send($body, ['HTTP_ACCEPT_ENCODING' => 'identity']);

        $this->assertNull($response->headers->get('Content-Encoding'));
        $this->assertSame($body, $response->getContent());
    }

    public function test_a_small_response_is_left_alone(): void
    {
        // Below the threshold the CPU time and header overhead cost more than
        // the few hundred bytes saved.
        $response = $this->send(json_encode(['ok' => true]));

        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    public function test_non_json_is_left_alone(): void
    {
        $response = $this->send(str_repeat('x', 8192), type: 'text/html');

        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    public function test_vary_is_set_so_caches_do_not_serve_gzip_to_a_client_that_cannot_read_it(): void
    {
        $body = json_encode(['items' => array_fill(0, 500, ['title' => 'A track name'])]);

        $response = $this->send($body);

        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
    }

    public function test_content_length_matches_the_compressed_body(): void
    {
        $body = json_encode(['items' => array_fill(0, 500, ['title' => 'A track name'])]);

        $response = $this->send($body);

        $this->assertSame(
            strlen($response->getContent()),
            (int) $response->headers->get('Content-Length'),
            'a mismatched length truncates the body or hangs the client',
        );
    }
}
