<?php

namespace App\Services;

use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * P5 mandant-aware mail dispatch.
 *
 * ## Position 45 (2026-10-02): sending is ASYNCHRONOUS
 *
 * The MVP decision (synchronous send, "queue integration is a documented
 * follow-up") is superseded. `send()` now only DISPATCHES a
 * {@see SendMandantMail} job; the actual SMTP I/O happens inside
 * {@see self::deliver()}, on a queue worker. Consequences that the callers rely
 * on:
 *
 *  - `send()` cannot fail because a relay is down — it is a queue write. The
 *    status transition and the delivery order are written in one transaction
 *    (`config/queue.php` → `after_commit => true`), so a rolled-back decision
 *    sends nothing and a committed one always leaves an order behind.
 *  - `deliver()` DOES throw on a transport failure. That is the point: the
 *    queue retries it with a capped backoff, and after the last attempt the job
 *    is dead-lettered in `failed_jobs` (with its `mandant_id`) instead of being
 *    swallowed. The former `catch (Throwable)` here is gone; the audited
 *    failure log now lives in `SendMandantMail::failed()`.
 *
 * Delivery policy (unchanged):
 *  - When the mandant carries an `smtp_config` with host + port, the mail is
 *    sent through a dedicated Symfony `EsmtpTransport` built from that config
 *    (per-mandant SMTP, e. g. a Verband's own mail server). The `from` stays
 *    the global `config('mail.from')` — mandants get their own SMTP relay, not
 *    their own sender identity.
 *  - Without a usable config the mail falls back to the application default
 *    `smtp` mailer (Mailpit in local dev).
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
     * Queue a delivery of a mailable to an applicant of the given mandant.
     *
     * Does NOT touch the network: it dispatches {@see SendMandantMail}, which
     * carries the mandant id, the mailable and a retry/idempotency identity.
     * Callers keep the old fire-and-forget shape — the only new contract is
     * that the mail is not necessarily gone when this method returns.
     */
    public function send(Mandant $mandant, Mailable $mailable): void
    {
        SendMandantMail::dispatch($mandant->getKey(), $mailable);
    }

    /**
     * Deliver the mailable through the mandant's transport (or the default
     * mailer). Executed by the queue worker, so a transport failure is thrown
     * on purpose: the worker needs it to retry and finally dead-letter the job.
     */
    public function deliver(Mandant $mandant, Mailable $mailable): void
    {
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
    }

    /**
     * The Symfony transport derived from the mandant's `smtp_config`, or null
     * when the mandant has no usable SMTP config (host + port required) — the
     * caller then falls back to the application default mailer.
     */
    public function transportFor(Mandant $mandant): ?TransportInterface
    {
        $config = $this->readSmtpConfig($mandant);

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

    /**
     * The mandant's readable `smtp_config`, or null when there is none.
     *
     * F4: `smtp_config` is `encrypted:json` (WP-6-d), so a row written BEFORE
     * that cast holds plain JSON and the encrypter raises `DecryptException` on
     * every read. An unreadable config means "no mandant relay", which is
     * exactly what a null return expresses; the warning tells the operator which
     * mandant still owes the documented re-save. This degradation is deliberate
     * and is NOT the old silent drop: before Position 45 the `send()` wrapper
     * swallowed the exception and the mail vanished without even reaching the
     * default-mailer fallback; now `transportFor()` converts it to null and
     * `deliver()` still sends the mail through the default mailer.
     *
     * @return array<string, mixed>|null
     */
    private function readSmtpConfig(Mandant $mandant): ?array
    {
        try {
            $config = $mandant->smtp_config;
        } catch (DecryptException) {
            Log::warning('A mandant row carries an smtp_config that predates the encrypted cast; its mail falls back to the default mailer until the operator re-saves it.', [
                'mandant_id' => $mandant->getKey(),
            ]);

            return null;
        }

        return is_array($config) ? $config : null;
    }
}
