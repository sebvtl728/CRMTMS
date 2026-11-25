<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Mail\MailTemplate;
use App\Modules\Core\Mail\MailTemplates;
use App\Modules\Core\Mail\MailTemplateTestMail;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\InvitationMail;
use App\Modules\Crm\Activities\DailyDigestMail;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Quotes\QuoteMail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('lists the editable templates and the system ones, read only', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->get('/admin/mail-templates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/mail-templates/index')
        ->where('templates', fn ($t) => collect($t)->where('editable', true)->pluck('key')->sort()->values()->all() === ['activity_assigned', 'attendance_catch_up', 'attendance_catch_up_code', 'booklet_apprentice', 'booklet_code', 'booklet_tutor', 'brochure', 'crm.daily_digest', 'document_apprenticeship_convention', 'document_completion_certificate', 'document_contract', 'document_convention', 'document_convocation', 'document_image_rights', 'document_individual_contract', 'document_internal_rules', 'document_professionalisation_convention', 'document_realisation_certificate', 'invoice', 'invoice_reminder_1', 'invoice_reminder_2', 'invoice_reminder_3', 'positioning_test', 'quote.remind', 'quote.send', 'session_day_before_trainee', 'session_day_before_trainer', 'signature_code', 'signature_done', 'signature_reminder', 'signature_request', 'survey_client', 'survey_cold', 'survey_hot', 'users.invitation'])
        ->where('templates', fn ($t) => collect($t)->where('editable', false)->pluck('key')->contains('auth.password_reset')));

    $this->get('/admin/mail-templates/quote.send')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/mail-templates/edit')
        ->where('template.subject', 'Devis {numero_devis} - {societe}')
        ->where('template.customised', false)
        ->where('template.variables', fn ($v) => collect($v)->pluck('name')->contains('numero_devis')));

    $this->get('/admin/mail-templates/auth.password_reset')->assertOk()->assertInertia(fn (Assert $page) => $page->where('template.editable', false));
    $this->put('/admin/mail-templates/auth.password_reset', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
    $this->get('/admin/mail-templates/inconnu')->assertNotFound();

    $this->actingAs(commercial())->get('/admin/mail-templates')->assertForbidden();
});

