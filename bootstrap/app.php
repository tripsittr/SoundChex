<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a tunnel or reverse proxy, the app sees the proxy's address
        // and a plain-HTTP scheme unless it trusts the forwarded headers.
        // Without this, generated URLs come out as http:// and rate limiting
        // would throttle every remote visitor as one shared IP.
        $middleware->trustProxies(at: '*');

        // Generate URLs that match the address each request came in on, rather
        // than the single one in APP_URL — this server answers on a LAN address,
        // a tailnet IP and a Funnel hostname at once. Runs on web and API both,
        // since the API hands back stream and artwork URLs. Prepended so the
        // corrected root is in place before anything downstream builds a URL.
        // (CLI/queue have no request; server:detect-address keeps APP_URL sane
        // for them.)
        $middleware->web(prepend: [\App\Http\Middleware\SetAppUrl::class]);
        $middleware->api(prepend: [\App\Http\Middleware\SetAppUrl::class]);

        // The library sync ships every visible item, which for a real library
        // is hundreds of kilobytes — six times more than it needs to be.
        $middleware->api(append: [
            \App\Http\Middleware\CompressJsonResponses::class,
        ]);

        // Cover art was served with no caching headers, so a music page showing
        // forty covers asked for forty files every time it opened — 6,958
        // requests in one afternoon here. Over a relayed connection that is the
        // difference between a page that loads and one that does not.
        $middleware->web(append: [
            \App\Http\Middleware\CacheStaticMedia::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
