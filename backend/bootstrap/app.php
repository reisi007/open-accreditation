<?php

use App\Http\Middleware\MandantContextMiddleware;
use App\Models\MandantDomain;
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
        // B3: host-header allow-list. Symfony validates every request Host
        // against these patterns and rejects foreign hosts (400) before any
        // route/mandant logic runs — MandantContextMiddleware's unknown-host
        // 404 is an additional layer for hosts that pass the allow-list but
        // map to no mandant. Entries are regexes (Symfony wraps each in
        // `{...}i`): plain hostnames match exactly, `*.test`-style wildcards
        // must be spelled as `^(.+\.)?test$`. `localhost:5173` cannot match
        // (getHost() strips the port) — it is kept for documentation of the
        // Vite dev origin, which is effectively covered by `localhost`.
        // The DB list makes the allow-list dynamic per mandant domain;
        // defensive when the DB is unavailable/empty (console, installs).
        $middleware->trustHosts(function (): array {
            $hosts = [
                'localhost',
                '127.0.0.1',
                '^\[::1\]$',
                '^(.+\.)?test$',
                '^(.+\.)?localhost$',
                'localhost:5173',
            ];

            try {
                $domains = MandantDomain::query()
                    ->pluck('hostname')
                    ->map(static fn (string $hostname): string => preg_quote($hostname, '{}'))
                    ->all();
            } catch (Throwable) {
                $domains = [];
            }

            return array_values(array_unique(array_filter(array_merge($hosts, $domains))));
        });

        $middleware->append(MandantContextMiddleware::class);
        // This is an API-only SPA backend: guests must never be redirected to
        // a `login` HTML route (which does not exist). Unauthenticated requests
        // render as 401 JSON for api/* (see shouldRenderJsonWhen below), or a
        // 401 no-content response otherwise.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
