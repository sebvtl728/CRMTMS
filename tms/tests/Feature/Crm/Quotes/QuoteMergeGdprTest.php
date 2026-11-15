<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteMail;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Quotes\SendQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
    fakeQuoteGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

/**
 * Sends $quote through the page (frozen, archived, mailed).
 *
 * @param  array<string, mixed>  $message
 */
function qgSend(Quote $quote, array $message = []): Quote
{
    test()->post("/crm/quotes/{$quote->id}/send", quoteMessage($message))->assertSessionHasNoErrors();

    return $quote->refresh();
}

it('merge moves quotes of an entreprise, trashed ones too, without touching the frozen recipient', function () {
    $this->actingAs(commercial());
    $sent = sendableQuote();
    $drop = $sent->organisation;
    $keep = Organisation::query()->create(['name' => 'Le Bistrot (doublon)']);
    qgSend($sent);
    $snapshot = $sent->recipient_snapshot;
    $draft = makeQuote(makeOpportunity($drop, ['title' => 'Brouillon']));
    $trashed = makeQuote(makeOpportunity($drop, ['title' => 'Supprimé']));
    $trashed->delete();

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.organisations.show', $keep));

    expect($sent->refresh()->organisation_id)->toBe($keep->id)
        ->and($sent->recipient_snapshot)->toBe($snapshot)
        ->and($draft->refresh()->organisation_id)->toBe($keep->id)
        ->and(Quote::withTrashed()->findOrFail($trashed->id)->organisation_id)->toBe($keep->id)
        ->and(Quote::withTrashed()->where('organisation_id', $drop->id)->count())->toBe(0);
});

it('merge moves the quotes of a person, as particulier and as recipient', function () {
    $this->actingAs(commercial());
    $sent = sendableQuote();
    $drop = $sent->contact;
    $keep = makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea@autre.test']);
    affiliate($keep, $sent->organisation);
    qgSend($sent);
    $snapshot = $sent->recipient_snapshot;
    $individual = makeQuote(makeOpportunity($drop, ['title' => 'Particulier']));
    $individual->forceFill(['contact_person_id' => $drop->id])->save();

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.people.show', $keep));

    expect($sent->refresh()->contact_person_id)->toBe($keep->id)
        ->and($sent->recipient_snapshot)->toBe($snapshot)
        ->and($individual->refresh()->person_id)->toBe($keep->id)
        ->and($individual->contact_person_id)->toBe($keep->id)
        ->and(Quote::withTrashed()->where('person_id', $drop->id)->orWhere('contact_person_id', $drop->id)->count())->toBe(0);
});

it('anonymisation of a particulier keeps the quote figures and purges its documents', function () {
    $this->actingAs(commercial());
    $person = makePerson('Zébulon', ['first_name' => 'Anatole', 'is_individual' => true, 'email' => 'anatole@example.test',
        'address_line1' => '3 impasse Verte', 'postal_code' => '74000', 'city' => 'Annecy']);
    $quote = makeQuote(makeOpportunity($person, ['title' => 'BTS']));
    $quote->forceFill(['contact_person_id' => $person->id, 'notes' => 'Anatole préfère le matin'])->save();
    qgSend($quote, ['to' => 'anatole@example.test', 'body' => "Bonjour Anatole,\n\nVoici notre devis."]);
    $this->post("/crm/quotes/{$quote->id}/refuse", ['refusal_reason' => 'Anatole trouve cela trop cher'])->assertSessionHasNoErrors();
    $quote->refresh();
    $pdf = $quote->pdf_path;
    expect(Storage::disk(TenantStorage::DISK)->exists($pdf))->toBeTrue()
        ->and(personalTraces(['anatole@example.test', 'impasse Verte', 'préfère le matin', 'trouve cela trop cher']))->not->toBe([]);

    $number = $quote->number;

    app(Anonymiser::class)->anonymise($person, 'request');

    $quote->refresh();
    expect($quote->number)->toBe($number)
        ->and($quote->status)->toBe(QuoteStatus::Refused)
        ->and([$quote->total_ht, $quote->total_ttc])->toBe(['1000.00', '1200.00'])
        ->and($quote->lines()->count())->toBe(1)
        ->and($quote->person_id)->toBe($person->id)
        ->and($quote->contact_person_id)->toBeNull()
        ->and($quote->recipient_snapshot)->toBeNull()
        ->and($quote->notes)->toBeNull()
        ->and($quote->refusal_reason)->toBeNull()
        ->and($quote->email_message)->toBeNull()
        ->and($quote->pdf_path)->toBeNull()
        ->and(Storage::disk(TenantStorage::DISK)->exists($pdf))->toBeFalse();
    expect(personalTraces(['Anatole', 'Zébulon', 'anatole@example.test', 'impasse Verte', 'préfère le matin', 'trouve cela trop cher']))->toBe([]);

    // The document is gone: never rendered again with today's data.
    $calls = count(Http::recorded());
    $this->get("/crm/quotes/{$quote->id}/pdf")->assertStatus(410)->assertSee('Document supprimé (données anonymisées).');
    expect(count(Http::recorded()))->toBe($calls);
    $this->get("/crm/quotes/{$quote->id}")->assertOk();
});

