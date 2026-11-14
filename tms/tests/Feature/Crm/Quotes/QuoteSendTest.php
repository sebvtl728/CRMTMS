<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Quotes\QuoteMail;
use App\Modules\Crm\Quotes\QuoteMailFailed;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\SendQuote;
use App\Modules\Crm\Timeline\LastContact;
use App\Modules\Crm\Timeline\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    fakeQuoteGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/**
 * The organisme's SMTP connector, its transport replaced: $send receives
 * every message (and may throw).
 */
function quoteSmtp(Closure $send): ConnectorConfig
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
            return 'quote://';
        }
    };
    app()->instance(SmtpTransportFactory::class, new class($transport) extends SmtpTransportFactory
    {
        public function __construct(private readonly TransportInterface $fake) {}

        public function make(array $settings, array $secrets): TransportInterface
        {
            return $this->fake;
        }
    });

    return ConnectorConfig::create([
        'connector_key' => 'smtp',
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'devis@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ]);
}

it('sends a quote: frozen, archived, mailed with its pdf, in the timeline and as a contact', function () {
    Mail::fake();
    $user = commercial();
    $this->actingAs($user);
    $quote = sendableQuote('Étude');
    $contact = $quote->contact;
    $opportunity = $quote->opportunity;

    // The window is prefilled from the recipient, the number, the company and the validity.
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('send.to', 'lea.durand@example.test')
        ->where('send.subject', 'Devis CF-2026-0001 — CAP FORMATION')
        ->where('send.body', fn (string $body) => str_contains($body, 'CF-2026-0001') && str_contains($body, '14/12/2026') && str_contains($body, 'CAP FORMATION'))
        ->where('can.send', true)
        ->where('can.remind', false));

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['cc' => ['direction@cap.test'], 'subject' => 'Votre devis CF-2026-0001']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('crm.quotes.show', $quote))
        ->assertSessionHas('status', 'Devis CF-2026-0001 envoyé.');

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->sent_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($quote->recipient_snapshot['email'])->toBe('lea.durand@example.test')
        ->and($quote->pdf_path)->toBe(TenantStorage::path('quotes', $quote->id.'/CF-2026-0001.pdf'))
        ->and($quote->email_status)->toBe('sent')
        ->and($quote->email_sent_at)->not->toBeNull();

    $archived = Storage::disk(TenantStorage::DISK)->get($quote->pdf_path);
    $mail = Mail::sent(QuoteMail::class)->sole();
    expect($mail->hasTo('lea.durand@example.test'))->toBeTrue()
        ->and($mail->hasCc('direction@cap.test'))->toBeTrue();
    $mail->assertHasSubject('Votre devis CF-2026-0001');
    $mail->assertSeeInText('Voici notre devis.');
    $mail->assertHasAttachedData($archived, 'CF-2026-0001.pdf', ['mime' => 'application/pdf']);

    expect(Timeline::for($quote->organisation)->where('type', 'quote')->pluck('title')->all())
        ->toBe(['Devis CF-2026-0001 envoyé', 'Devis CF-2026-0001 créé']);

    // A contact of the entreprise and of the interlocutrice.
    expect(DB::table('organisations')->where('id', $quote->organisation_id)->value('last_contact_at'))->not->toBeNull()
        ->and(DB::table('people')->where('id', $contact->id)->value('last_contact_at'))->not->toBeNull();
    // Recomputed (e.g. after a note was removed): the sending still counts.
    DB::table('organisations')->update(['last_contact_at' => null]);
    DB::table('people')->update(['last_contact_at' => null]);
    LastContact::recompute('organisation', $quote->organisation_id);
    LastContact::recompute('person', $contact->id);
    expect(CarbonImmutable::parse(DB::table('organisations')->where('id', $quote->organisation_id)->value('last_contact_at'))->equalTo($quote->sent_at))->toBeTrue()
        ->and(DB::table('people')->where('id', $contact->id)->value('last_contact_at'))->not->toBeNull();

    // « Étude » → « Proposition » (the stage flagged meta.quote_stage), in the history.
    expect($opportunity->refresh()->stage_id)->toBe(pipelineStage('Proposition')->id)
        ->and(StageChange::query()->where('opportunity_id', $opportunity->id)->orderByDesc('id')->first()->to_stage_id)->toBe(pipelineStage('Proposition')->id);

    // Sent once only.
    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertStatus(409)->assertSee('Seul un brouillon s&#039;envoie', false);
});

