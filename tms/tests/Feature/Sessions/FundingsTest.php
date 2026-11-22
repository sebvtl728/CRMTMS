<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingStatus;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function funTraining(): Training
{
    return Training::query()->where('title', 'HACCP 3 jours')->first()
        ?? Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
}

/**
 * A dossier of 3 000 € HT: one training line (per trainee by default) and,
 * with $fee, a fee line of 500 €.
 *
 * @param  array<string, mixed>  $line
 */
function funDossier(array $line = [], bool $fee = false): Dossier
{
    $training = funTraining();
    $lines = [[
        'kind' => 'training', 'training_id' => $training->id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'forfait',
        'unit_price' => '3000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee', ...$line,
    ]];
    if ($fee) {
        $lines[] = ['kind' => 'fee', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '500', 'discount_percent' => ''];
    }

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Parc'])->id,
        'account_person_id' => null,
        'das_id' => $training->das_id,
        'lines' => $lines,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function funPayload(array $overrides = []): array
{
    return [
        'funder_organisation_id' => (Organisation::query()->where('name', 'OPCO EP')->first() ?? Organisation::query()->create(['name' => 'OPCO EP']))->id,
        'funder_person_id' => null,
        'funding_type_id' => crmItem(ReferenceList::FundingType, 'OPCO')->id,
        'dossier_line_id' => null,
        'enrolment_id' => null,
        'amount' => '2 100,00',
        'status' => 'requested',
        'agreement_number' => 'PEC-2026-42',
        'agreed_on' => null,
        'notes' => null,
        ...$overrides,
    ];
}

function funSession(): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => funTraining()->id, 'kind' => 'inter', 'organisation_id' => null, 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'planned_hours' => '', 'location_kind' => 'remote', 'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null,
        'status' => 'planned', 'notes' => null,
    ]);
}

function funEnrol(Dossier $dossier, ?TrainingSession $session = null): Enrolment
{
    return app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session ?? funSession(), makePerson('Stagiaire '.Str::random(6))->id);
}

/**
 * @param  array<string, AccessScope|null>  $grants
 */
function funUser(array $grants): User
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

// ---------------------------------------------------------------- creation, page

it('adds a funding from the dossier page in French amounts, journaled, and shows the balance', function () {
    $dossier = funDossier();
    $this->actingAs(admin(twoFactor: true));

    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['notes' => 'Accord oral de Mme Martin']))
        ->assertRedirect("/dossiers/{$dossier->id}")->assertSessionHasNoErrors();

    $funding = Funding::query()->sole();
    expect($funding->amount)->toBe('2100.00')
        ->and($funding->status)->toBe(FundingStatus::Requested)
        ->and($funding->agreement_number)->toBe('PEC-2026-42');
    $log = AuditLog::query()->where('auditable_id', $funding->id)->where('event', 'created')->sole();
    expect(json_encode($log->new_values))->not->toContain('Martin');

    $this->get("/dossiers/{$dossier->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('fundings', 1)
        ->where('fundings.0.funder.label', 'OPCO EP')
        ->where('fundings.0.type', 'OPCO')
        ->where('fundings.0.amount', '2 100,00 €')
        ->where('fundings.0.status_label', 'Demandé')
        ->where('balance.remaining_cents', 90000)
        ->where('balance.message', 'Reste à financer : 900,00 €')
        ->where('balance.balanced', false)
        ->where('funding_options.status.0.label', 'À demander')
        ->where('can.fund', true));
});

it('defaults the state to « À demander »', function () {
    $dossier = funDossier();

    $funding = app(FundingWriter::class)->create($dossier, funPayload(['status' => null]));

    expect($funding->status)->toBe(FundingStatus::ToRequest)->and(FundingStatus::ToRequest->label())->toBe('À demander');
});

it('accepts a person as funder (a trainee who pays himself)', function () {
    $dossier = funDossier();
    $person = makePerson('Martin', ['first_name' => 'Paul']);
    $this->actingAs(admin(twoFactor: true));

    $this->post("/dossiers/{$dossier->id}/fundings", funPayload([
        'funder_organisation_id' => null, 'funder_person_id' => $person->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'Personnel')->id,
    ]))->assertSessionHasNoErrors();

    expect(Funding::query()->sole()->funder_person_id)->toBe($person->id);
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('fundings.0.funder.type', 'person')->where('fundings.0.funder.label', 'Paul Martin')->where('fundings.0.funder.can_open', true));
});

