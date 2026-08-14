<?php

namespace Tests\Unit;

use App\Models\Mandant;
use App\Services\MandantMailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * P5 `MandantMailerService` — per-mandant SMTP transport derivation and the
 * default-mailer fallback.
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

    public function test_send_without_smtp_config_falls_back_to_default_mailer(): void
    {
        Mail::fake();

        $mandant = Mandant::factory()->create(['smtp_config' => null]);
        $mailable = $this->plainMailable();

        $this->service->send($mandant, $mailable);

        Mail::assertSent(get_class($mailable));
    }

    public function test_send_does_not_crash_on_delivery_failure(): void
    {
        // A config pointing at a dead relay must be logged, not thrown — the
        // status transition that triggered the mail has already happened.
        $mandant = Mandant::factory()->create([
            'smtp_config' => [
                'host' => '127.0.0.1',
                'port' => 1,
                'username' => null,
                'password' => null,
            ],
        ]);

        $this->service->send($mandant, $this->plainMailable());

        // No exception thrown — reaching this line is the assertion.
        $this->assertTrue(true);
    }

    private function plainMailable(): Mailable
    {
        return new class extends Mailable
        {
            public function content(): Content
            {
                return new Content(htmlString: '<p>integration check body</p>');
            }
        };
    }
}
