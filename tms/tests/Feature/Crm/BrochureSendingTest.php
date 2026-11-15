<?php

use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochureVersion;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Brochures\BrochureAttachments;
use App\Modules\Crm\Brochures\BrochureMail;
use App\Modules\Crm\Brochures\BrochureSending;
use App\Modules\Crm\Brochures\SendBrochure;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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
});

/**
 * A brochure whose current version is written on the private disk.
 */
function sendingBrochure(string $title, string $content = "%PDF-1.4 plaquette\n", ?int $size = null): BrochureVersion
{
    $brochure = new Brochure;
    $brochure->forceFill(['title' => $title])->save();

    return sendingVersion($brochure, $content, $size);
}

function sendingVersion(Brochure $brochure, string $content, ?int $size = null): BrochureVersion
{
    $path = TenantStorage::path('brochures', $brochure->id.'/'.Str::uuid()->toString().'.pdf');
    Storage::disk(TenantStorage::DISK)->put($path, $content);
    $version = $brochure->versions()->create([
        'path' => $path, 'original_name' => Str::slug($brochure->title).'.pdf', 'size' => $size ?? strlen($content), 'sha256' => hash('sha256', $content),
    ]);
    $brochure->forceFill(['current_version_id' => $version->id])->save();

    return $version;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sendingPayload(BrochureVersion|array $versions, string $recipientId, array $overrides = []): array
{
    return [
        'versions' => array_map(fn (BrochureVersion $v) => $v->id, is_array($versions) ? $versions : [$versions]),
        'recipient_person_id' => $recipientId,
        'subject' => 'Nos formations',
        'message' => "Bonjour,\n\nVoici nos plaquettes.",
        ...$overrides,
    ];
}

/** The sending entry of a timeline response. */
function brochureEntry(TestResponse $response): array
{
    return collect($response->assertOk()->json('entries'))->firstWhere('type', 'brochure_sending') ?? [];
}

/** An organisme SMTP connector whose transport is replaced: $send gets each message (and may throw). */
function brochureSmtp(Closure $send): void
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
            return 'brochure://';
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

    ConnectorConfig::create([
        'connector_key' => 'smtp',
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'contact@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ]);
}

it('sends brochures from a person fiche: mailed with the files, recorded, shown in the history', function () {
    Mail::fake();
    $user = commercial();
    $this->actingAs($user);
    $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@example.test', 'is_individual' => true]);
    $haccp = sendingBrochure('HACCP', "%PDF-1.4 haccp\n");
    $secu = sendingBrochure('Sécurité incendie', "%PDF-1.4 secu\n");

    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload([$haccp, $secu], $person->id))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('crm.people.show', $person));

    $mail = Mail::sent(BrochureMail::class)->sole();
    expect($mail->hasTo('claire.martin@example.test'))->toBeTrue();
    $mail->assertHasAttachedData("%PDF-1.4 haccp\n", 'haccp.pdf', ['mime' => 'application/pdf']);
    $mail->assertHasAttachedData("%PDF-1.4 secu\n", 'securite-incendie.pdf', ['mime' => 'application/pdf']);

    $sending = BrochureSending::query()->sole();
    expect($sending->email_status)->toBe('sent')
        ->and($sending->sent_at)->not->toBeNull()
        ->and($sending->person_id)->toBe($person->id)
        ->and($sending->organisation_id)->toBeNull()
        ->and($sending->recipient_email)->toBe('claire.martin@example.test')
        ->and($sending->created_by)->toBe($user->id)
        ->and($sending->versionIds())->toBe([$haccp->id, $secu->id]);

    $entry = brochureEntry($this->getJson("/crm/people/{$person->id}/timeline"));
    expect($entry['title'])->toBe('Plaquettes envoyées à Claire Martin')
        ->and($entry['body'])->toBe('HACCP, Sécurité incendie')
        ->and($entry['author'])->toBe($user->name)
        ->and($entry['meta']['status_label'])->toBe('Envoyée');
});

