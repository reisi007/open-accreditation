<?php

namespace App\Services;

use App\Models\Mandant;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Throwable;

/**
 * P5 mandant-aware mail dispatch.
 *
 * MVP decision (2026-08-14): sending is SYNCHRONOUS — the mail volume per
 * accreditation is small and a queue would add operational complexity without
 * a measurable benefit yet. Queue integration is a documented follow-up.
 *
 * Delivery policy:
 *  - When the mandant carries an `smtp_config` with host + port, the mail is
 *    sent through a dedicated Symfony `EsmtpTransport` built from that config
 *    (per-mandant SMTP, e. g. a Verband's own mail server). The `from` stays
 *    the global `config('mail.from')` — mandants get their own SMTP relay, not
 *    their own sender identity.
 *  - Without a usable config the mail falls back to the application default
 *    `smtp` mailer (Mailpit in local dev).
 *  - Delivery errors are logged (`Log::warning`) and swallowed — a broken
 *    mail relay must never break the apply/approval flow itself. The status
 *    transition has already happened by the time the mail is attempted.
 *
 * Encryption mapping (documented deviation from the original `?encryption=`
 * DSN sketch): Symfony's `EsmtpTransportFactory` ignores a DSN `encryption`
 * option entirely, so the config is mapped onto the transport explicitly —
 * `ssl` → implicit TLS (scheme `smtps` equivalent), `tls` → mandatory
 * STARTTLS, anything else → opportunistic STARTTLS (Symfony default).
 */
final class MandantMailerService
{
    /**
     * Deliver a mailable to an applicant of the given mandant.
     */
    public function send(Mandant $mandant, Mailable $mailable): void
    {
        try {
            $transport = $this->transportFor($mandant);

            if ($transport === null) {
                Mail::mailer('smtp')->send($mailable);

                return;
            }

            $mailer = new Mailer(
                'mandant-'.$mandant->getKey(),
                app(Factory::class),
                $transport,
                app(Dispatcher::class),
            );

            $from = config('mail.from');
            $mailer->alwaysFrom(
                (string) ($from['address'] ?? 'no-reply@example.com'),
                $from['name'] ?? null,
            );

            $mailable->send($mailer);
        } catch (Throwable $e) {
            Log::warning('Mandant mail dispatch failed', [
                'mandant_id' => $mandant->getKey(),
                'mailable' => get_class($mailable),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The Symfony transport derived from the mandant's `smtp_config`, or null
     * when the mandant has no usable SMTP config (host + port required) — the
     * caller then falls back to the application default mailer.
     */
    public function transportFor(Mandant $mandant): ?TransportInterface
    {
        $config = $mandant->smtp_config;

        if (! is_array($config)) {
            return null;
        }

        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 0);

        if ($host === '' || $port <= 0) {
            return null;
        }

        $encryption = strtolower(trim((string) ($config['encryption'] ?? '')));

        // `ssl` → implicit TLS right away; `tls` → STARTTLS (enforced);
        // anything else → opportunistic STARTTLS (Symfony default).
        $transport = new EsmtpTransport(
            $host,
            $port,
            $encryption === 'ssl' ? true : null,
        );

        if ($encryption === 'tls') {
            $transport->setRequireTls(true);
        }

        $username = (string) ($config['username'] ?? '');

        if ($username !== '') {
            $transport->setUsername($username);
        }

        $password = (string) ($config['password'] ?? '');

        if ($password !== '') {
            $transport->setPassword($password);
        }

        $stream = $transport->getStream();

        // A hung mandant relay must not block the request/command forever.
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(10);
        }

        return $transport;
    }
}