it('requires exactly one funder, a funding type of its list and a valid amount', function () {
    $dossier = funDossier();
    $this->actingAs(admin(twoFactor: true));
    $person = makePerson('Martin');

    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['funder_organisation_id' => null]))->assertSessionHasErrors('funder');
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['funder_person_id' => $person->id]))->assertSessionHasErrors('funder');
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['funder_organisation_id' => (string) Str::uuid7()]))->assertSessionHasErrors('funder_organisation_id');
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['funding_type_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]))->assertSessionHasErrors('funding_type_id');
    foreach (['', 'abc', '-5', '12,345', '10000000000'] as $amount) {
        $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['amount' => $amount]))->assertSessionHasErrors('amount');
    }
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['status' => 'lost']))->assertSessionHasErrors('status');
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['agreed_on' => '2026-02-30']))->assertSessionHasErrors('agreed_on');

    expect(Funding::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- targets

it('targets a line or an enrolment of the dossier, never both, never another dossier', function () {
    $dossier = funDossier(fee: true);
    $other = funDossier();
    $mine = funEnrol($dossier);
    $theirs = funEnrol($other);
    $this->actingAs(admin(twoFactor: true));
    $url = "/dossiers/{$dossier->id}/fundings";

    $this->post($url, funPayload(['dossier_line_id' => $other->lines()->firstOrFail()->id]))->assertSessionHasErrors('dossier_line_id');
    $this->post($url, funPayload(['enrolment_id' => $theirs->id]))->assertSessionHasErrors('enrolment_id');
    $this->post($url, funPayload(['dossier_line_id' => $dossier->lines()->firstOrFail()->id, 'enrolment_id' => $mine->id]))->assertSessionHasErrors('target');
    expect(Funding::query()->count())->toBe(0);

    // JSON clients get a 422.
    $this->postJson($url, funPayload(['dossier_line_id' => $other->lines()->firstOrFail()->id]))->assertUnprocessable();

    $this->post($url, funPayload(['dossier_line_id' => $dossier->lines[1]->id]))->assertSessionHasNoErrors();
    $this->post($url, funPayload(['enrolment_id' => $mine->id]))->assertSessionHasNoErrors();
    expect(Funding::query()->count())->toBe(2);
});

it('targets an enrolment only on a line priced per trainee, and not a cancelled one', function () {
    $group = funDossier(['pricing_mode' => 'group']);
    $enrolment = funEnrol($group);
    $this->actingAs(admin(twoFactor: true));

    $this->post("/dossiers/{$group->id}/fundings", funPayload(['enrolment_id' => $enrolment->id]))
        ->assertSessionHasErrors(['enrolment_id' => 'Cette inscription relève d\'une ligne au prix de groupe : visez la ligne.']);

    $dossier = funDossier();
    $cancelled = app(Enrolments::class)->cancel(funEnrol($dossier));
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['enrolment_id' => $cancelled->id]))->assertSessionHasErrors('enrolment_id');
});

// ---------------------------------------------------------------- update, delete

it('changes the state of a funding and deletes it', function () {
    $dossier = funDossier();
    $funding = app(FundingWriter::class)->create($dossier, funPayload());
    $this->actingAs(admin(twoFactor: true));

    $this->put("/dossiers/{$dossier->id}/fundings/{$funding->id}", funPayload(['status' => 'agreed', 'agreed_on' => '2026-11-20', 'amount' => '2 000']))
        ->assertRedirect("/dossiers/{$dossier->id}")->assertSessionHasNoErrors();
    $funding->refresh();
    expect($funding->status)->toBe(FundingStatus::Agreed)
        ->and($funding->agreed_on?->toDateString())->toBe('2026-11-20')
        ->and($funding->amount)->toBe('2000.00');

    $this->delete("/dossiers/{$dossier->id}/fundings/{$funding->id}")->assertRedirect("/dossiers/{$dossier->id}");
    expect(Funding::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('auditable_id', $funding->id)->where('event', 'deleted')->exists())->toBeTrue();
});

it('answers 404 for a funding of another dossier or a malformed id', function () {
    $dossier = funDossier();
    $other = funDossier();
    $theirs = app(FundingWriter::class)->create($other, funPayload());
    $this->actingAs(admin(twoFactor: true));

    $this->put("/dossiers/{$dossier->id}/fundings/{$theirs->id}", funPayload())->assertNotFound();
    $this->delete("/dossiers/{$dossier->id}/fundings/{$theirs->id}")->assertNotFound();
    $this->delete("/dossiers/{$dossier->id}/fundings/pas-un-uuid")->assertNotFound();
    expect(Funding::query()->count())->toBe(1);
});

