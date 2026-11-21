<?php

namespace App\Modules\Sessions\Attendance\Signing;

use Illuminate\Mail\Mailable;

/** An e-mail of a remote signing catch-up (link or code), plain text only. */
class CatchUpMail extends Mailable
{
    public function __construct(public readonly string $mailSubject, public readonly string $text) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
    }
}
