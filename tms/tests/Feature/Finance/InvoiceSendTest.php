<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceJournalEntry;
use App\Modules\Finance\Invoices\InvoiceMail;
use App\Modules\Finance\Invoices\InvoiceMailFailed;
use App\Modules\Finance\Invoices\InvoiceSender;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Invoices\SendInvoice;
use App\Modules\Finance\Permissions;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use Carbon\CarbonImmutable;
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

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 facture', 200, ['Content-Type' => 'application/pdf'])]);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/** A validated invoice of the contractor (F-2026-00001, 171,43 €), its e-mail frozen in the snapshot. */
function fsInvoice(): array
{
    $x = fiSeed();
    Company::query()->where('is_default', true)->update(['name' => 'CAP FORMATION']);
    Organisation::query()->where('name', 'Hôtel du Parc')->update(['email' => 'compta@hotel-du-parc.test']);
    $accountant = fiManager([Permissions::VALIDATE => null]);
    fiDrafts()->prepare($accountant, [$x['dossier']->id.'|contractor']);
    $draft = Invoice::query()->where('dossier_id', $x['dossier']->id)->where('status', InvoiceStatus::Draft)->sole();
    $invoice = app(InvoiceValidator::class)->validate($draft, $accountant)->refresh();

    return [$invoice, $x, $accountant];
}

function fsMessage(array $over = []): array
{
    return ['to' => 'compta@hotel-du-parc.test', 'subject' => 'Votre facture F-2026-00001', 'body' => 'Bonjour, voici la facture.', ...$over];
}

/** The organisme's SMTP connector, its transport replaced: $send receives every message (and may throw). */
function fsSmtp(Closure $send): ConnectorConfig
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
            return 'invoice://';
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
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'factures@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ]);
}

// ---------------------------------------------------------------- sending

it('proposes the e-mail of the frozen snapshot, the number, the amount and the due date', function () {
    [$invoice, , $accountant] = fsInvoice();

    $this->actingAs($accountant)->get("/finance/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.send', true)
        ->where('email.status', null)
        ->where('email.defaults.to', 'compta@hotel-du-parc.test')
        ->where('email.defaults.subject', 'Facture F-2026-00001 - CAP FORMATION')
        ->where('email.defaults.body', fn (string $body) => str_contains($body, 'notre facture F-2026-00001')
            && str_contains($body, "171,43\u{00A0}€ TTC") && str_contains($body, 'À régler avant le 17/12/2026.'))
        ->where('email.sendings', []));
});

it('queues the e-mail after commit, touching only what the immutability trigger allows', function () {
    [$invoice, , $accountant] = fsInvoice();
    $updatedBy = $invoice->updated_by;
    Queue::fake();

    $this->actingAs($accountant)->from("/finance/invoices/{$invoice->id}")
        ->post("/finance/invoices/{$invoice->id}/send", fsMessage(['to' => 'autre@hotel-du-parc.test']))
        ->assertSessionHasNoErrors()->assertRedirect("/finance/invoices/{$invoice->id}")->assertSessionHas('status');

    Queue::assertPushed(SendInvoice::class, 1);
    $invoice->refresh();
    $sending = InvoiceSending::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->email_status)->toBe('pending')
        ->and($invoice->updated_by)->toBe($updatedBy)
        ->and($sending->only(['email', 'status', 'sent_by']))->toBe(['email' => 'autre@hotel-du-parc.test', 'status' => 'pending', 'sent_by' => $accountant->id]);

    // While it is under way, another sending is refused.
    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertStatus(409);
    expect(InvoiceSending::query()->count())->toBe(1);
});

