<?php

namespace App\Modules\Core\Signatures;

use Illuminate\Mail\Mailable;

/** An e-mail of the online signature (link, code, confirmation), plain text, a PDF attached at most. */
class SignatureMail extends Mailable
{
    /**
     * @param  array{content: string, name: string}|null  $attachment
     */
    public function __construct(public readonly string $mailSubject, public readonly string $text, public readonly ?array $attachment = null) {}

    public function build(): self
    {
        $mail = $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);

        return $this->attachment === null ? $mail : $mail->attachData($this->attachment['content'], $this->attachment['name'], ['mime' => 'application/pdf']);
    }
}
