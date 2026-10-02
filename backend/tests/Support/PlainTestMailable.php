<?php

namespace Tests\Support;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;

/**
 * A concrete, serializable mailable for queue tests.
 *
 * The previous unit test used an anonymous `Mailable` subclass; a queued mail
 * job serializes its mailable into the payload, and PHP refuses to serialize an
 * anonymous class ("Serialization of 'class@anonymous' is not allowed"). A
 * named class with scalar properties is what production mailables are, so this
 * is the closer fixture.
 */
class PlainTestMailable extends Mailable
{
    public function __construct(
        public string $subjectLine = 'integration check',
    ) {}

    public function content(): Content
    {
        return new Content(htmlString: '<p>integration check body</p>');
    }
}
