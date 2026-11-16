<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions as CrmPermissions;
use App\Modules\Crm\Public\AcceptedQuotes;
use App\Modules\Crm\Public\DuplicateTrainee;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Crm\Public\UserDas;
use App\Modules\Crm\Quotes\QuoteStatus;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function employ(Person $person, Organisation $organisation, array $attributes = []): Affiliation
{
    return Affiliation::query()->create(['person_id' => $person->id, 'organisation_id' => $organisation->id, ...$attributes]);
}

// ---------------------------------------------------------------- AcceptedQuotes

it('reads an accepted quote with its lines in cents', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $contact = makePerson('Martin', ['first_name' => 'Léa']);
    $opportunity = makeOpportunity($org);
    $quote = makeQuote($opportunity, lines: [
        ['training_id' => null, 'label' => 'Formation HACCP', 'description' => null, 'quantity' => '3.00',
            'unit' => 'stagiaire', 'unit_price' => '333.33', 'discount_percent' => '10.00', 'vat_rate' => '20.00'],
        ['training_id' => null, 'label' => 'Frais de déplacement', 'description' => null, 'quantity' => '1.00',
            'unit' => 'forfait', 'unit_price' => '120.50', 'discount_percent' => '0.00', 'vat_rate' => '20.00'],
    ]);
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => now('Europe/Paris')->toDateString(), 'contact_person_id' => $contact->id])->save();

    $data = app(AcceptedQuotes::class)->find($quote->id, admin());

    expect($data)->not->toBeNull()
        ->and($data->id)->toBe($quote->id)
        ->and($data->number)->toBe($quote->fresh()->number)
        ->and($data->company_id)->toBe($quote->company_id)
        ->and($data->account)->toBe(['type' => 'organisation', 'id' => $org->id, 'label' => 'Hôtel du Parc'])
        ->and($data->contact_person_id)->toBe($contact->id)
        ->and($data->opportunity_id)->toBe($opportunity->id)
        ->and($data->das_id)->toBe($opportunity->das_id)
        ->and($data->lines)->toHaveCount(2)
        ->and($data->lines[0])->toBe([
            'training_id' => null, 'das_id' => $quote->lines[0]->das_id, 'label' => 'Formation HACCP', 'quantity' => '3.00', 'unit' => 'stagiaire',
            'unit_price_cents' => 33333, 'discount_percent' => '10.00', 'total_ht_cents' => 89999,
        ])
        ->and($data->lines[1]['unit_price_cents'])->toBe(12050)
        ->and($data->lines[1]['total_ht_cents'])->toBe(12050);
});

it('reads an accepted quote of a particulier', function () {
    $person = makePerson('Durand', ['first_name' => 'Paul', 'is_individual' => true]);
    $quote = makeQuote(makeOpportunity($person));
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => now('Europe/Paris')->toDateString()])->save();

    expect(app(AcceptedQuotes::class)->find($quote->id, admin())->account)
        ->toBe(['type' => 'person', 'id' => $person->id, 'label' => 'Paul Durand']);
});

it('gives nothing for a quote not accepted, invisible or unknown', function () {
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Hôtel du Parc']));
    $sent = makeQuote($opportunity);
    $sent->forceFill(['status' => QuoteStatus::Sent])->save();
    $accepted = makeQuote($opportunity);
    $accepted->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => now('Europe/Paris')->toDateString()])->save();
    $service = app(AcceptedQuotes::class);

    expect($service->find($sent->id, admin()))->toBeNull()
        ->and($service->find('not-a-uuid', admin()))->toBeNull()
        ->and($service->find((string) Str::uuid7(), admin()))->toBeNull()
        // No quotes right, or a Responsable DAS who does not follow the opportunity's DAS.
        ->and($service->find($accepted->id, userWithPermissions([CrmPermissions::FICHES_VIEW])))->toBeNull()
        ->and($service->find($accepted->id, userWithRole(Role::ResponsableDas)))->toBeNull()
        ->and($service->find($accepted->id, userWithPermissions([CrmPermissions::QUOTES_VIEW])))->not->toBeNull();

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    expect($service->find($accepted->id, $responsable))->not->toBeNull();

    // A quote whose opportunity is in the trash is not transformed.
    $opportunity->delete();
    expect($service->find($accepted->id, admin()))->toBeNull();
});

// ---------------------------------------------------------------- Fiches

it('reads the minimal identity of fiches', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc', 'siret' => '12345678900011']);
    Organisation::query()->create(['name' => 'Parc des Expositions']);
    $trashed = Organisation::query()->create(['name' => 'Parc fermé']);
    $trashed->delete();
    $person = makePerson('Martin', ['first_name' => 'Léa', 'birth_date' => '1990-04-12']);
    $fiches = app(Fiches::class);

    expect($fiches->organisation($org->id))->toBe(['id' => $org->id, 'label' => 'Hôtel du Parc'])
        ->and($fiches->organisation($trashed->id))->toBeNull()
        ->and($fiches->organisation('x'))->toBeNull()
        ->and($fiches->person($person->id))->toBe(['id' => $person->id, 'label' => 'Léa Martin', 'birth_date' => '1990-04-12'])
        ->and($fiches->person((string) Str::uuid7()))->toBeNull()
        ->and($fiches->searchOrganisations('parc'))->toBe([
            ['id' => $org->id, 'label' => 'Hôtel du Parc', 'siret' => '12345678900011'],
            ['id' => Organisation::query()->where('name', 'Parc des Expositions')->value('id'), 'label' => 'Parc des Expositions', 'siret' => null],
        ])
        ->and($fiches->searchOrganisations('parc', 1))->toHaveCount(1)
        ->and($fiches->searchOrganisations('  '))->toBe([]);
});

