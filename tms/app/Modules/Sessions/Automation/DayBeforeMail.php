<?php

namespace App\Modules\Sessions\Automation;

use Illuminate\Mail\Mailable;

/** The day-before reminder (trainee or trainer), plain text only. */
class DayBeforeMail extends Mailable
{
    public function __construct(public readonly string $mailSubject, public readonly string $text) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
    }
}