it('sends the archived PDF, marks the invoice sent and journals it without the address', function () {
    [$invoice, , $accountant] = fsInvoice();
    Mail::fake();
    $updatedBy = $invoice->updated_by;
    $archive = Storage::disk(TenantStorage::DISK)->get($invoice->pdf_path);

    $this->actingAs($accountant)->post("/finance/invoices/{$invoice->id}/send", fsMessage(['to' => 'autre@hotel-du-parc.test']))->assertSessionHasNoErrors();

    $mail = Mail::sent(InvoiceMail::class)->sole();
    expect($mail->hasTo('autre@hotel-du-parc.test'))->toBeTrue();
    $mail->assertHasSubject('Votre facture F-2026-00001');
    $mail->assertSeeInText('Bonjour, voici la facture.');
    $mail->assertHasAttachedData($archive, 'F-2026-00001.pdf', ['mime' => 'application/pdf']);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->email_status)->toBe('sent')
        ->and($invoice->email_error_code)->toBeNull()
        ->and($invoice->sent_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($invoice->updated_by)->toBe($updatedBy)
        ->and(InvoiceSending::query()->sole()->status)->toBe('sent');

    $events = AuditLog::query()->whereIn('event', ['invoice.email_queued', 'invoice.email_sent'])->get();
    expect($events->pluck('event')->sort()->values()->all())->toBe(['invoice.email_queued', 'invoice.email_sent'])
        ->and($events->pluck('user_id')->unique()->all())->toBe([$accountant->id])
        ->and(json_encode($events->toArray()))->not->toContain('autre@hotel-du-parc.test')->not->toContain('compta@');

    // The page shows the history; the chain is untouched by the sending.
    $this->get("/finance/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
        ->where('email.status', 'sent')
        ->where('email.sendings.0.email', 'autre@hotel-du-parc.test')
        ->where('email.sendings.0.status_label', 'Envoyé')
        ->where('email.sendings.0.by', $accountant->name)
        ->where('can.credit_note', true));
    expect(Integrity::verify())->toBe(['count' => 1, 'broken' => null]);

    // « Renvoyer » a sent invoice (to another address) is possible; sent_at keeps the first sending.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:05', 'Europe/Paris'));
    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertSessionHasNoErrors();
    expect(Mail::sent(InvoiceMail::class))->toHaveCount(2)
        ->and(InvoiceSending::query()->count())->toBe(2)
        ->and($invoice->refresh()->sent_at->setTimezone('Europe/Paris')->format('H:i'))->toBe('10:00'); // sent_at keeps the first sending
});

it('refuses a draft, a bad address and a missing archive, and checks the rights and the scope', function () {
    [$invoice, $x, $accountant] = fsInvoice();
    Queue::fake();
    $this->actingAs($accountant);

    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage(['to' => 'pas-une-adresse']))->assertSessionHasErrors(['to' => 'Cette adresse e-mail n\'est pas valide.']);
    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage(['to' => '']))->assertSessionHasErrors('to');
    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage(['subject' => "a\r\nBcc: x@y.test"]))->assertSessionHasNoErrors();
    expect(InvoiceSending::query()->sole()->subject)->toBe('a Bcc: x@y.test');
    InvoiceSending::query()->delete();
    Invoice::query()->whereKey($invoice->id)->toBase()->update(['email_status' => null]);

    // A draft is never sent.
    fiDrafts()->prepare($accountant, [$x['dossier']->id.'|funding:'.$x['funding']->id]);
    $draft = Invoice::query()->where('status', InvoiceStatus::Draft)->sole();
    $this->post("/finance/invoices/{$draft->id}/send", fsMessage())->assertStatus(409);
    expect(fn () => app(InvoiceSender::class)->send($accountant, $draft, fsMessage()))->toThrow(UserFacingHttpException::class, InvoiceSender::DRAFT);

    // The archive lost: nothing is queued.
    Storage::disk(TenantStorage::DISK)->delete($invoice->pdf_path);
    $this->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertStatus(409);
    expect(InvoiceSending::query()->count())->toBe(0);

    // Reading is not sending; another's dossier is out of reach.
    $reader = l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);
    $this->actingAs($reader)->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertForbidden();
    $this->get("/finance/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page->where('can.send', false)->where('email.defaults', null));
    $own = fiManager([Permissions::MANAGE => AccessScope::Own, Permissions::VIEW => AccessScope::Own, SessionsPermissions::DOSSIERS_VIEW => AccessScope::Own]);
    $this->actingAs($own)->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertNotFound();
    Queue::assertPushed(SendInvoice::class, 1); // the one of the injected subject: every other attempt queued nothing
});

it('sends nothing when the archive no longer matches its fingerprint', function () {
    [$invoice, , $accountant] = fsInvoice();
    Mail::fake();
    Queue::fake();
    $this->actingAs($accountant)->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertSessionHasNoErrors();
    Storage::disk(TenantStorage::DISK)->put($invoice->pdf_path, '%PDF-1.7 autre contenu');

    app()->call([new SendInvoice(InvoiceSending::query()->sole()->id), 'handle']);

    Mail::assertNothingSent();
    $invoice->refresh();
    $sending = InvoiceSending::query()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->email_status)->toBe('failed')
        ->and($invoice->email_error_code)->toBe('archive_altered')
        ->and($sending->status)->toBe('failed')
        ->and($sending->error)->toBe(SendInvoice::ALTERED_DOCUMENT);
});

