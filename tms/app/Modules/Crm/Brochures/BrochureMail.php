<?php

namespace App\Modules\Crm\Brochures;

use Illuminate\Mail\Mailable;

/**
 * « Envoyer une plaquette »: the subject and message typed in the window,
 * as plain text only (no HTML part), with the brochures' PDFs attached under
 * their original names.
 */
class BrochureMail extends Mailable
{
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $text,
        /** @var list<array{bytes: string, name: string}> */
        private readonly array $files,
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
        foreach ($this->files as $file) {
            $mail->attachData($file['bytes'], $file['name'], ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
