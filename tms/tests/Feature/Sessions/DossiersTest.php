<?php

use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\AbandonRule;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function dosCompany(): Company
{
    return Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dosTraining(string $title = 'HACCP 3 jours', string $das = 'Hôtellerie-restauration', array $attributes = []): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, $das)->id, 'duration_hours' => '21.00', ...$attributes]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function dosLine(?Training $training, array $overrides = []): array
{
    return [
        'kind' => $training === null ? 'fee' : 'training',
        'training_id' => $training?->id,
        'label' => $training->title ?? 'Frais de déplacement',
        'quantity' => '1',
        'unit' => $training === null ? 'forfait' : 'stagiaire',
        'unit_price' => '1000',
        'discount_percent' => '',
        'pricing_mode' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  list<array<string, mixed>>|null  $lines
 * @return array<string, mixed>
 */
function dosPayload(?Organisation $organisation = null, ?array $lines = null, array $overrides = []): array
{
    $organisation ??= Organisation::query()->create(['name' => 'Hôtel du Parc']);

    return [
        'company_id' => dosCompany()->id,
        'account_organisation_id' => $organisation->id,
        'account_person_id' => null,
        'contact_person_id' => null,
        'responsible_user_id' => null,
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'abandon_mode' => null,
        'abandon_dedit_percent' => null,
        'notes' => null,
        'lines' => $lines ?? [dosLine(dosTraining())],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dosCreate(array $overrides = [], ?array $lines = null): Dossier
{
    return app(DossierWriter::class)->create(dosPayload(null, $lines, $overrides));
}

/**
 * A user of a custom role holding exactly $grants (permission => scope; null: « all »).
 *
 * @param  array<string, AccessScope|null>  $grants
 */
function dosUser(array $grants): User
{
    $user = userWithPermissions(array_keys($grants));
    $role = $user->roles()->firstOrFail();
    foreach ($grants as $permission => $scope) {
        if ($scope !== null) {
            DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => $scope->value]);
        }
    }
    Scopes::forget();

    return $user;
}

// ---------------------------------------------------------------- numbering

it('numbers the dossiers D-<Paris year>-<0001> per organisme', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00', 'Europe/Paris'));
    expect(dosCreate()->number)->toBe('D-2026-0001')
        ->and(dosCreate()->number)->toBe('D-2026-0002');

    // 23:30 UTC on 31 December is already 2027 in Paris.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:30', 'UTC'));
    expect(dosCreate()->number)->toBe('D-2027-0001');

    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $number = app(TenantContext::class)->run($other, fn () => dosCreate()->number);
    expect($number)->toBe('D-2027-0001');
});

it('gives the number back when the creation fails inside its transaction', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-01 10:00', 'Europe/Paris'));
    $payload = dosPayload();
    $fail = true;
    DossierLine::creating(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Échec au milieu de la création.');
        }
    });

    expect(fn () => app(DossierWriter::class)->create($payload))->toThrow(RuntimeException::class);
    expect(Dossier::query()->count())->toBe(0)
        ->and(app(DossierWriter::class)->create($payload)->number)->toBe('D-2026-0001');
});

// ---------------------------------------------------------------- contractant