it('keeps the invoice validated when the smtp server fails, never shows the server text, and sends again', function () {
    [$invoice, , $accountant] = fsInvoice();
    $fail = true;
    $sent = [];
    $config = fsSmtp(function (RawMessage $m) use (&$fail, &$sent) {
        if ($fail) {
            throw new TransportException('550 5.1.1 <compta@hotel-du-parc.test>: Recipient address rejected for user cap-user');
        }
        $sent[] = $m;
    });

    Queue::fake();
    $this->actingAs($accountant)->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertSessionHasNoErrors();
    $job = null;
    Queue::assertPushed(SendInvoice::class, function (SendInvoice $j) use (&$job) {
        $job = $j;

        return true;
    });

    // Three tries with a backoff, rethrown without the server's text, then failed.
    expect($job->tries)->toBe(3)->and($job->backoff())->toBe([60, 300, 900]);
    $thrown = null;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(InvoiceMailFailed::class)
        ->and($thrown->getMessage())->toBe('Le serveur d\'envoi a refusé le message (code 550).')
        ->and($thrown->getCode())->toBe(550)
        ->and((string) $thrown)->not->toContain('compta@')->not->toContain('cap-user')
        ->and($invoice->refresh()->email_status)->toBe('pending');
    $job->failed($thrown);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->email_status)->toBe('failed')
        ->and($invoice->email_error_code)->toBe('smtp_550')
        ->and(InvoiceSending::query()->sole()->error)->toBe('Le serveur d\'envoi a refusé le message (code 550).');
    $run = ConnectorRun::query()->where('connector_config_id', $config->id)->sole();
    expect($run->status)->toBe('failed')
        ->and($run->payload_ref)->toBe('invoice:'.$invoice->id)
        ->and(json_encode($run->toArray()))->not->toContain('compta@')->not->toContain('cap-user');
    $audit = AuditLog::query()->where('event', 'invoice.email_failed')->sole();
    expect(json_encode($audit->toArray()))->not->toContain('compta@')->not->toContain('cap-user');

    $this->get("/finance/invoices/{$invoice->id}")->assertInertia(fn (Assert $page) => $page
        ->where('email.status', 'failed')
        ->where('email.sendings.0.error', 'Le serveur d\'envoi a refusé le message (code 550).'));

    // « Renvoyer »: the same message again, once the server answers.
    $fail = false;
    Queue::fake();
    $this->post("/finance/invoices/{$invoice->id}/resend")->assertSessionHasNoErrors()->assertSessionHas('status');
    Queue::assertPushed(SendInvoice::class, function (SendInvoice $j) use (&$job) {
        $job = $j;

        return true;
    });
    app()->call([$job, 'handle']);

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->email_status)->toBe('sent')
        ->and($sent)->toHaveCount(1)
        ->and(InvoiceSending::query()->orderBy('created_at')->pluck('status')->sort()->values()->all())->toBe(['failed', 'sent']);

    // Nothing failed any more: nothing to resend.
    $this->post("/finance/invoices/{$invoice->id}/resend")->assertStatus(409);
});

it('on a sync queue, a failed e-mail shows the banner state at once', function () {
    [$invoice, , $accountant] = fsInvoice();
    fsSmtp(fn () => throw new TransportException('550 5.1.1 <compta@hotel-du-parc.test>: rejected for user cap-user'));

    $this->actingAs($accountant)->post("/finance/invoices/{$invoice->id}/send", fsMessage())->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::Validated)
        ->and($invoice->email_status)->toBe('failed')
        ->and(json_encode(InvoiceSending::query()->sole()->toArray()))->not->toContain('cap-user');
});