it('shows the recipients and the template text on the fiche pages, only with the right to change actions', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@example.test']);
    $noMail = makePerson('Sansmail', ['first_name' => 'Paul']);
    $former = makePerson('Ancien', ['first_name' => 'Jean', 'email' => 'jean@example.test']);
    affiliate($lea, $organisation);
    affiliate($noMail, $organisation);
    affiliate($former, $organisation, ['ended_on' => '2026-01-01']);

    $this->get("/crm/organisations/{$organisation->id}")->assertInertia(fn (Assert $page) => $page
        ->has('brochure_send.recipients', 2)
        ->where('brochure_send.recipients.0.name', 'Léa Durand')
        ->where('brochure_send.recipients.0.email', 'lea@example.test')
        ->where('brochure_send.template.subject', fn (string $s) => str_starts_with($s, 'Nos formations'))
        ->where('brochure_send.template.body', fn (string $b) => str_contains($b, 'Bonjour {destinataire}')));

    $this->actingAs(userWithPermissions(['crm.fiches.view']));
    $this->get("/crm/organisations/{$organisation->id}")->assertInertia(fn (Assert $page) => $page->where('brochure_send', null));
    $this->post("/crm/organisations/{$organisation->id}/brochures/send", sendingPayload(sendingBrochure('X'), $lea->id))->assertForbidden();
});

it('sends from an entreprise to an active interlocuteur with the work e-mail, never to someone else or without an address', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.perso@example.test']);
    affiliate($lea, $organisation, ['work_email' => 'lea@bistrot.test']);
    $noMail = makePerson('Sansmail');
    affiliate($noMail, $organisation);
    $former = makePerson('Ancien', ['email' => 'ancien@example.test']);
    affiliate($former, $organisation, ['ended_on' => '2026-01-01']);
    $stranger = makePerson('Etranger', ['email' => 'etranger@example.test']);
    $version = sendingBrochure('HACCP');
    $url = "/crm/organisations/{$organisation->id}/brochures/send";

    $this->post($url, sendingPayload($version, $noMail->id))->assertSessionHasErrors(['recipient_person_id' => 'Ce destinataire n\'a pas d\'adresse e-mail.']);
    $this->post($url, sendingPayload($version, $former->id))->assertSessionHasErrors('recipient_person_id');
    $this->post($url, sendingPayload($version, $stranger->id))->assertSessionHasErrors(['recipient_person_id' => 'Ce destinataire n\'est pas rattaché à cette fiche.']);
    expect(BrochureSending::query()->count())->toBe(0);

    $this->post($url, sendingPayload($version, $lea->id))->assertSessionHasNoErrors();
    expect(BrochureSending::query()->sole()->recipient_email)->toBe('lea@bistrot.test')
        ->and(BrochureSending::query()->sole()->organisation_id)->toBe($organisation->id);
    Mail::assertSent(BrochureMail::class, fn ($m) => $m->hasTo('lea@bistrot.test'));
});

it('sends from an opportunity to its contacts and records it on the account fiche', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@example.test']);
    $other = makePerson('Autre', ['email' => 'autre@example.test']);
    affiliate($lea, $organisation);
    affiliate($other, $organisation);
    $opportunity = makeOpportunity($organisation);
    DB::table('opportunity_contacts')->insert(['tenant_id' => tenant()->id, 'opportunity_id' => $opportunity->id, 'person_id' => $lea->id]);
    $version = sendingBrochure('HACCP');

    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $page) => $page
        ->has('brochure_send.recipients', 1)->where('brochure_send.recipients.0.id', $lea->id));

    $this->post("/crm/opportunities/{$opportunity->id}/brochures/send", sendingPayload($version, $other->id))->assertSessionHasErrors('recipient_person_id');
    $this->post("/crm/opportunities/{$opportunity->id}/brochures/send", sendingPayload($version, $lea->id))
        ->assertSessionHasNoErrors()->assertRedirect(route('crm.opportunities.show', $opportunity));

    $sending = BrochureSending::query()->sole();
    expect($sending->opportunity_id)->toBe($opportunity->id)->and($sending->organisation_id)->toBe($organisation->id);
    expect(brochureEntry($this->getJson("/crm/organisations/{$organisation->id}/timeline")))->not->toBeEmpty();
    $this->get("/crm/opportunities/{$opportunity->id}")->assertInertia(fn (Assert $page) => $page
        ->has('brochure_sendings', 1)->where('brochure_sendings.0.titles', ['HACCP'])->where('brochure_sendings.0.status_label', 'Envoyée')
        ->missing('brochure_sendings.0.recipient_email')->missing('brochure_sendings.0.message'));
});

