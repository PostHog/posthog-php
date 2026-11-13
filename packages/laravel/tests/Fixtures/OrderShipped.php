<?php

namespace PostHog\Laravel\Tests\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class OrderShipped extends Mailable
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(private readonly array $headers = [])
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(from: 'shop@example.com', subject: 'Your order shipped');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>On its way.</p>');
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->headers);
    }
}