it('sends a credit note too, without a due date in the message', function () {
    [$invoice, , $accountant] = fsInvoice();
    Mail::fake();
    $this->actingAs($accountant)->post("/finance/invoices/{$invoice->id}/credit-note")->assertSessionHasNoErrors();
    $credit = Invoice::query()->where('kind', Invoice::CREDIT_NOTE)->sole();
    $this->post("/finance/invoices/{$credit->id}/validate")->assertSessionHasNoErrors();

    $this->get("/finance/invoices/{$credit->id}")->assertInertia(fn (Assert $page) => $page
        ->where('email.defaults.subject', 'Avoir AV-2026-00001 - CAP FORMATION')
        ->where('email.defaults.body', fn (string $b) => str_contains($b, 'notre avoir AV-2026-00001') && ! str_contains($b, 'À régler')));
    $this->post("/finance/invoices/{$credit->id}/send", fsMessage())->assertSessionHasNoErrors();

    expect(Mail::sent(InvoiceMail::class)->sole()->hasTo('compta@hotel-du-parc.test'))->toBeTrue()
        ->and($credit->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('registers the editable e-mail template « invoice »', function () {
    fsInvoice();
    $text = app(MailTemplates::class)->render('invoice', ['numero' => 'F-1', 'piece' => 'Facture', 'type' => 'facture', 'societe' => 'CAP', 'montant_ttc' => '10 €', 'reglement' => '']);

    expect($text['subject'])->toBe('Facture F-1 - CAP')
        ->and($text['body'])->toContain('notre facture F-1 d\'un montant de 10 € TTC.');
});

// ---------------------------------------------------------------- list: export and integrity

it('exports the list as a French CSV within the scope and the filters, audited', function () {
    [$invoice, $x, $accountant] = fsInvoice();
    fiDrafts()->prepare($accountant, [$x['dossier']->id.'|funding:'.$x['funding']->id]);
    $this->actingAs($accountant);

    $response = $this->get('/finance/invoices-export?status=validated')->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('factures-20261117-1000.csv')
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();
    $lines = explode("\r\n", trim(substr($csv, 3)));
    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe('Numéro;Type;Date;Échéance;Destinataire;Dossier;HT;TVA;TTC;État;Réglé;"Reste dû";"État de paiement"')
        ->and($lines[1])->toBe('F-2026-00001;Facture;17/11/2026;17/12/2026;"Hôtel du Parc";'.$x['dossier']->number.';171,43;0,00;171,43;Validée;0,00;171,43;"Non réglée"');

    // Without a filter the draft of the OPCO is listed too, without number.
    $all = explode("\r\n", trim(substr($this->get('/finance/invoices-export')->streamedContent(), 3)));
    expect($all)->toHaveCount(3);

    expect(AuditLog::query()->where('event', 'export.run')->latest('id')->first()->new_values)->toMatchArray(['export' => 'finance.invoices', 'rows' => 2]);

    // The right and the scope of the list.
    $this->actingAs(userWithPermissions([]))->get('/finance/invoices-export')->assertForbidden();
    $own = fiManager([Permissions::VIEW => AccessScope::Own, SessionsPermissions::DOSSIERS_VIEW => AccessScope::Own]);
    $none = explode("\r\n", trim(substr($this->actingAs($own)->get('/finance/invoices-export')->streamedContent(), 3)));
    expect($none)->toHaveCount(1);
});

it('neutralises a recipient that a spreadsheet would read as a formula', function () {
    [$invoice, , $accountant] = fsInvoice();
    Organisation::query()->where('name', 'Hôtel du Parc')->update(['name' => '=HYPERLINK("x")']);

    $csv = $this->actingAs($accountant)->get('/finance/invoices-export')->streamedContent();

    expect($csv)->not->toContain(';=HYPERLINK')->and($csv)->toContain("'=HYPERLINK");
});

it('verifies the integrity from the list, for who may validate, and names the first broken number', function () {
    [$invoice, $x, $accountant] = fsInvoice();

    $this->actingAs($accountant)->get('/finance/invoices')->assertInertia(fn (Assert $page) => $page->where('can.integrity', true)->where('can.export', true));
    $this->from('/finance/invoices')->post('/finance/invoices-integrity')
        ->assertRedirect('/finance/invoices')->assertSessionHas('status', 'Intégrité vérifiée : 1 pièce, chaîne intacte.');

    // A journal row forged by the application role breaks the chain at its number.
    $draft = fiDrafts()->manual($accountant, $x['dossier']->id, 'contractor');
    InvoiceJournalEntry::query()->create([
        'invoice_id' => $draft->id, 'position' => 2, 'number' => 'F-2026-00099', 'kind' => 'invoice', 'issued_on' => '2026-11-17',
        'total_ht_cents' => 100, 'total_vat_cents' => 0, 'total_ttc_cents' => 100,
        'hash' => str_repeat('a', 64), 'previous_hash' => $invoice->hash,
    ]);
    $this->from('/finance/invoices')->post('/finance/invoices-integrity')
        ->assertRedirect('/finance/invoices')
        ->assertSessionHasErrors(['integrity' => 'Intégrité rompue à la pièce F-2026-00099 : contactez l\'administrateur.']);

    // Preparing invoices is not enough.
    $manager = fiManager();
    $this->actingAs($manager)->post('/finance/invoices-integrity')->assertForbidden();
    $this->get('/finance/invoices')->assertInertia(fn (Assert $page) => $page->where('can.integrity', false));
});
