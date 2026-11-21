<?php

namespace App\Modules\Questionnaires\Campaigns;

use Illuminate\Mail\Mailable;

/** The e-mail of a questionnaire invitation, plain text only. */
class SurveyMail extends Mailable
{
    public function __construct(public readonly string $mailSubject, public readonly string $text) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
    }
}