// ---------------------------------------------------------------- Trainees

it('finds a person outside the DAS of a Responsable DAS, identity only (Review Focus 5)', function () {
    $other = Organisation::query()->create(['name' => 'Clinique des Lilas']);
    $other->das()->sync([crmItem(ReferenceList::Das, 'Métiers supports')->id => ['tenant_id' => tenant()->id]]);
    $person = makePerson('Moreau', ['first_name' => 'Julie', 'birth_date' => '1985-02-03', 'email' => 'julie@lilas.test', 'phone' => '0612345678']);
    employ($person, $other, ['job_title' => 'Infirmière']);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $this->actingAs($responsable);
    expect(CrmVisibility::people(Person::query(), $responsable)->whereKey($person->id)->exists())->toBeFalse();

    expect(app(Trainees::class)->search('moreau jul'))->toBe([[
        'id' => $person->id, 'last_name' => 'Moreau', 'first_name' => 'Julie', 'birth_date' => '1985-02-03', 'organisation_label' => 'Clinique des Lilas',
    ]]);
});

it('lists the employees of the contractant first, never anonymised or trashed people', function () {
    $contractant = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $elsewhere = Organisation::query()->create(['name' => 'Autre']);
    $first = makePerson('Bernard', ['first_name' => 'Alain']);
    employ($first, $elsewhere);
    $employee = makePerson('Bernard', ['first_name' => 'Zoé']);
    employ($employee, $contractant);
    $former = makePerson('Bernard', ['first_name' => 'Yves']);
    employ($former, $contractant, ['started_on' => '2020-01-01', 'ended_on' => '2023-01-01']);
    makePerson('Bernard', ['first_name' => 'Anonyme'])->forceFill(['anonymised_at' => now()])->save();
    makePerson('Bernard', ['first_name' => 'Corbeille'])->delete();

    $found = app(Trainees::class)->search('bernard', $contractant->id);

    expect(array_column($found, 'first_name'))->toBe(['Zoé', 'Alain', 'Yves'])
        ->and($found[0]['organisation_label'])->toBe('Hôtel du Parc')
        ->and($found[2]['organisation_label'])->toBeNull()
        ->and(app(Trainees::class)->search('bernard', null, 1))->toHaveCount(1)
        ->and(app(Trainees::class)->search(''))->toBe([]);
});

it('creates a trainee as an ordinary journaled person, after a duplicate check', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $this->actingAs($user = commercial());
    $trainees = app(Trainees::class);

    $id = $trainees->create(['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'marc.petit@example.test', 'birth_date' => '1992-06-30', 'organisation_id' => $org->id]);

    $person = Person::query()->findOrFail($id);
    expect($person->fullName())->toBe('Marc Petit')
        ->and($person->birth_date?->toDateString())->toBe('1992-06-30')
        ->and($person->is_individual)->toBeFalse()
        ->and($person->created_by)->toBe($user->id)
        ->and($person->activeAffiliations()->pluck('organisation_id')->all())->toBe([$org->id])
        ->and(AuditLog::query()->where('event', 'created')->where('auditable_id', $id)->exists())->toBeTrue();

    // Same e-mail: the existing person is offered, identity only.
    try {
        $trainees->create(['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'MARC.petit@example.test']);
        $this->fail('A duplicate was expected.');
    } catch (DuplicateTrainee $e) {
        expect($e->getMessage())->toBe('Des personnes similaires existent déjà.')
            ->and($e->candidates)->toBe([['id' => $id, 'last_name' => 'Petit', 'first_name' => 'Marc', 'birth_date' => '1992-06-30', 'organisation_label' => 'Hôtel du Parc']]);
    }
    expect(Person::query()->where('last_name', 'Petit')->count())->toBe(1);

    // Confirmed: created anyway.
    $trainees->create(['last_name' => 'Petit', 'first_name' => 'Marc', 'email' => 'marc.petit@example.test', 'confirm_duplicate' => true]);
    expect(Person::query()->where('last_name', 'Petit')->count())->toBe(2);
});

it('validates a new trainee in French', function () {
    $this->actingAs(commercial());

    expect(fn () => app(Trainees::class)->create(['first_name' => 'Marc', 'birth_date' => '30/06/1992']))
        ->toThrow(ValidationException::class);

    try {
        app(Trainees::class)->create(['first_name' => 'Marc']);
        $this->fail('A validation error was expected.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('last_name')
            ->and($e->errors()['last_name'][0])->toBe('Ce champ est obligatoire.');
    }
});

it('tells the DAS a user follows', function () {
    $user = userWithRole(Role::ResponsableDas);
    expect(UserDas::ids($user))->toBe([]);

    followDas($user, $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    expect(UserDas::ids($user))->toBe([$das->id]);
});
