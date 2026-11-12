<?php

namespace App\Modules\Core\Platform;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Operational alert for the instance administrator (failed backup, failed
 * update...). Plain text, French, sent with the instance mailer: it is not
 * tied to any organisme.
 */
class AlertMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $details) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.alert');
    }
}
