<?php

namespace App\Modules\Admissions\Positioning;

use Illuminate\Mail\Mailable;

/**
 * The e-mail of a positioning test: the subject and the message typed in
 * the window (link filled in), as plain text only, with the PDF of each test.
 */
class PositioningMail extends Mailable
{
    /**
     * @param  list<array{name: string, content: string}>  $files
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $text,
        private readonly array $files,
    ) {}

    public function build(): self
    {
        $mail = $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
        foreach ($this->files as $file) {
            $mail->attachData($file['content'], $file['name'], ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
