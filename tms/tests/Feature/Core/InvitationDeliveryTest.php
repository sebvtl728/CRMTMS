<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\InvitationMail;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Replaces the SMTP transport factory: $send receives every message.
 */
function invitationTransport(Closure $send, ?Closure $make = null): void
{
    $transport = new class($send) implements TransportInterface
    {
        public function __construct(private readonly Closure $send) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            ($this->send)($message);

            return null;
        }

        public function __toString(): string
        {
            return 'invitation://';
        }
    };

    app()->instance(SmtpTransportFactory::class, new class($transport, $make) extends SmtpTransportFactory
    {
        public function __construct(private readonly TransportInterface $fake, private readonly ?Closure $make) {}

        public function make(array $settings, array $secrets): TransportInterface
        {
            if ($this->make !== null) {
                ($this->make)();
            }

            return $this->fake;
        }
    });
}

function organismeSmtp(): ConnectorConfig
{
    tenant();

    return ConnectorConfig::create([
        'connector_key' => 'smtp',
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'no-reply@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ]);
}

function invite(string $email = 'nouveau@cap.test')
{
    return test()->actingAs(admin(twoFactor: true))
        ->post('/admin/users/invitations', ['email' => $email, 'name' => 'Nouveau Venu', 'role' => 'commercial']);
}

it('sends the invitation through the organisme SMTP connector', function () {
    $sent = [];
    invitationTransport(function (RawMessage $m) use (&$sent) {
        $sent[] = $m;
    });
    organismeSmtp();

    invite()->assertRedirect('/admin/users')
        ->assertSessionHas('status', 'Invitation envoyée à nouveau@cap.test.')
        ->assertSessionMissing('activation_link');

    expect($sent)->toHaveCount(1);
    /** @var Email $email */
    $email = $sent[0];
    expect($email->getTo()[0]->getAddress())->toBe('nouveau@cap.test')
        ->and($email->getFrom()[0]->getAddress())->toBe('no-reply@cap.test')
        ->and($email->getHtmlBody())->toContain('/activation/');
    expect(AuditLog::query()->where('event', 'users.invitation_sent')->count())->toBe(1);
});

it('falls back to the instance mailer when the organisme has no SMTP connector', function () {
    config(['mail.default' => 'smtp']);
    Mail::fake();

    invite()->assertRedirect('/admin/users')->assertSessionHas('status');

    $invitation = Invitation::sole();
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('nouveau@cap.test')
        && hash('sha256', basename($mail->url)) === $invitation->token_hash);
});

it('keeps the invitation and shows the link when the SMTP server fails', function () {
    invitationTransport(fn () => throw new TransportException('535 Authentication failed for user "cap-user" raw-server-text'));
    organismeSmtp();

    invite()->assertRedirect('/admin/users')
        ->assertSessionMissing('status')
        ->assertSessionHas('warning', fn (string $w) => str_contains($w, 'n\'a pas pu être envoyé'))
        ->assertSessionHas('activation_link', fn (string $link) => str_contains($link, '/activation/'));

    $invitation = Invitation::sole();
    expect(hash('sha256', basename(session('activation_link'))))->toBe($invitation->token_hash);

    $log = AuditLog::query()->where('event', 'users.invitation_send_failed')->sole();
    expect($log->new_values['email'])->toBe('nouveau@cap.test')
        ->and($log->new_values['reason'])->toBeString()
        ->and(json_encode($log->new_values))->not->toContain('raw-server-text')->not->toContain('cap-user');
    expect(AuditLog::query()->where('event', 'users.invitation_sent')->count())->toBe(0);
});

it('keeps the invitation when the SMTP host is refused', function () {
    invitationTransport(fn () => null, fn () => throw new SmtpHostRefused('Le serveur « smtp.example.test » pointe vers une adresse interne : envoi refusé.'));
    organismeSmtp();

    invite()->assertRedirect('/admin/users')->assertSessionHas('activation_link');

    expect(Invitation::count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'users.invitation_send_failed')->sole()->new_values['reason'])->toContain('envoi refusé');
});

it('shows the link with a warning when no mail delivery is configured', function (string $mailer) {
    config(['mail.default' => $mailer]);
    Mail::fake();

    invite()->assertRedirect('/admin/users')
        ->assertSessionHas('warning', fn (string $w) => str_contains($w, 'aucun e-mail n\'a été envoyé'))
        ->assertSessionHas('activation_link');

    Mail::assertNothingSent();
    expect(Invitation::count())->toBe(1)
        ->and(AuditLog::query()->where('event', 'users.invitation_send_failed')->count())->toBe(1);
})->with(['log', 'array']);

it('passes the warning and the link to the users page', function () {
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)
        ->withSession(['warning' => 'Attention', 'activation_link' => 'https://tms.test/activation/abc'])
        ->get('/admin/users')
        ->assertInertia(fn (Assert $page) => $page->component('admin/users/index')
            ->where('warning', 'Attention')
            ->where('activationLink', 'https://tms.test/activation/abc'));
});
