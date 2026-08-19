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

        // The library sync ships every visible item, which for a real library
        // is hundreds of kilobytes — six times more than it needs to be.
        $middleware->api(append: [
            \App\Http\Middleware\CompressJsonResponses::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