it('leaves an opportunity already past the quote stage, closed, or without flagged stage where it is', function (string $stage) {
    Mail::fake();
    $this->actingAs(commercial());
    $quote = sendableQuote($stage);

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    expect($quote->opportunity->refresh()->stage_id)->toBe(pipelineStage($stage)->id);
})->with(['Négociation', 'Proposition', 'Gagné']);

it('does not move the opportunity when no stage is flagged, or the flagged one is inactive', function (bool $inactive) {
    Mail::fake();
    $this->actingAs(commercial());
    $proposition = pipelineStage('Proposition');
    $inactive ? $proposition->update(['is_active' => false]) : $proposition->update(['meta' => ['probability' => 60, 'outcome' => null]]);
    $quote = sendableQuote('Nouveau');

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    expect($quote->opportunity->refresh()->stage_id)->toBe(pipelineStage('Nouveau')->id)
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent);
})->with(['not flagged' => false, 'inactive' => true]);

it('refuses a recipient without e-mail and wrong addresses, in French', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $quote = sendableQuote('Nouveau', email: null);

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('send.to', ''));

    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['to' => '']))
        ->assertStatus(422)->assertJsonValidationErrors(['to' => 'Le destinataire n\'a pas d\'adresse e-mail.']);
    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['to' => 'pas-une-adresse', 'cc' => ['a@b.test', 'nope']]))
        ->assertStatus(422)->assertJsonValidationErrors(['to', 'cc.1']);
    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['cc' => array_map(fn ($i) => "c{$i}@cap.test", range(1, 6))]))
        ->assertStatus(422)->assertJsonValidationErrors(['cc' => 'Cinq adresses en copie au plus.']);
    $this->postJson("/crm/quotes/{$quote->id}/send", quoteMessage(['subject' => '', 'body' => '']))
        ->assertStatus(422)->assertJsonValidationErrors(['subject', 'body']);

    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft)
        ->and($quote->pdf_path)->toBeNull();
    Mail::assertNothingSent();
});

it('sends the message as plain text, never as html', function () {
    $sent = [];
    quoteSmtp(function (RawMessage $m) use (&$sent) {
        $sent[] = $m;
    });
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage(['body' => '<b>Bonjour</b> <script>alert(1)</script>']))->assertSessionHasNoErrors();

    expect($sent)->toHaveCount(1);
    $email = $sent[0];
    expect($email->getHtmlBody())->toBeNull()
        ->and($email->getTextBody())->toContain('<b>Bonjour</b> <script>alert(1)</script>')
        ->and($email->getFrom()[0]->getAddress())->toBe('devis@cap.test')
        ->and($email->getAttachments())->toHaveCount(1)
        ->and($email->getAttachments()[0]->getFilename())->toBe('CF-2026-0001.pdf');

    $run = ConnectorRun::query()->sole();
    expect($run->status)->toBe('success')
        ->and($run->summary)->toBe('Devis CF-2026-0001 envoyé par e-mail.')
        ->and($run->payload_ref)->toBe('quote:'.$quote->id);
});