it('takes an entreprise or a particulier as contractant, exactly one', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $individual = makePerson('Martin', ['first_name' => 'Léa', 'is_individual' => true]);
    $employee = makePerson('Durand', ['is_individual' => false]);

    $dossier = app(DossierWriter::class)->create(dosPayload($organisation));
    expect($dossier->account_organisation_id)->toBe($organisation->id)->and($dossier->account_person_id)->toBeNull();

    $dossier = app(DossierWriter::class)->create(dosPayload($organisation, null, ['account_organisation_id' => null, 'account_person_id' => $individual->id]));
    expect($dossier->account_person_id)->toBe($individual->id)->and($dossier->accountType())->toBe('person');

    $both = fn () => app(DossierWriter::class)->create(dosPayload($organisation, null, ['account_person_id' => $individual->id]));
    expect($both)->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('account'));
    $none = fn () => app(DossierWriter::class)->create(dosPayload($organisation, null, ['account_organisation_id' => null]));
    expect($none)->toThrow(fn (ValidationException $e) => expect($e->errors()['account'])->toBe(['Choisissez l\'entreprise ou le particulier contractant.']));
    $notIndividual = fn () => app(DossierWriter::class)->create(dosPayload($organisation, null, ['account_organisation_id' => null, 'account_person_id' => $employee->id]));
    expect($notIndividual)->toThrow(fn (ValidationException $e) => expect($e->errors()['account_person_id'])->toBe(['Choisissez une personne « Particulier » du CRM.']));

    $trashed = Organisation::query()->create(['name' => 'Fermé']);
    $trashed->delete();
    $gone = fn () => app(DossierWriter::class)->create(dosPayload($trashed));
    expect($gone)->toThrow(fn (ValidationException $e) => expect($e->errors()['account_organisation_id'])->toBe(['Entreprise introuvable.']));

    // The database refuses both accounts too.
    expect(fn () => DB::table('dossiers')->where('id', $dossier->id)->update(['account_organisation_id' => $organisation->id]))->toThrow(Exception::class);
});

