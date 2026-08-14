<?php

namespace App\Support;

use App\Models\Application;

/**
 * Builds the public verification link used in approval/pass mails.
 *
 * The host must come from the application's mandant domain (multi-tenant:
 * every mandant runs on its own domain, so a link to the primary domain would
 * land the applicant on the wrong tenant). Fallback chain mirrors the
 * activation-link logic in `AuthController`: mandant's first domain host →
 * `config('app.url')` host → request host. The scheme always follows
 * `config('app.url')` (https in prod, http in local).
 *
 * The signed `qr_token` is the access credential; dispatch sites guarantee it
 * exists (via `QrTokenService::make`) before the link is built.
 */
final class VerifyLink
{
    public static function for(Application $application): string
    {
        $baseUrl = (string) config('app.url');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

        $host = $application->accreditation?->mandant?->domains->first()?->hostname;

        if ($host === null || $host === '') {
            $host = parse_url($baseUrl, PHP_URL_HOST);
        }

        if ($host === null || $host === '') {
            $host = request()->getHost();
        }

        return $scheme.'://'.$host.'/verify/'.(string) $application->qr_token;
    }
}