it('saves a template, journaled, and the quote window is prefilled with it', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->put('/admin/mail-templates/quote.send', [
        'subject' => 'Votre devis {numero_devis}',
        'body' => "Bonjour {destinataire},\n\nVoici le devis {numero_devis} ({montant_ttc}), valable jusqu'au {date_validite}.\n\n{commercial}\n{societe}",
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Modèle enregistré.');

    expect(MailTemplate::query()->where('key', 'quote.send')->sole()->updated_by)->toBe(auth()->id())
        ->and(AuditLog::query()->where('auditable_type', 'mail_template')->where('event', 'created')->exists())->toBeTrue();

    $quote = sendableQuote();
    $defaults = QuoteMail::defaults($quote->refresh(), QuoteMail::KIND_SEND);
    expect($defaults['subject'])->toBe('Votre devis CF-2026-0001')
        ->and($defaults['body'])->toContain('Voici le devis CF-2026-0001 (1 200,00 €)')
        ->and($defaults['body'])->toContain('CAP FORMATION');

    // « Rétablir le modèle par défaut ».
    $this->delete('/admin/mail-templates/quote.send')->assertSessionHasNoErrors();
    expect(MailTemplate::query()->count())->toBe(0)
        ->and(QuoteMail::defaults($quote, QuoteMail::KIND_SEND)['subject'])->toBe('Devis CF-2026-0001 - CAP FORMATION');
});

it('refuses unknown or malformed variables, and too long texts', function () {
    $this->actingAs(admin(twoFactor: true));
    $url = '/admin/mail-templates/quote.send';

    $this->put($url, ['subject' => 'Devis {numero}', 'body' => 'x'])
        ->assertSessionHasErrors(['subject' => 'Variable inconnue : {numero}.']);
    $this->put($url, ['subject' => 'Devis', 'body' => 'Bonjour {destinataire'])
        ->assertSessionHasErrors(['body' => 'Accolade mal formée : écrivez une variable sous la forme {nom}.']);
    $this->put($url, ['subject' => 'Devis', 'body' => '{{ phpinfo() }}'])->assertSessionHasErrors('body');
    $this->put($url, ['subject' => str_repeat('a', 201), 'body' => 'x'])->assertSessionHasErrors('subject');
    $this->put($url, ['subject' => 'a', 'body' => str_repeat('a', 10001)])->assertSessionHasErrors('body');
    $this->put('/admin/mail-templates/crm.daily_digest', ['subject' => 'Récap', 'body' => 'Bonjour'])
        ->assertSessionHasErrors(['body' => 'Gardez la variable {actions} : la liste des actions y est insérée.']);

    expect(MailTemplate::query()->count())->toBe(0);
});

it('renders values as plain text, never as code, and escapes them in html mails', function () {
    inTenant();
    MailTemplate::query()->create(['key' => 'crm.daily_digest', 'subject' => 'Récap : {nombre_actions}', 'body' => "Salut {prenom_utilisateur} !\n\n{actions}\n\nÀ demain,\nL'équipe"]);
    app(MailTemplates::class)->forget('crm.daily_digest');

    $mail = new DailyDigestMail('<b>Léa</b> Durand', collect(), collect());
    $html = $mail->render();

    expect($mail->subject)->toBe('Récap : 0 action')
        ->and($html)->toContain('Salut &lt;b&gt;Léa&lt;/b&gt; !')
        ->and($html)->toContain('À demain,')
        ->and($html)->not->toContain('<b>Léa</b>');
});

it('keeps the activation link of an invitation, whatever the text', function () {
    inTenant();
    MailTemplate::query()->create(['key' => 'users.invitation', 'subject' => 'Bienvenue chez {organisme}', 'body' => "Bonjour {prenom_utilisateur},\n\nRejoignez-nous."]);
    app(MailTemplates::class)->forget('users.invitation');

    $mail = (new InvitationMail('https://cap.example.test/activation/abc', 'Léa Durand', 'CAP FORMATION'))->build();
    $html = $mail->render();

    expect($mail->subject)->toBe('Bienvenue chez CAP FORMATION')
        ->and($html)->toContain('Bonjour Léa,')
        ->and($html)->toContain('Rejoignez-nous.')
        ->and($html)->toContain('https://cap.example.test/activation/abc')
        ->and($html)->toContain('heures');
});

it('previews with sample data and sends a test e-mail to the administrator', function () {
    Mail::fake();
    $admin = admin(twoFactor: true);
    $this->actingAs($admin);

    $this->postJson('/admin/mail-templates/quote.remind/preview', ['subject' => 'Relance {numero_devis}', 'body' => 'Bonjour {destinataire}'])
        ->assertOk()->assertJson(['subject' => 'Relance DEV-2026-0042', 'body' => 'Bonjour Mme Martin']);
    $this->postJson('/admin/mail-templates/quote.remind/preview', ['subject' => '{oups}', 'body' => 'x'])->assertUnprocessable();

    $this->post('/admin/mail-templates/quote.remind/test', ['subject' => 'Relance {numero_devis}', 'body' => 'Bonjour {destinataire}'])
        ->assertSessionHasNoErrors()->assertSessionHas('status', "E-mail de test envoyé à {$admin->email}.");
    Mail::assertSent(fn (MailTemplateTestMail $m) => $m->build()->hasTo($admin->email) && $m->subject === '[Test] Relance DEV-2026-0042');
});

it('keeps templates per organisme, cached under the tenant id', function () {
    inTenant();
    MailTemplate::query()->create(['key' => 'quote.send', 'subject' => 'Mien', 'body' => 'x']);
    app(MailTemplates::class)->forget('quote.send');
    $other = Tenant::factory()->create();

    expect(app(MailTemplates::class)->get('quote.send')['subject'])->toBe('Mien')
        ->and(app(TenantContext::class)->run($other, fn () => app(MailTemplates::class)->get('quote.send')['subject']))->toBe('Devis {numero_devis} - {societe}')
        ->and(MailTemplates::cacheKey(tenant()->id, 'quote.send'))->toStartWith(tenant()->id.':');
});

it('refuses control characters in a subject and caps the prefilled quote subject', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->put('/admin/mail-templates/quote.send', ['subject' => "Devis\r\nBcc: x@example.test", 'body' => 'x'])
        ->assertSessionHasErrors(['subject' => "L'objet doit tenir sur une ligne, sans caractère de contrôle."]);
    $this->put('/admin/mail-templates/quote.send', ['subject' => "Devis\t{numero_devis}", 'body' => 'x'])->assertSessionHasErrors('subject');

    $this->put('/admin/mail-templates/quote.send', ['subject' => str_repeat('{societe} ', 20), 'body' => 'x'])->assertSessionHasNoErrors();
    $quote = sendableQuote();
    $quote->company?->update(['name' => str_repeat('Très longue raison sociale ', 4)]);
    $subject = QuoteMail::defaults($quote->refresh(), QuoteMail::KIND_SEND)['subject'];
    expect(mb_strlen($subject))->toBeLessThanOrEqual(200);
});

