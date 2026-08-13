<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // VS Code port forwarding (and ngrok/Cloudflare Tunnel) terminate TLS at
        // their edge and reach PHP over plain http on loopback, describing the
        // real request in X-Forwarded-*. Without trusting those headers Laravel
        // believes every request is http://localhost, so route() and asset()
        // emit http:// URLs that the browser then blocks as mixed content on an
        // https tunnel — the page loads but its assets and links do not.
        //
        // '*' is safe here only because nothing but a local tunnel or the local
        // web server can reach this app. A public deployment must narrow this to
        // the actual proxy addresses, otherwise a client can spoof its own
        // scheme, host, and IP.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