it('requires the DAS of a manual dossier and the company', function () {
    $this->actingAs(commercial());

    $this->post('/dossiers', dosPayload(null, null, ['das_id' => null, 'company_id' => null]))
        ->assertSessionHasErrors(['das_id' => 'Choisissez le DAS du dossier.', 'company_id' => 'Choisissez la société émettrice.']);
    expect(Dossier::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- lines and price

it('refuses a training line without a training, and a dossier without line', function () {
    $this->actingAs(commercial());

    $this->post('/dossiers', dosPayload(null, [dosLine(null, ['kind' => 'training'])]))
        ->assertSessionHasErrors(['lines.0.training_id' => 'Choisissez la formation de la ligne.']);
    $this->post('/dossiers', dosPayload(null, []))->assertSessionHasErrors(['lines' => 'Ajoutez au moins une ligne.']);
    $this->post('/dossiers', dosPayload(null, [dosLine(dosTraining(), ['quantity' => '0'])]))->assertSessionHasErrors('lines.0.quantity');
    $this->post('/dossiers', dosPayload(null, [dosLine(dosTraining(), ['discount_percent' => '120'])]))->assertSessionHasErrors('lines.0.discount_percent');

    $inactive = dosTraining('Ancienne', attributes: ['is_active' => false]);
    $this->post('/dossiers', dosPayload(null, [dosLine($inactive)]))->assertSessionHasErrors(['lines.0.training_id' => 'Cette formation est désactivée.']);
    expect(Dossier::query()->count())->toBe(0);
});

it('computes the price exactly in cents from three lines', function () {
    $this->actingAs(commercial());
    $haccp = dosTraining();
    $excel = dosTraining('Excel', 'Métiers supports');

    $dossier = dosCreate([], [
        // 3 × 333,33 € − 10 % = 899,991 → 899,99 €
        dosLine($haccp, ['quantity' => '3', 'unit_price' => '333,33', 'discount_percent' => '10']),
        // 1 × 45,50 €
        dosLine(null, ['unit_price' => '45,50']),
        // 2,5 × 1 234,57 € = 3 086,425 → 3 086,43 € (half up)
        dosLine($excel, ['quantity' => '2,5', 'unit' => 'jour', 'unit_price' => '1 234,57']),
    ]);

    $lines = $dossier->lines()->get();
    expect($lines->pluck('total_ht')->all())->toBe(['899.99', '45.50', '3086.43'])
        ->and($dossier->fresh()->priceCents())->toBe(403192)
        ->and($lines[0]->das_id)->toBe($haccp->das_id)
        ->and($lines[2]->das_id)->toBe($excel->das_id)
        // A fee carries the dossier's DAS.
        ->and($lines[1]->das_id)->toBe($dossier->das_id)
        ->and($lines->pluck('position')->all())->toBe([0, 1, 2]);

    $this->get("/dossiers/{$dossier->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/dossiers/show')
        ->where('dossier.price', '4 031,92 €')
        ->where('dossier.price_cents', 403192)
        ->where('dossier.lines.0.total_ht', '899,99 €')
        ->where('dossier.lines.2.quantity', '2,50')
        ->where('dossier.lines.1.kind_label', 'Frais'));
});

it('proposes the pricing mode from the unit, keeps the one chosen, none for a fee', function () {
    $training = dosTraining();
    $dossier = dosCreate([], [
        dosLine($training, ['unit' => 'stagiaire']),
        dosLine($training, ['unit' => 'jour']),
        dosLine($training, ['unit' => 'stagiaire', 'pricing_mode' => 'group']),
        dosLine(null, ['pricing_mode' => 'per_trainee']),
    ]);

    expect($dossier->lines()->pluck('pricing_mode')->all())->toBe(['per_trainee', 'group', 'group', null]);
});

it('updates the lines keeping their ids, adding and removing the others', function () {
    $this->actingAs(commercial());
    $training = dosTraining();
    $dossier = dosCreate([], [dosLine($training), dosLine(null)]);
    [$first, $fee] = $dossier->lines()->get()->all();

    $payload = dosPayload(Organisation::query()->findOrFail($dossier->account_organisation_id), [
        ['id' => $first->id, ...dosLine($training, ['quantity' => '2'])],
        dosLine(null, ['label' => 'Repas', 'unit_price' => '15']),
    ]);
    $this->put("/dossiers/{$dossier->id}", $payload)->assertRedirect("/dossiers/{$dossier->id}");

    $lines = $dossier->lines()->get();
    expect($lines)->toHaveCount(2)
        ->and($lines[0]->id)->toBe($first->id)
        ->and($lines[0]->total_ht)->toBe('2000.00')
        ->and($lines[1]->label)->toBe('Repas')
        ->and(DossierLine::query()->whereKey($fee->id)->exists())->toBeFalse()
        ->and($dossier->fresh()->priceCents())->toBe(201500);

    // A line of another dossier is unknown here.
    $other = dosCreate();
    $foreign = $other->lines()->firstOrFail();
    $this->put("/dossiers/{$dossier->id}", dosPayload(null, [['id' => $foreign->id, ...dosLine($training)]]))
        ->assertSessionHasErrors(['lines.0.id' => 'Ligne inconnue.']);
});

// ---------------------------------------------------------------- abandon rule

it('applies the dossier override of the abandon rule, otherwise the organisme rule', function () {
    $tenant = tenant();
    $tenant->forceFill(['settings' => ['sessions' => ['abandon_rule' => ['mode' => 'prorata_dedit', 'dedit_percent' => 30]]]])->save();
    app(TenantContext::class)->set($tenant->fresh());

    $plain = dosCreate();
    expect(AbandonRule::forDossier($plain))->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 30, 'overridden' => false]);

    $own = dosCreate(['abandon_mode' => 'prorata', 'abandon_dedit_percent' => '50']);
    expect(AbandonRule::forDossier($own))->toBe(['mode' => 'prorata', 'dedit_percent' => null, 'overridden' => true]);

    $dedit = dosCreate(['abandon_mode' => 'prorata_dedit', 'abandon_dedit_percent' => '15']);
    expect(AbandonRule::forDossier($dedit))->toBe(['mode' => 'prorata_dedit', 'dedit_percent' => 15, 'overridden' => true]);

    expect(fn () => dosCreate(['abandon_mode' => 'prorata_dedit']))->toThrow(ValidationException::class);

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dedit->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dossier.abandon_rule.label', 'Prorata et dédit de 15 %')->where('dossier.abandon_rule.overridden', true));
});

// ---------------------------------------------------------------- page, links

it('creates a dossier from the form and shows it, journaled', function () {
    $user = commercial();
    $this->actingAs($user);

    $this->post('/dossiers', dosPayload())->assertRedirect();
    $dossier = Dossier::query()->sole();
    expect($dossier->responsible_user_id)->toBe($user->id)
        ->and($dossier->created_by)->toBe($user->id)
        ->and(AuditLog::query()->where('auditable_id', $dossier->id)->where('event', 'created')->exists())->toBeTrue();

    $this->get("/dossiers/{$dossier->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dossier.number', $dossier->number)
        ->where('dossier.status_label', 'Créé')
        ->where('dossier.account.label', 'Hôtel du Parc')
        ->where('dossier.training_titles', ['HACCP 3 jours'])
        ->where('can.steps.convention-sent', true)
        ->where('can.steps.convention-signed', false));
});

it('links the contractant and the contact only when the user can open their fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $contact = makePerson('Durand', ['first_name' => 'Léa']);
    $dossier = app(DossierWriter::class)->create(dosPayload($organisation, null, ['contact_person_id' => $contact->id]));

    $this->actingAs(commercial())->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dossier.account.can_open', true)->where('dossier.contact.label', 'Léa Durand')->where('dossier.contact.can_open', true));

    // Dossiers without the CRM fiches: names in plain text.
    $this->actingAs(dosUser([Permissions::DOSSIERS_VIEW => null]))->get("/dossiers/{$dossier->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('dossier.account.label', 'Hôtel du Parc')->where('dossier.account.can_open', false)->where('dossier.contact.can_open', false));
});

// ---------------------------------------------------------------- list, export, scope

it('lists and exports only the dossiers of the user scope', function () {
    $me = dosUser([Permissions::DOSSIERS_VIEW => AccessScope::Own, 'crm.export' => null]);
    $someone = commercial();
    $mine = dosCreate(['responsible_user_id' => $me->id]);
    $other = dosCreate(['responsible_user_id' => $someone->id]);
    DB::table('dossiers')->update(['created_by' => $someone->id]);
    $this->actingAs($me);

    $this->get('/dossiers')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('sessions/dossiers/index')->has('dossiers.data', 1)->where('dossiers.data.0.id', $mine->id));
    $this->get("/dossiers/{$other->id}")->assertNotFound();

    $csv = $this->get('/dossiers/export')->assertOk()->streamedContent();
    expect($csv)->toContain($mine->number)->not->toContain($other->number);

    // « Ses DAS »: the dossiers of the DAS he follows.
    $das = dosUser([Permissions::DOSSIERS_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Métiers supports'));
    $support = dosCreate(['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    $this->actingAs($das)->get('/dossiers')->assertInertia(fn (Assert $page) => $page->has('dossiers.data', 1)->where('dossiers.data.0.id', $support->id));

    // Without crm.export, no export.
    $this->actingAs(dosUser([Permissions::DOSSIERS_VIEW => null]))->get('/dossiers/export')->assertForbidden();
});

it('exports a French CSV: « ; », French amounts and dates, accents kept', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true));
    $dossier = dosCreate([], [dosLine(dosTraining(), ['quantity' => '3', 'unit_price' => '1 234,56'])]);

    $response = $this->get('/dossiers/export')->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    $csv = $response->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBF");
    $lines = explode("\r\n", trim(substr($csv, 3)));
    expect($lines[0])->toStartWith('Numéro;"Créé le";Contractant;"Type de contractant"')
        ->and($lines[1])->toContain("{$dossier->number};03/12/2026;\"Hôtel du Parc\";Entreprise")
        ->and($lines[1])->toContain(';"3 703,68";Créé;')
        ->and(AuditLog::query()->where('event', 'export.run')->latest('id')->first()?->new_values['export'] ?? null)->toBe('sessions.dossiers');
});

it('filters the list by search, state and DAS', function () {
    $this->actingAs(admin(twoFactor: true));
    $parc = dosCreate();
    $other = app(DossierWriter::class)->create(dosPayload(Organisation::query()->create(['name' => 'Garage Martin']), [dosLine(dosTraining('Excel', 'Métiers supports'))], ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]));

    $ids = fn (string $query) => collect($this->get("/dossiers?{$query}")->original->getData()['page']['props']['dossiers']['data'])->pluck('id')->all();
    expect($ids('q=garage'))->toBe([$other->id])
        ->and($ids('q=haccp'))->toBe([$parc->id])
        ->and($ids('q='.$parc->number))->toBe([$parc->id])
        ->and($ids('das='.crmItem(ReferenceList::Das, 'Métiers supports')->id))->toBe([$other->id])
        ->and($ids('status=cancelled'))->toBe([]);
});