it('lets a user limited to his DAS send only from a fiche he sees, with a brochure of any DAS', function () {
    Mail::fake();
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);
    $user->givePermissionTo('crm.activities.update');
    $this->actingAs($user);

    $visible = makePerson('Visible', ['email' => 'visible@example.test', 'is_individual' => true]);
    DB::table('person_das')->insert(['tenant_id' => tenant()->id, 'person_id' => $visible->id, 'das_id' => $mine->id]);
    $hidden = makePerson('Cachee', ['email' => 'cachee@example.test', 'is_individual' => true]);
    DB::table('person_das')->insert(['tenant_id' => tenant()->id, 'person_id' => $hidden->id, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    // A brochure of another DAS: the catalogue is shared.
    $version = sendingBrochure('Plaquette Bâtiment');
    DB::table('brochure_das')->insert(['tenant_id' => tenant()->id, 'brochure_id' => $version->brochure_id, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);

    $this->post("/crm/people/{$hidden->id}/brochures/send", sendingPayload($version, $hidden->id))->assertNotFound();
    expect(BrochureSending::query()->count())->toBe(0);

    $this->post("/crm/people/{$visible->id}/brochures/send", sendingPayload($version, $visible->id))->assertSessionHasNoErrors();
    expect(BrochureSending::query()->sole()->person_id)->toBe($visible->id);
    // The recipient of an entreprise must be visible to him too.
    $outside = Organisation::query()->create(['name' => 'Hors DAS']);
    $this->post("/crm/organisations/{$outside->id}/brochures/send", sendingPayload($version, $visible->id))->assertNotFound();
});

it('refuses a trashed fiche and a trashed opportunity', function () {
    $this->actingAs(admin(twoFactor: true));
    $person = makePerson('Corbeille', ['email' => 'c@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');
    $person->delete();

    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertStatus(409);
    expect(BrochureSending::query()->count())->toBe(0);
});

it('offers only current versions of brochures that are not in the trash', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $old = sendingBrochure('HACCP', "%PDF-1.4 v1\n");
    $new = sendingVersion($old->brochure, "%PDF-1.4 v2\n");
    $trashed = sendingBrochure('Archivée');
    $trashed->brochure->delete();
    $url = "/crm/people/{$person->id}/brochures/send";

    $this->post($url, sendingPayload($old, $person->id))->assertSessionHasErrors(['versions.0' => 'Cette plaquette n\'existe plus.']);
    $this->post($url, sendingPayload($trashed, $person->id))->assertSessionHasErrors('versions.0');
    $this->post($url, [...sendingPayload([], $person->id)])->assertSessionHasErrors('versions');
    expect(BrochureSending::query()->count())->toBe(0);

    $this->post($url, sendingPayload($new, $person->id))->assertSessionHasNoErrors();
    expect(BrochureSending::query()->sole()->versionIds())->toBe([$new->id]);
    // A later replacement does not change what was sent.
    $newer = sendingVersion($old->brochure, "%PDF-1.4 v3\n");
    expect(BrochureSending::query()->sole()->versionIds())->toBe([$new->id])->and($newer->id)->not->toBe($new->id);
});

it('refuses attachments over 20 Mo with the field message and sends exactly 20 Mo', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $a = sendingBrochure('A', "%PDF-1.4 a\n", 10 * 1024 * 1024);
    $b = sendingBrochure('B', "%PDF-1.4 b\n", 10 * 1024 * 1024 + 1);
    $c = sendingBrochure('C', "%PDF-1.4 c\n", 10 * 1024 * 1024);
    $url = "/crm/people/{$person->id}/brochures/send";

    $this->post($url, sendingPayload([$a, $b], $person->id))->assertSessionHasErrors(['versions' => 'Les pièces jointes dépassent 20 Mo : retirez une plaquette.']);
    expect(BrochureSending::query()->count())->toBe(0);
    $this->post($url, sendingPayload([$a, $c], $person->id))->assertSessionHasNoErrors();
    expect(BrochureSending::query()->count())->toBe(1)->and(BrochureAttachments::TOO_BIG)->toBe('Les pièces jointes dépassent 20 Mo : retirez une plaquette.');
});

it('validates the subject, the message and the shape of the request', function () {
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');
    $url = "/crm/people/{$person->id}/brochures/send";

    $this->post($url, sendingPayload($version, $person->id, ['subject' => '', 'message' => '']))->assertSessionHasErrors(['subject' => 'L\'objet est obligatoire.', 'message' => 'Le message est obligatoire.']);
    $this->post($url, sendingPayload($version, 'pas-un-uuid'))->assertSessionHasErrors('recipient_person_id');
    $this->post($url, [...sendingPayload($version, $person->id), 'versions' => [$version->id, $version->id]])->assertSessionHasErrors('versions.1');
    expect(BrochureSending::query()->count())->toBe(0);
});

it('rate limits sending to 20 a minute per user', function () {
    Queue::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');

    foreach (range(1, 20) as $_) {
        $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertSessionHasNoErrors();
    }
    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertStatus(429);
});

it('shows a failed delivery as « Échec » without the server text', function () {
    brochureSmtp(function () {
        throw new TransportException('550 5.1.1 <claire.martin@example.test>: Recipient address rejected for user cap-user');
    });
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');

    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertSessionHasNoErrors();

    $sending = BrochureSending::query()->sole();
    expect($sending->email_status)->toBe('failed')
        ->and($sending->email_error)->toBe('Le serveur d\'envoi a refusé le message (code 550).');
    $response = $this->getJson("/crm/people/{$person->id}/timeline");
    expect(brochureEntry($response)['meta']['status_label'])->toBe('Échec')->and($response->getContent())->not->toContain('Recipient address rejected')->not->toContain('cap-user')->not->toContain('s3cret');
    $page = $this->get("/crm/people/{$person->id}")->assertOk();
    expect($page->getContent())->not->toContain('Recipient address rejected');
});

it('stays pending until the job runs, then fails for a file missing from the disk', function () {
    Queue::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');
    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertSessionHasNoErrors();
    Queue::assertPushed(SendBrochure::class, 1);
    expect(BrochureSending::query()->sole()->email_status)->toBe('pending');
    expect(brochureEntry($this->getJson("/crm/people/{$person->id}/timeline"))['meta']['status_label'])->toBe('En attente');

    Storage::disk(TenantStorage::DISK)->delete($version->path);
    (new SendBrochure(BrochureSending::query()->sole()->id))->handle(app(Brochures::class));

    expect(BrochureSending::query()->sole()->email_status)->toBe('failed')
        ->and(BrochureSending::query()->sole()->email_error)->toBe(BrochureAttachments::MISSING);
});

it('proposes the customised « brochure » template', function () {
    $user = admin(twoFactor: true);
    $this->actingAs($user);
    app(MailTemplates::class)->save('brochure', 'Documentation {organisme}', 'Chère cliente {destinataire}, de la part de {commercial}.');
    $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'm@example.test', 'is_individual' => true]);

    $this->get("/crm/people/{$person->id}")->assertInertia(fn (Assert $page) => $page
        ->where('brochure_send.template.subject', 'Documentation '.tenant()->name)
        ->where('brochure_send.template.body', 'Chère cliente {destinataire}, de la part de '.$user->name.'.'));
});

