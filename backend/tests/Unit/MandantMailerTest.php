<?php

namespace Tests\Unit;

use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;

/**
 * P5 `MandantMailerService` — per-mandant SMTP transport derivation and the
 * queued default-mailer fallback.
 *
 * ## Policy change (Position 45, 2026-10-02)
 *
 * `send()` used to dial the relay synchronously and swallow every `Throwable`
 * (the old `test_send_does_not_crash_on_delivery_failure` asserted exactly that
 * with `assertTrue(true)`). It now only dispatches `SendMandantMail`; the
 * transport is spoken to in `deliver()`, which THROWS on failure so the queue
 * can retry and finally dead-letter the mail. The two tests below pin both
 * halves of that split.
 */
class MandantMailerTest extends TestCase
{
    use RefreshDatabase;

    private MandantMailerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(MandantMailerService::class);
    }

    public function test_transport_derives_host_port_credentials_and_tls_from_smtp_config(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => 'smtp.example.com',
                'port' => 587,
                'username' => 'mailuser',
                'password' => 'mailpass',
                'encryption' => 'tls',
            ],
        ]);

        $transport = $this->service->transportFor($mandant);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('smtp.example.com', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());
        $this->assertSame('mailuser', $transport->getUsername());
        $this->assertSame('mailpass', $transport->getPassword());
        // `tls` → enforced STARTTLS.
        $this->assertTrue($transport->isTlsRequired());
        // A hung relay must not block the request indefinitely.
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame(10.0, $stream->getTimeout());
    }

    public function test_ssl_encryption_uses_implicit_tls(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => 'smtp.example.com',
                'port' => 465,
                'username' => 'mailuser',
                'password' => null,
                'encryption' => 'ssl',
            ],
        ]);

        $transport = $this->service->transportFor($mandant);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame(465, $transport->getStream()->getPort());
        $this->assertSame('mailuser', $transport->getUsername());
        $this->assertSame('', $transport->getPassword());
        $this->assertTrue($transport->getStream()->isTLS());
    }

    public function test_transport_without_smtp_config_returns_null(): void
    {
        $mandant = Mandant::factory()->create(['smtp_config' => null]);

        $this->assertNull($this->service->transportFor($mandant));
    }

    public function test_transport_without_host_or_port_returns_null(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => ['username' => 'mailuser'],
        ]);

        $this->assertNull($this->service->transportFor($mandant));
    }

    /**
     * `send()` is a pure dispatch: with the queue faked it touches no transport
     * at all, even when the mandant's relay config is dead. That is the
     * fire-and-forget shape `AllocationService` / `SendReminders` rely on.
     */
    public function test_send_only_dispatches_a_job_and_never_touches_the_relay(): void
    {
        Queue::fake();

        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => '127.0.0.1', 'port' => 1],
        ]);

        $this->service->send($mandant, (new PlainTestMailable)->to('applicant@example.test'));

        Queue::assertPushed(SendMandantMail::class, function (SendMandantMail $job) use ($mandant): bool {
            return $job->mandantId === $mandant->id
                && $job->recipient === 'applicant@example.test'
                && $job->mailableClass === PlainTestMailable::class;
        });
    }

    public function test_send_without_smtp_config_falls_back_to_default_mailer(): void
    {
        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);

        // Under the suite's `QUEUE_CONNECTION=sync` the job runs inline, so the
        // fallback path is observable through the Mail fake.
        $this->service->send($mandant, (new PlainTestMailable)->to('applicant@example.test'));

        Mail::assertSent(PlainTestMailable::class);
    }

    /**
     * The policy reversal: a failed delivery is NO LONGER swallowed by the
     * service. `deliver()` is what the queue worker runs, and it must surface
     * the failure so the job retries (and, after the cap, dead-letters).
     */
    public function test_deliver_propagates_a_transport_failure_instead_of_swallowing_it(): void
    {
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'username' => null,
                'password' => null,
            ],
        ]);

        $this->expectException(TransportExceptionInterface::class);

        $this->service->deliver($mandant, (new PlainTestMailable)->to('applicant@example.test'));
    }
}