it('anonymisation of an interlocuteur removes her from the quotes of the entreprise', function () {
    $this->actingAs(commercial());
    $quote = sendableQuote();
    $lea = $quote->contact;
    qgSend($quote, ['body' => "Bonjour Madame Durand,\n\nVoici notre devis."]);
    $copied = makeQuote(makeOpportunity($quote->organisation, ['title' => 'Cuisine']));
    qgSend($copied, ['to' => 'accueil@bistrot.test', 'cc' => ['lea.durand@example.test', 'chef@bistrot.test']]);
    $pdf = $quote->pdf_path;
    $otherPdf = $copied->pdf_path;

    app(Anonymiser::class)->anonymise($lea, 'request');

    $quote->refresh();
    expect($quote->contact_person_id)->toBeNull()
        ->and($quote->recipient_snapshot)->toEqual([
            'name' => 'Le Bistrot', 'attention' => null, 'address_line1' => null, 'address_line2' => null,
            'postal_code' => null, 'city' => 'Annecy', 'email' => null,
        ])
        ->and($quote->email_message)->toBeNull()
        ->and($quote->pdf_path)->toBeNull()
        ->and(Storage::disk(TenantStorage::DISK)->exists($pdf))->toBeFalse()
        ->and($quote->status)->toBe(QuoteStatus::Sent);
    // Only in copy: her address leaves the copies, the document (not naming her) stays.
    $copied->refresh();
    expect($copied->email_message['to'])->toBe('accueil@bistrot.test')
        ->and($copied->email_message['cc'])->toBe(['chef@bistrot.test'])
        ->and($copied->pdf_path)->toBe($otherPdf)
        ->and(Storage::disk(TenantStorage::DISK)->exists($otherPdf))->toBeTrue();
    expect(personalTraces(['Durand', 'lea.durand@example.test']))->toBe([]);

    $this->get("/crm/quotes/{$quote->id}/pdf")->assertStatus(410)->assertSee('Document supprimé (données anonymisées).');

    // No reminder without the archived document (no new rendering): refused, hidden.
    $calls = count(Http::recorded());
    $this->get("/crm/quotes/{$quote->id}")->assertInertia(fn (Assert $page) => $page->where('pdf_available', false)->where('can.remind', false));
    $this->post("/crm/quotes/{$quote->id}/remind", quoteMessage(['to' => 'accueil@bistrot.test']))
        ->assertStatus(409)->assertSee('Le PDF archivé de ce devis est introuvable', false);
    expect(Mail::sent(QuoteMail::class))->toHaveCount(2) // the two sendings above
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Sent)
        ->and($quote->reminded_at)->toBeNull()
        ->and(count(Http::recorded()))->toBe($calls);

    // A message queued before the purge sends nothing either.
    $quote->forceFill(['email_status' => 'pending', 'email_message' => ['kind' => QuoteMail::KIND_REMIND, ...quoteMessage(['to' => 'accueil@bistrot.test'])]])->save();
    SendQuote::dispatchSync($quote->id);
    expect(Mail::sent(QuoteMail::class))->toHaveCount(2)
        ->and($quote->refresh()->email_status)->toBe('failed')
        ->and($quote->email_error)->toBe(SendQuote::NO_DOCUMENT)
        ->and(count(Http::recorded()))->toBe($calls);
});

it('journals no personal value of a quote', function () {
    $this->actingAs(commercial());
    $quote = sendableQuote();
    $quote->forceFill(['notes' => 'Secret Durand'])->save();
    qgSend($quote);
    $this->post("/crm/quotes/{$quote->id}/refuse", ['refusal_reason' => 'Motif Durand'])->assertSessionHasNoErrors();

    $journal = AuditLog::query()->where('auditable_type', 'quote')->get()
        ->map(fn ($l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode(' ');
    expect($journal)->not->toContain('Durand')->not->toContain('lea.durand@example.test')->not->toContain('Annecy');
});

it('lists the quotes of a person in her data export, complete or refused', function () {
    $this->actingAs(commercial());
    $quote = sendableQuote();
    $lea = $quote->contact;
    qgSend($quote);
    $lea->update(['is_individual' => true]);
    $own = makeQuote(makeOpportunity($lea, ['title' => 'BTS', 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]));
    makeQuote(makeOpportunity($quote->organisation, ['title' => 'Sans elle']));

    $data = PersonDataExport::for(Person::query()->findOrFail($lea->id));
    expect($data['devis'])->toBe([
        ['numero' => 'CF-2026-0001', 'date' => '2026-11-14', 'role' => 'Destinataire', 'statut' => 'Envoyé', 'total_ttc' => '1200.00', 'devis_signe' => 'non', 'supprime' => false],
        ['numero' => 'CF-2026-0002', 'date' => '2026-11-14', 'role' => 'Compte (particulier)', 'statut' => 'Brouillon', 'total_ttc' => '1200.00', 'devis_signe' => 'non', 'supprime' => false],
    ]);

    // A DAS-restricted exporter would get a trimmed file: refused.
    RoleModel::findByName(Role::ResponsableDas->value)->givePermissionTo('crm.gdpr');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $responsable = userWithRole(Role::ResponsableDas);
    $hotel = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    followDas($responsable, $hotel);
    // He sees her (her DAS, her entreprise's DAS), not the « Métiers supports » quote.
    $lea->das()->sync([$hotel->id => ['tenant_id' => tenant()->id]]);
    $quote->organisation->das()->sync([$hotel->id => ['tenant_id' => tenant()->id]]);
    $this->actingAs($responsable)->get("/crm/people/{$lea->id}")->assertOk();
    $this->getJson("/crm/people/{$lea->id}/gdpr-export")
        ->assertForbidden()->assertJson(['message' => PersonDataExport::INCOMPLETE]);

    // Every domain on « Tout l'organisme »: the full file, the other DAS's quote too.
    $json = $this->actingAs(direction(twoFactor: true))->get("/crm/people/{$lea->id}/gdpr-export")->assertOk()->getContent();
    expect(collect(json_decode((string) $json, true)['devis'])->pluck('numero')->all())->toBe(['CF-2026-0001', 'CF-2026-0002']);
    expect($own->number)->toBe('CF-2026-0002');
});
