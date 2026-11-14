<?php

namespace App\Modules\Core\Mail;

use Illuminate\Mail\Mailable;

/**
 * « Envoyer un e-mail de test »: the template filled with sample values, as
 * plain text, its subject prefixed with « [Test] ».
 */
class MailTemplateTestMail extends Mailable
{
    public function __construct(private readonly string $mailSubject, private readonly string $text) {}

    public function build(): self
    {
        return $this->subject('[Test] '.$this->mailSubject)->text('mail.quote', ['text' => $this->text]);
    }
}