it('refuses a funding change on a cancelled dossier', function () {
    $dossier = funDossier();
    $funding = app(FundingWriter::class)->create($dossier, funPayload());
    app(DossierTransitions::class)->cancel($dossier, crmItem(ReferenceList::CancelReason, 'Demande du client')->id);
    $this->actingAs(admin(twoFactor: true));

    $this->post("/dossiers/{$dossier->id}/fundings", funPayload())->assertStatus(409);
    $this->put("/dossiers/{$dossier->id}/fundings/{$funding->id}", funPayload(['status' => 'refused']))->assertStatus(409);
    $this->delete("/dossiers/{$dossier->id}/fundings/{$funding->id}")->assertStatus(409);
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page->has('fundings', 1)->where('can.fund', false));
});

// ---------------------------------------------------------------- rights

it('writes fundings with sessions.dossiers.update on the dossier only', function () {
    $dossier = funDossier();
    $this->actingAs(funUser([Permissions::DOSSIERS_VIEW => null]));
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload())->assertForbidden();
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page->has('fundings', 0)->where('can.fund', false)->where('funding_options', null));

    // Update limited to his own dossiers: this one is not.
    $this->actingAs(funUser([Permissions::DOSSIERS_VIEW => null, Permissions::DOSSIERS_UPDATE => AccessScope::Own]));
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload())->assertForbidden();

    // Outside the view scope: 404.
    $this->actingAs(funUser([Permissions::DOSSIERS_VIEW => AccessScope::Own, Permissions::DOSSIERS_UPDATE => AccessScope::Own]));
    $this->post("/dossiers/{$dossier->id}/fundings", funPayload())->assertNotFound();

    expect(Funding::query()->count())->toBe(0);
});

it('links the funder only when the user can open its fiche', function () {
    $dossier = funDossier();
    app(FundingWriter::class)->create($dossier, funPayload());

    $this->actingAs(funUser([Permissions::DOSSIERS_VIEW => null]))->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('fundings.0.funder.label', 'OPCO EP')->where('fundings.0.funder.can_open', false));
    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('fundings.0.funder.can_open', true));
});

it('never shows the fundings on the page of a session', function () {
    $dossier = funDossier();
    $session = funSession();
    funEnrol($dossier, $session);
    app(FundingWriter::class)->create($dossier, funPayload());

    $this->actingAs(funUser([Permissions::SESSIONS_VIEW => null]))->get("/sessions/{$session->id}")->assertOk()
        ->assertDontSee('OPCO EP')->assertDontSee('2 100');
});

it('keeps a funding type in use out of deletion', function () {
    app(FundingWriter::class)->create(funDossier(), funPayload());

    expect(crmItem(ReferenceList::FundingType, 'OPCO')->isInUse())->toBeTrue()
        ->and(crmItem(ReferenceList::FundingType, 'CPF')->isInUse())->toBeFalse();
});

// ---------------------------------------------------------------- targets kept (carry-over of task 5)

it('refuses to delete a line that a funding targets', function () {
    $dossier = funDossier(fee: true);
    $fee = $dossier->lines[1];
    app(FundingWriter::class)->create($dossier, funPayload(['dossier_line_id' => $fee->id]));
    $training = $dossier->lines[0];

    expect(fn () => app(DossierWriter::class)->update($dossier, funKeepLines($dossier, [$training->id])))
        ->toThrow(ValidationException::class, 'La ligne « Déplacement » est visée par un financement');
    expect($dossier->lines()->count())->toBe(2);
});

it('refuses to delete a line whose cancelled enrolment a funding targets: the enrolment is kept', function () {
    $dossier = funDossier(fee: true);
    $enrolment = funEnrol($dossier);
    app(FundingWriter::class)->create($dossier, funPayload(['enrolment_id' => $enrolment->id]));
    app(Enrolments::class)->cancel($enrolment);

    expect(fn () => app(DossierWriter::class)->update($dossier, funKeepLines($dossier, [$dossier->lines[1]->id])))
        ->toThrow(ValidationException::class, 'La ligne « HACCP » a un stagiaire visé par un financement');
    expect(Enrolment::query()->find($enrolment->id))->not->toBeNull();
});

it('never deletes a line with an enrolment, even a cancelled one, nor the enrolment', function () {
    $dossier = funDossier(fee: true);
    $enrolment = funEnrol($dossier);
    app(Enrolments::class)->cancel($enrolment);

    expect(fn () => app(DossierWriter::class)->update($dossier, funKeepLines($dossier, [$dossier->lines[1]->id])))
        ->toThrow(ValidationException::class, DossierWriter::LINE_ENROLLED_PREFIX.'HACCP'.DossierWriter::LINE_ENROLLED_SUFFIX);
    expect(Enrolment::query()->find($enrolment->id))->not->toBeNull()->and($dossier->lines()->count())->toBe(2);
});