it('limits the writes of the templates screen', function () {
    foreach (['admin.mail-templates.update', 'admin.mail-templates.destroy', 'admin.mail-templates.preview'] as $name) {
        expect(app('router')->getRoutes()->getByName($name)?->gatherMiddleware())->toContain('throttle:mail-templates-write');
    }
});

it('renders the invitation text literally, without Markdown', function () {
    inTenant();
    MailTemplate::query()->create(['key' => 'users.invitation', 'subject' => 'Bienvenue', 'body' => "Bonjour {nom_utilisateur},\nsuite de la formule\n\n[lien](http://x.example.test)\n\n# titre"]);
    app(MailTemplates::class)->forget('users.invitation');

    $html = (new InvitationMail('https://cap.example.test/activation/abc', '*Léa* [Durand](http://evil.test)', 'CAP FORMATION'))->build()->render();

    expect($html)->not->toContain('href="http://x.example.test"')
        ->and($html)->not->toContain('href="http://evil.test"')
        ->and($html)->not->toContain('<h1>titre')
        ->and($html)->not->toContain('<em>Léa</em>')
        ->and($html)->toContain('[lien](http://x.example.test)')
        ->and($html)->toContain('# titre')
        ->and($html)->toContain('suite de la formule')
        ->and($html)->toContain('https://cap.example.test/activation/abc');
});

it('saves a template even when a concurrent first save collides', function () {
    $this->actingAs(admin(twoFactor: true));
    $collided = false;
    MailTemplate::creating(function () use (&$collided): void {
        if (! $collided) {
            $collided = true;

            throw new UniqueConstraintViolationException('pgsql', 'insert into mail_templates', [], new RuntimeException('duplicate key'));
        }
    });

    $this->put('/admin/mail-templates/quote.remind', ['subject' => 'Relance {numero_devis}', 'body' => 'x'])->assertSessionHasNoErrors();

    expect($collided)->toBeTrue()
        ->and(MailTemplate::query()->where('key', 'quote.remind')->sole()->subject)->toBe('Relance {numero_devis}');
});

it('shows « < » and « > » of the invitation text as typed', function () {
    inTenant();
    MailTemplate::query()->create(['key' => 'users.invitation', 'subject' => 'Bienvenue', 'body' => "Bonjour,\n\nSi a < b et c > d, rejoignez-nous."]);
    app(MailTemplates::class)->forget('users.invitation');

    $html = (new InvitationMail('https://cap.example.test/activation/abc', 'Léa', 'CAP'))->build()->render();

    expect($html)->toContain('a &lt; b et c &gt; d')
        ->and($html)->not->toContain('&amp;lt;')
        ->and($html)->not->toContain('\\&lt;');
});