it('keeps the address, subject and message out of the journal and clears them when the person is anonymised', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@example.test', 'is_individual' => true]);
    $version = sendingBrochure('HACCP');
    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id, ['message' => 'Bonjour Claire, un secret perso']))->assertSessionHasNoErrors();
    $sending = BrochureSending::query()->sole();

    $journal = json_encode(AuditLog::query()->where('auditable_id', $sending->id)->get()->toArray());
    expect($journal)->not->toContain('claire.martin@example.test')->not->toContain('un secret perso')->not->toContain('Nos formations');

    app(Anonymiser::class)->anonymise($person->refresh(), 'manual');

    $sending->refresh();
    expect($sending->recipient_email)->toBeNull()->and($sending->subject)->toBeNull()->and($sending->message)->toBeNull()
        ->and($sending->email_status)->toBe('sent')->and($sending->versionIds())->toBe([$version->id]);
    expect(brochureEntry($this->getJson("/crm/people/{$person->id}/timeline"))['title'])->toBe('Plaquettes envoyées à Anonyme');
});

it('follows a merged fiche', function () {
    Mail::fake();
    $this->actingAs(admin(twoFactor: true));
    $keep = Organisation::query()->create(['name' => 'Le Bistrot']);
    $drop = Organisation::query()->create(['name' => 'Bistrot SARL']);
    $lea = makePerson('Durand', ['email' => 'lea@example.test']);
    affiliate($lea, $drop);
    $this->post("/crm/organisations/{$drop->id}/brochures/send", sendingPayload(sendingBrochure('HACCP'), $lea->id))->assertSessionHasNoErrors();

    app(Merger::class)->organisations($keep, $drop, []);

    expect(BrochureSending::query()->sole()->organisation_id)->toBe($keep->id);
});