it('keeps the quote sent when the smtp server fails, shows the banner and sends again', function () {
    $fail = true;
    $sent = [];
    $config = quoteSmtp(function (RawMessage $m) use (&$fail, &$sent) {
        if ($fail) {
            throw new TransportException('550 5.1.1 <lea.durand@example.test>: Recipient address rejected for user cap-user');
        }
        $sent[] = $m;
    });
    $this->actingAs(commercial());
    $quote = sendableQuote();

    Queue::fake();
    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();
    $job = null;
    Queue::assertPushed(SendQuote::class, function (SendQuote $j) use (&$job) {
        $job = $j;

        return true;
    });
    expect($quote->refresh()->email_status)->toBe('pending');

    // Three tries with a backoff, then failed.
    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([60, 300, 900]);
    // Rethrown without the server's text (logged by the worker, kept in failed_jobs).
    $thrown = null;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(QuoteMailFailed::class)
        ->and($thrown->getMessage())->toBe('Le serveur d\'envoi a refusé le message (code 550).')
        ->and($thrown->getCode())->toBe(550)
        ->and((string) $thrown)->not->toContain('lea.durand')->not->toContain('cap-user')
        ->and($thrown->getPrevious())->toBeNull();
    expect($quote->refresh()->email_status)->toBe('pending'); // retried until the last try
    $job->failed($thrown);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->email_status)->toBe('failed')
        ->and($quote->email_error)->toBe('Le serveur d\'envoi a refusé le message (code 550).');

    $run = ConnectorRun::query()->where('connector_config_id', $config->id)->sole();
    expect($run->status)->toBe('failed')
        ->and($run->error)->toBe('Le serveur d\'envoi a refusé le message (code 550).')
        ->and(json_encode($run->toArray()))->not->toContain('lea.durand')->not->toContain('cap-user');

    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page
        ->where('quote.email.status', 'failed')
        ->where('quote.email.error', 'Le serveur d\'envoi a refusé le message (code 550).')
        ->where('can.resend', true));

    // « Renvoyer »: the same message, queued again.
    $fail = false;
    $this->post("/crm/quotes/{$quote->id}/resend")->assertRedirect()->assertSessionHas('status', 'Nouvel essai d\'envoi du devis CF-2026-0001 en cours.');
    expect($quote->refresh()->email_status)->toBe('pending');
    Queue::assertPushed(SendQuote::class, 2);
    app()->call([$job, 'handle']);

    expect($sent)->toHaveCount(1)
        ->and($quote->refresh()->email_status)->toBe('sent')
        ->and($quote->email_error)->toBeNull();

    // Nothing to send again any more.
    $this->post("/crm/quotes/{$quote->id}/resend")->assertStatus(409);
});

it('queues the sending after commit, carrying the tenant', function () {
    Queue::fake();
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

    Queue::assertPushed(SendQuote::class, fn (SendQuote $job) => $job->tenantId === tenant()->id
        && $job->quoteId === $quote->id && $job->afterCommit === true);
});

it('warns before sending when no mail delivery is configured', function () {
    $this->actingAs(commercial());
    $quote = sendableQuote();

    config(['mail.default' => 'log']);
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('send.delivers_nowhere', true));

    config(['mail.default' => 'smtp']);
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('send.delivers_nowhere', false));
});

it('refuses to send without the right, or on a trashed fiche', function () {
    Mail::fake();
    $quote = sendableQuote();

    $this->actingAs(direction(twoFactor: true))->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertForbidden();

    $quote->organisation->delete();
    $this->actingAs(assistant())->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertNotFound();
    $this->actingAs(commercial())->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertForbidden();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Draft);
    Mail::assertNothingSent();
});

it('does not send when the pdf cannot be rendered', function () {
    Mail::fake();
    Http::swap(new Factory);
    Http::fake(['gotenberg:3000/*' => Http::response('boom', 500)]);
    $this->actingAs(commercial());
    $quote = sendableQuote('Étude');

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertStatus(503);

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Draft)
        ->and($quote->pdf_path)->toBeNull()
        ->and($quote->opportunity->refresh()->stage_id)->toBe(pipelineStage('Étude')->id);
    Mail::assertNothingSent();
    expect(ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->count())->toBe(7);
});

it('on a sync queue, a failed e-mail shows the banner, never the server text', function () {
    quoteSmtp(fn () => throw new TransportException('550 5.1.1 <lea.durand@example.test>: rejected for user cap-user'));
    $logged = [];
    Event::listen(MessageLogged::class, function ($e) use (&$logged) {
        $logged[] = $e->message.json_encode($e->context);
    });
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors()->assertRedirect(route('crm.quotes.show', $quote));

    $quote->refresh();
    expect($quote->status)->toBe(QuoteStatus::Sent)
        ->and($quote->email_status)->toBe('failed')
        ->and($quote->email_error)->toBe('Le serveur d\'envoi a refusé le message (code 550).');
    expect(implode("\n", $logged))->not->toContain('lea.durand')->not->toContain('cap-user');
    expect(DB::table('failed_jobs')->count())->toBe(0);
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('quote.email.status', 'failed')->where('can.resend', true)->where('can.remind', false));
});

it('a replayed sending job sends nothing again', function () {
    $sent = [];
    quoteSmtp(function (RawMessage $m) use (&$sent) {
        $sent[] = $m;
    });
    $this->actingAs(commercial());
    $quote = sendableQuote();

    $this->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();
    expect($sent)->toHaveCount(1)->and($quote->refresh()->email_status)->toBe('sent');

    app()->call([new SendQuote($quote->id), 'handle']);
    expect($sent)->toHaveCount(1)
        ->and(ConnectorRun::query()->count())->toBe(1);
});
