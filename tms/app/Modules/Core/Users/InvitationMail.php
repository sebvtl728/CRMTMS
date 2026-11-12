<?php

namespace App\Modules\Core\Users;

use Illuminate\Mail\Mailable;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * The activation e-mail of InvitationNotification as a mailable, so it can go
 * through the organisme's own mailer (TenantMailer). Never queued: the plain
 * token must not be serialized into a queue payload.
 */
class InvitationMail extends Mailable
{
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly string $organisation,
    ) {}

    public function build(): self
    {
        $message = (new InvitationNotification($this->url, $this->name, $this->organisation))
            ->toMail(new AnonymousNotifiable);

        return $this->subject((string) $message->subject)->html((string) $message->render());
    }
}
