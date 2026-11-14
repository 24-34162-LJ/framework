<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain AstraTrack notification email: title, message and an optional "View in AstraTrack" link. */
class NotificationMail extends Mailable
{
    public function __construct(
        public string $subjectLine,
        public string $body,
        public ?string $link = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'AstraTrack: '.$this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.notification');
    }
}