it('shows a sending on the recipient\'s own fiche, and the sent versions as downloadable', function () {
    Mail::fake();
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@example.test']);
    affiliate($lea, $organisation);
    $version = sendingBrochure('HACCP');
    $this->post("/crm/organisations/{$organisation->id}/brochures/send", sendingPayload($version, $lea->id))->assertSessionHasNoErrors();

    $entry = brochureEntry($this->getJson("/crm/people/{$lea->id}/timeline"));
    expect($entry['title'])->toBe('Plaquettes envoyées à Léa Durand')
        ->and($entry['meta']['versions'])->toBe([[
            'brochure_id' => $version->brochure_id, 'version_id' => $version->id, 'title' => 'HACCP', 'downloadable' => true,
        ]]);

    // Without catalogue.view: listed, not downloadable.
    $this->actingAs(userWithPermissions(['crm.fiches.view']));
    expect(brochureEntry($this->getJson("/crm/people/{$lea->id}/timeline"))['meta']['versions'][0]['downloadable'])->toBeFalse();
});

it('hides from the history the sendings of opportunities the viewer does not see or that are trashed', function () {
    Mail::fake();
    $this->actingAs(admin(twoFactor: true));
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $lea = makePerson('Durand', ['email' => 'lea@example.test']);
    affiliate($lea, $organisation);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = makeOpportunity($organisation, ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    $own = makeOpportunity($organisation, ['das_id' => $mine->id, 'title' => 'Mienne']);
    $gone = makeOpportunity($organisation, ['das_id' => $mine->id, 'title' => 'Supprimée']);
    DB::table('organisation_das')->insert(['tenant_id' => tenant()->id, 'organisation_id' => $organisation->id, 'das_id' => $mine->id]);
    $version = sendingBrochure('HACCP');
    foreach ([$other, $own, $gone] as $opportunity) {
        DB::table('opportunity_contacts')->insert(['tenant_id' => tenant()->id, 'opportunity_id' => $opportunity->id, 'person_id' => $lea->id]);
        $this->post("/crm/opportunities/{$opportunity->id}/brochures/send", sendingPayload($version, $lea->id))->assertSessionHasNoErrors();
    }
    $this->post("/crm/organisations/{$organisation->id}/brochures/send", sendingPayload($version, $lea->id))->assertSessionHasNoErrors();
    $gone->delete();

    $count = fn () => collect($this->getJson("/crm/organisations/{$organisation->id}/timeline")->json('entries'))->where('type', 'brochure_sending')->count();
    expect($count())->toBe(3); // the fiche's own, the visible opportunity's, the other DAS (admin sees all); trashed one hidden

    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $mine);
    $this->actingAs($user);
    expect($count())->toBe(2); // the fiche's own and the one of his DAS
});

it('fails a pending sending whose recipient was anonymised before the job ran', function () {
    Queue::fake();
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['email' => 'm@example.test', 'is_individual' => true]);
    $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload(sendingBrochure('HACCP'), $person->id))->assertSessionHasNoErrors();
    app(Anonymiser::class)->anonymise($person->refresh(), 'manual');

    (new SendBrochure(BrochureSending::query()->sole()->id))->handle(app(Brochures::class));

    expect(BrochureSending::query()->sole()->email_status)->toBe('failed')
        ->and(BrochureSending::query()->sole()->email_error)->toBe(SendBrochure::ANONYMISED);
});