it('refuses a DAS-limited user a dossier outside his DAS', function () {
    $user = dosUser([Permissions::DOSSIERS_VIEW => AccessScope::Das, Permissions::DOSSIERS_UPDATE => AccessScope::Das]);
    followDas($user, crmItem(ReferenceList::Das, 'Métiers supports'));
    $this->actingAs($user);

    $this->post('/dossiers', dosPayload())->assertSessionHasErrors(['das_id' => 'Ce DAS n\'est pas dans vos DAS.']);
    $this->post('/dossiers', dosPayload(null, null, ['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]))->assertRedirect();
    expect(Dossier::query()->count())->toBe(1);
});

// ---------------------------------------------------------------- trash, guards

it('sends a dossier to the trash and restores it with sessions.dossiers.delete only', function () {
    $dossier = dosCreate();

    $this->actingAs(commercial())->delete("/dossiers/{$dossier->id}")->assertForbidden();
    $this->actingAs(admin(twoFactor: true))->delete("/dossiers/{$dossier->id}")->assertRedirect('/dossiers');
    expect($dossier->fresh()->trashed())->toBeTrue();
    $this->get('/dossiers')->assertInertia(fn (Assert $page) => $page->has('dossiers.data', 0));
    $this->get('/dossiers?trashed=1')->assertInertia(fn (Assert $page) => $page->has('dossiers.data', 1));
    $this->actingAs(commercial())->get("/dossiers/{$dossier->id}")->assertNotFound();

    $this->actingAs(admin(twoFactor: true))->post("/dossiers/{$dossier->id}/restore")->assertRedirect("/dossiers/{$dossier->id}");
    expect($dossier->fresh()->trashed())->toBeFalse();
});

it('keeps a training used by a dossier line out of deletion, and the DAS in use', function () {
    $training = dosTraining();
    $dossier = dosCreate([], [dosLine($training)]);
    $dossier->delete();

    expect(TrainingUsage::isUsed($training->id))->toBeTrue()
        ->and(crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->isInUse())->toBeTrue();
});

it('answers 404 for a dossier of another organisme or a malformed id', function () {
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $theirs = app(TenantContext::class)->run($other, fn () => dosCreate());
    inTenant();
    $this->actingAs(admin(twoFactor: true));

    $this->get("/dossiers/{$theirs->id}")->assertNotFound();
    $this->get('/dossiers/pas-un-uuid')->assertNotFound();
    $this->put("/dossiers/{$theirs->id}", [])->assertNotFound();
});

it('searches entreprises and people for the contractant with the update right only', function () {
    Organisation::query()->create(['name' => 'Hôtel du Parc']);
    makePerson('Parcelle', ['first_name' => 'Jean', 'is_individual' => true]);

    $this->actingAs(direction(twoFactor: true))->getJson('/dossiers/organisations?q=parc')->assertForbidden();
    $this->actingAs(commercial())->getJson('/dossiers/organisations?q=parc')->assertOk()->assertJsonPath('entreprises.0.label', 'Hôtel du Parc');
    $this->getJson('/dossiers/people?q=parcelle')->assertOk()->assertJsonPath('personnes.0.last_name', 'Parcelle');
    expect(Person::query()->count())->toBe(1);
});