it('keeps the pricing mode of a line whose enrolment a funding targets', function () {
    $dossier = funDossier();
    $enrolment = funEnrol($dossier);
    $funding = app(FundingWriter::class)->create($dossier, funPayload(['enrolment_id' => $enrolment->id]));
    $input = funKeepLines($dossier, [$dossier->lines[0]->id]);
    $input['lines'][0]['pricing_mode'] = 'group';

    expect(fn () => app(DossierWriter::class)->update($dossier, $input))
        ->toThrow(ValidationException::class, 'La ligne « HACCP » a un stagiaire visé par un financement : son mode de prix ne change plus.');

    // Defence in depth: a target that no longer qualifies stays editable.
    DB::table('dossier_lines')->where('id', $dossier->lines[0]->id)->update(['pricing_mode' => 'group']);
    app(FundingWriter::class)->update($funding, ['amount' => '1 000']);
    expect($funding->refresh()->amount)->toBe('1000.00')->and($funding->enrolment_id)->toBe($enrolment->id);
});

it('refuses a waitlisted enrolment as target and leaves it out of the options', function () {
    $dossier = funDossier();
    $session = app(SessionWriter::class)->create([
        'training_id' => funTraining()->id, 'kind' => 'inter', 'organisation_id' => null, 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'planned_hours' => '', 'location_kind' => 'remote', 'location_text' => null, 'capacity' => 1, 'responsible_user_id' => null,
        'status' => 'planned', 'notes' => null,
    ]);
    $enrolled = funEnrol($dossier, $session);
    $waiting = funEnrol($dossier, $session);
    expect($waiting->status->value)->toBe('waitlisted');
    $this->actingAs(admin(twoFactor: true));

    $this->post("/dossiers/{$dossier->id}/fundings", funPayload(['target' => 'enrolment', 'enrolment_id' => $waiting->id]))
        ->assertSessionHasErrors(['enrolment_id' => 'Ce stagiaire est sur liste d\'attente : il n\'a pas encore de part du prix.']);
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->has('funding_options.enrolment', 1)->where('funding_options.enrolment.0.value', $enrolled->id));
});

it('requires the line or the trainee chosen as target', function () {
    $dossier = funDossier(fee: true);
    $this->actingAs(admin(twoFactor: true));
    $url = "/dossiers/{$dossier->id}/fundings";

    $this->post($url, funPayload(['target' => 'line']))->assertSessionHasErrors(['dossier_line_id' => 'Choisissez la ligne financée.']);
    $this->post($url, funPayload(['target' => 'enrolment']))->assertSessionHasErrors(['enrolment_id' => 'Choisissez le stagiaire financé.']);
    $this->post($url, funPayload(['target' => 'session']))->assertSessionHasErrors('target');
    expect(Funding::query()->count())->toBe(0);

    // « Le dossier »: an id left over by the form is dropped.
    $this->post($url, funPayload(['target' => 'dossier', 'dossier_line_id' => $dossier->lines[1]->id]))->assertSessionHasNoErrors();
    expect(Funding::query()->sole()->dossier_line_id)->toBeNull();
});

it('shows the funding of a cancelled enrolment as an amount to move', function () {
    $dossier = funDossier();
    $enrolment = funEnrol($dossier);
    funEnrol($dossier);
    app(FundingWriter::class)->create($dossier, funPayload(['enrolment_id' => $enrolment->id, 'amount' => '500']));
    app(Enrolments::class)->cancel($enrolment);

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('balance.parts.1.kind', 'enrolment')
        ->where('balance.parts.1.cancelled', true)
        ->where('balance.parts.1.message', 'Financement à reporter : 500,00 €'));
});

/**
 * The input of the dossier keeping only the lines $keep.
 *
 * @param  list<string>  $keep
 * @return array<string, mixed>
 */
function funKeepLines(Dossier $dossier, array $keep): array
{
    return [
        'company_id' => $dossier->company_id,
        'account_organisation_id' => $dossier->account_organisation_id,
        'account_person_id' => null,
        'das_id' => $dossier->das_id,
        'lines' => $dossier->lines()->get()->filter(fn ($l) => in_array($l->id, $keep, true))->map(fn ($l) => [
            'id' => $l->id, 'kind' => $l->kind, 'training_id' => $l->training_id, 'label' => $l->label, 'quantity' => $l->quantity,
            'unit' => $l->unit, 'unit_price' => $l->unit_price, 'discount_percent' => $l->discount_percent, 'pricing_mode' => $l->pricing_mode,
        ])->values()->all(),
    ];
}

it('takes the contractant as funder even when « paie directement » is ticked: he already gets the invoice of the rest', function () {
    $dossier = l2Dossier();
    $funding = l2Funding($dossier, ['funder_organisation_id' => $dossier->account_organisation_id, 'pays_directly' => true]);

    expect($funding->funder_organisation_id)->toBe($dossier->account_organisation_id)
        ->and($funding->pays_directly)->toBeFalse();
});
