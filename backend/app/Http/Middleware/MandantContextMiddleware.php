<?php

namespace App\Http\Middleware;

use App\Support\MandantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MandantContextMiddleware
{
    /**
     * Resolve the mandant from the request host and set it as the current
     * mandant. An unknown host results in a 404 — except in test/CLI contexts
     * (PHPUnit runs as a console process), where we continue without a mandant
     * because tests set the mandant manually via MandantContext::set().
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $this->resolveHost($request);

        if ($host !== '') {
            $mandant = MandantContext::resolve($host);

            if ($mandant !== null) {
                MandantContext::set($mandant);

                return $next($request);
            }

            if (! $this->skipsUnknownMandant()) {
                abort(404, 'Mandant not found');
            }
        }

        return $next($request);
    }

    /**
     * The request host, lower-cased. In local dev the Vite proxy rewrites the
     * Host header — the Referer header (sent automatically by browsers) still
     * carries the original host, so we prefer it (mirrors the portal's
     * BrandContextMiddleware).
     */
    private function resolveHost(Request $request): string
    {
        if (app()->environment('local')) {
            $refererHost = parse_url((string) $request->header('referer', ''), PHP_URL_HOST);

            if (is_string($refererHost) && $refererHost !== '') {
                return strtolower($refererHost);
            }
        }

        return strtolower($request->getHost());
    }

    /**
     * HTTP middleware never executes in a pure CLI process, but PHPUnit runs
     * as a console process — tests without a resolvable host (e.g. an
     * unseeded `localhost`) must not 404; they set the mandant manually.
     */
    private function skipsUnknownMandant(): bool
    {
        return app()->runningInConsole() || app()->environment('testing');
    }
}
