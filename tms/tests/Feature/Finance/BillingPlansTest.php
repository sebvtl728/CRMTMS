<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlan;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Public\DossierStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * A dossier of 1 000 € with one trainee on a session from 1 to 30 September 2026.
 */
function fpDossier(): Dossier
{
    $dossier = l2Dossier();
    l2Enrol($dossier, plSession('2026-09-01', '2026-09-30'), makePerson('Durand'));

    return $dossier;
}

/**
 * Who reads and manages the invoicing of every dossier (plus $extra rights).
 *
 * @param  array<string, AccessScope|null>  $extra
 */
function fpManager(array $extra = []): User
{
    return l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null, Permissions::MANAGE => null, ...$extra]);
}

function fpPlans(): BillingPlans
{
    return app(BillingPlans::class);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fpCompanyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'CAP FORMATION', 'legal_form' => 'scop', 'scop_underlying_form' => 'sarl', 'capital_amount' => null,
        'capital_variable' => true, 'siret' => '73282932000074', 'rcs_city' => 'Lyon', 'vat_number' => null,
        'vat_regime' => 'exempt', 'nda_number' => '84691234569', 'address_country' => 'FR',
    ], $overrides);
}

// ---------------------------------------------------------------- rights

it('declares the invoicing rights, scoped, with their default matrix', function () {
    $all = [Permissions::VIEW, Permissions::MANAGE, Permissions::VALIDATE];
    expect(CorePermissions::all())->toContain(...$all);
    foreach ($all as $permission) {
        expect(CorePermissions::isScoped($permission))->toBeTrue($permission)
            ->and(CorePermissions::label($permission)['group'] ?? null)->toBe('Factures');
    }

    $finance = fn (Role $role) => array_values(array_intersect(CorePermissions::defaultsFor($role), $all));
    expect($finance(Role::AdminOrganisme))->toBe($all)
        ->and($finance(Role::Direction))->toBe($all)
        ->and($finance(Role::Commercial))->toBe([Permissions::VIEW, Permissions::MANAGE])
        ->and($finance(Role::ChargeRelations))->toBe([Permissions::VIEW, Permissions::MANAGE])
        ->and($finance(Role::Assistant))->toBe([Permissions::VIEW, Permissions::MANAGE])
        ->and($finance(Role::ResponsableDas))->toBe([])
        ->and($finance(Role::Formateur))->toBe([]);
});

// ---------------------------------------------------------------- plan: defaults and validation

it('gives a dossier without settings the default plan, following the organisme absences rule', function () {
    $dossier = fpDossier();

    expect(fpPlans()->for($dossier->id))->toBe([
        'rhythm' => 'end', 'basis' => 'realised', 'unjustified_absences' => null, 'absences' => 'not_billed', 'schedule' => [], 'locked' => false,
    ])->and(BillingPlan::query()->count())->toBe(0);

    FinanceSettings::saveAbsences('bill_contractor');
    expect(fpPlans()->for($dossier->id)['absences'])->toBe('bill_contractor');
});

it('saves a monthly plan, and a schedule sorted by date', function () {
    $dossier = fpDossier();

    fpPlans()->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised', 'unjustified_absences' => 'bill_contractor', 'schedule' => [['due_on' => '2026-09-01', 'percent' => 100]]]);
    expect(fpPlans()->for($dossier->id))->toBe([
        'rhythm' => 'monthly', 'basis' => 'realised', 'unjustified_absences' => 'bill_contractor', 'absences' => 'bill_contractor', 'schedule' => [], 'locked' => false,
    ]);

    fpPlans()->save($dossier->id, ['rhythm' => 'schedule', 'basis' => 'realised', 'unjustified_absences' => '', 'schedule' => [
        ['due_on' => '2026-10-15', 'percent' => '70'], ['due_on' => '2026-09-01', 'percent' => 30],
    ]]);
    expect(fpPlans()->for($dossier->id))->toBe([
        'rhythm' => 'schedule', 'basis' => 'realised', 'unjustified_absences' => null, 'absences' => 'not_billed',
        'schedule' => [['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-10-15', 'percent' => 70]], 'locked' => false,
    ])->and(BillingPlan::query()->count())->toBe(1)
        // Journaled like any business record.
        ->and(DB::table('audit_logs')->where('auditable_type', 'billing_plan')->count())->toBe(2);
});

/**
 * The validation messages of saving $data on the dossier.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function fpErrors(Dossier $dossier, array $data): array
{
    try {
        fpPlans()->save($dossier->id, $data);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('refuses an invalid plan with French messages', function () {
    $dossier = fpDossier();

    expect(fpErrors($dossier, ['rhythm' => 'weekly', 'basis' => 'guess', 'unjustified_absences' => 'trainee']))->toBe([
        'rhythm' => ['Choisissez le rythme de facturation : à la fin, mensuel ou échéancier.'],
        'basis' => ['Choisissez la base de facturation : au réalisé ou au prévu.'],
        'unjustified_absences' => ['Choisissez « Non facturées », « Facturées au contractant » ou le réglage de l\'organisme.'],
    ])->and(fpErrors($dossier, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => []]))->toBe([
        'schedule' => ['Indiquez au moins une échéance.'],
    ])->and(fpErrors($dossier, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-09-10', 'percent' => 0], ['due_on' => '2026-09-20', 'percent' => '12.5'], ['due_on' => '', 'percent' => 101],
    ]]))->toEqual([
        'schedule.0.percent' => ['Chaque pourcentage est un nombre entier de 1 à 100.'],
        'schedule.1.percent' => ['Chaque pourcentage est un nombre entier de 1 à 100.'],
        'schedule.2.due_on' => ['Indiquez la date de chaque échéance.'],
        'schedule.2.percent' => ['Chaque pourcentage est un nombre entier de 1 à 100.'],
    ])->and(fpErrors($dossier, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-09-10', 'percent' => 30], ['due_on' => '2026-10-10', 'percent' => 60],
    ]]))->toBe([
        'schedule' => ['La somme des pourcentages de l\'échéancier doit faire 100 % (actuellement 90 %).'],
    ])->and(fpErrors($dossier, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-08-31', 'percent' => 30], ['due_on' => '2026-10-10', 'percent' => 70],
    ]]))->toBe([
        'schedule.0.due_on' => ['Une échéance ne peut pas précéder le début de la formation (01/09/2026).'],
    ])->and(fpErrors($dossier, ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => [
        ['due_on' => '2026-10-10', 'percent' => 30], ['due_on' => '2026-10-10', 'percent' => 70],
    ]]))->toBe([
        'schedule.1.due_on' => ['Deux échéances ne peuvent pas tomber le même jour.'],
    ])
        // The monthly rhythm is billed on the realised hours only.
        ->and(fpErrors($dossier, ['rhythm' => 'monthly', 'basis' => 'planned']))->toBe([
            'basis' => ['Le rythme mensuel se facture au réalisé.'],
        ])->and(BillingPlan::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- lock

it('locks the rhythm, the basis and the schedule after the first validated invoice', function () {
    $dossier = fpDossier();
    fpPlans()->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised']);
    fpPlans()->lock($dossier->id);
    fpPlans()->lock($dossier->id); // idempotent

    expect(fpPlans()->for($dossier->id)['locked'])->toBeTrue()
        ->and(fpPlans()->isLocked($dossier->id))->toBeTrue()
        ->and(fn () => fpPlans()->save($dossier->id, ['rhythm' => 'end', 'basis' => 'realised']))
        ->toThrow(UserFacingHttpException::class, BillingPlans::LOCKED)
        ->and(fn () => fpPlans()->save($dossier->id, ['rhythm' => 'end', 'basis' => 'planned']))
        ->toThrow(UserFacingHttpException::class, BillingPlans::LOCKED);

    // The absences rule stays editable: the due amount is computed again.
    fpPlans()->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised', 'unjustified_absences' => 'bill_contractor']);
    expect(fpPlans()->for($dossier->id))->toMatchArray(['rhythm' => 'monthly', 'unjustified_absences' => 'bill_contractor', 'locked' => true]);

    // A dossier without a plan yet: locking creates the default one.
    $other = fpDossier();
    fpPlans()->lock($other->id);
    expect(fpPlans()->for($other->id))->toMatchArray(['rhythm' => 'end', 'locked' => true]);
});

it('refuses to change who pays directly, or to trash the dossier, once a plan is locked', function () {
    $dossier = l2Dossier();
    $funding = l2Funding($dossier, ['status' => 'agreed', 'agreement_number' => 'PEC-1', 'agreed_on' => '2026-09-01']);
    $writer = app(FundingWriter::class);

    // Not locked: free to change.
    $writer->update($funding, ['pays_directly' => true]);
    expect($funding->fresh()->pays_directly)->toBeTrue();

    fpPlans()->lock($dossier->id);
    try {
        $writer->update($funding, ['pays_directly' => false]);
        $this->fail('The change of pays_directly should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['pays_directly' => [BillingPlans::HAS_INVOICES]]);
    }
    // Anything else still changes, and resending the same value is no change.
    $writer->update($funding, ['amount' => '700', 'pays_directly' => true]);
    expect($funding->fresh())->amount->toBe('700.00')->pays_directly->toBeTrue();

    expect(fn () => app(DossierWriter::class)->trash($dossier))->toThrow(UserFacingHttpException::class, BillingPlans::HAS_INVOICES)
        ->and($dossier->fresh()->trashed())->toBeFalse();

    $free = l2Dossier();
    app(DossierWriter::class)->trash($free);
    expect($free->fresh()->trashed())->toBeTrue();
});

it('marks a dossier in the trash invoiced and back', function () {
    $dossier = l2Dossier();
    app(DossierTransitions::class)->markDone($dossier);
    app(DossierWriter::class)->trash($dossier);

    app(DossierStates::class)->markInvoiced($dossier->id);
    expect(Dossier::withTrashed()->findOrFail($dossier->id)->status)->toBe(DossierStatus::Invoiced);
    app(DossierStates::class)->unmarkInvoiced($dossier->id);
    expect(Dossier::withTrashed()->findOrFail($dossier->id)->status)->toBe(DossierStatus::Done);
});

// ---------------------------------------------------------------- HTTP: rights and scopes

it('saves the plan of a dossier through the page, for who manages its invoicing', function () {
    $dossier = fpDossier();

    $this->actingAs(fpManager())
        ->put("/finance/dossiers/{$dossier->id}/plan", ['rhythm' => 'schedule', 'basis' => 'realised', 'unjustified_absences' => null, 'schedule' => [
            ['due_on' => '2026-09-01', 'percent' => 40], ['due_on' => '2026-09-30', 'percent' => 60],
        ]])
        ->assertRedirect()->assertSessionHas('status', 'Réglages de facturation enregistrés.');
    expect(fpPlans()->for($dossier->id)['schedule'])->toHaveCount(2);

    $this->actingAs(fpManager())->put("/finance/dossiers/{$dossier->id}/plan", ['rhythm' => 'schedule', 'basis' => 'realised', 'schedule' => []])
        ->assertSessionHasErrors(['schedule' => 'Indiquez au moins une échéance.']);

    fpPlans()->lock($dossier->id);
    $this->actingAs(fpManager())->put("/finance/dossiers/{$dossier->id}/plan", ['rhythm' => 'end', 'basis' => 'realised'])
        ->assertStatus(409)->assertSee(BillingPlans::LOCKED);
});

it('answers 403 without the right to manage, 404 out of the scope of the dossier', function () {
    $dossier = fpDossier();
    $plan = ['rhythm' => 'monthly', 'basis' => 'realised'];

    $this->put("/finance/dossiers/{$dossier->id}/plan", $plan)->assertRedirect('/login');
    $this->actingAs(l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]))
        ->put("/finance/dossiers/{$dossier->id}/plan", $plan)->assertForbidden();
    // Seen with the « own » view scope, but managed on « own » too: not his dossier.
    $this->actingAs(fpManager([Permissions::VIEW => AccessScope::Own, Permissions::MANAGE => AccessScope::Own]))
        ->put("/finance/dossiers/{$dossier->id}/plan", $plan)->assertNotFound();
    // Seen by all, managed on « own » only: forbidden.
    $this->actingAs(fpManager([Permissions::MANAGE => AccessScope::Own]))
        ->put("/finance/dossiers/{$dossier->id}/plan", $plan)->assertForbidden();
    $this->actingAs(fpManager())->put('/finance/dossiers/not-a-uuid/plan', $plan)->assertNotFound();
    $this->actingAs(fpManager())->put('/finance/dossiers/'.Str::uuid7().'/plan', $plan)->assertNotFound();
    expect(BillingPlan::query()->count())->toBe(0);

    // His own dossier (responsible for it).
    $mine = fpManager([Permissions::VIEW => AccessScope::Own, Permissions::MANAGE => AccessScope::Own]);
    $dossier->forceFill(['responsible_user_id' => $mine->id])->save();
    $this->actingAs($mine)->put("/finance/dossiers/{$dossier->id}/plan", $plan)->assertRedirect();
    expect(fpPlans()->for($dossier->id)['rhythm'])->toBe('monthly');

    // A dossier in the trash: no longer reached.
    $trashed = l2Dossier();
    app(DossierWriter::class)->trash($trashed);
    $this->actingAs(fpManager([SessionsPermissions::DOSSIERS_DELETE => null]))->put("/finance/dossiers/{$trashed->id}/plan", $plan)->assertNotFound();
});

it('shows the billing block on the dossier page to who reads its invoicing', function () {
    $dossier = fpDossier();
    fpPlans()->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised']);

    $this->actingAs(fpManager())->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->has('blocks', 1)
        ->where('blocks.0.key', 'finance.billing')
        ->where('blocks.0.title', 'Facturation')
        ->where('blocks.0.component', 'finance.billing')
        ->where('blocks.0.rows', [])
        ->where('blocks.0.props.plan.rhythm', 'monthly')
        ->where('blocks.0.props.organisme_absences', 'not_billed')
        ->where('blocks.0.props.starts_on', '2026-09-01')
        ->where('blocks.0.props.can_manage', true)
        ->where('blocks.0.props.update_url', "/finance/dossiers/{$dossier->id}/plan"));

    $reader = l2User([SessionsPermissions::DOSSIERS_VIEW => null, Permissions::VIEW => null]);
    $this->actingAs($reader)->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('blocks.0.props.can_manage', false));

    $this->actingAs(l2User([SessionsPermissions::DOSSIERS_VIEW => null]))->get("/dossiers/{$dossier->id}")
        ->assertInertia(fn (Assert $page) => $page->has('blocks', 0));
    $this->actingAs(fpManager([Permissions::VIEW => AccessScope::Own]))->get("/dossiers/{$dossier->id}")
        ->assertInertia(fn (Assert $page) => $page->has('blocks', 0));
});

// ---------------------------------------------------------------- Finance › Réglages

it('edits the organisme default for unjustified absences', function () {
    $admin = l2User([CorePermissions::SETTINGS_MANAGE => null]);

    $this->actingAs($admin)->get('/finance/settings')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('finance/settings')->where('settings.unjustified_absences', 'not_billed'));
    $this->actingAs($admin)->put('/finance/settings', ['unjustified_absences' => 'bill_contractor'])
        ->assertRedirect('/finance/settings')->assertSessionHas('status', 'Réglages de facturation enregistrés.');
    expect(FinanceSettings::absencesDefault())->toBe('bill_contractor')
        ->and(DB::table('audit_logs')->where('auditable_type', 'finance_settings')->exists())->toBeTrue();

    $this->actingAs($admin)->put('/finance/settings', ['unjustified_absences' => 'everybody'])
        ->assertSessionHasErrors(['unjustified_absences' => 'Choisissez « Non facturées » ou « Facturées au contractant ».']);

    $this->actingAs(fpManager())->get('/finance/settings')->assertForbidden();
    $this->actingAs(fpManager())->put('/finance/settings', ['unjustified_absences' => 'not_billed'])->assertForbidden();
});

// ---------------------------------------------------------------- companies

it('gives every company invoicing defaults, edited in the company form', function () {
    $company = Company::factory()->create();
    expect($company->fresh())
        ->invoice_prefix->toBe('F')->credit_note_prefix->toBe('AV')->payment_terms_days->toBe(30)->invoice_footer->toBeNull();

    $admin = l2User([CorePermissions::COMPANIES_MANAGE => null]);
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload([
        'invoice_prefix' => ' fc ', 'credit_note_prefix' => 'avc', 'payment_terms_days' => '45', 'invoice_footer' => 'Pénalités : 3 fois le taux légal. Indemnité forfaitaire de 40 €.',
    ]))->assertSessionDoesntHaveErrors();
    expect($company->fresh())->invoice_prefix->toBe('FC')->credit_note_prefix->toBe('AVC')->payment_terms_days->toBe(45)
        ->invoice_footer->toBe('Pénalités : 3 fois le taux légal. Indemnité forfaitaire de 40 €.');

    $this->actingAs($admin)->get("/admin/companies/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('company.invoice_prefix', 'FC')->where('company.credit_note_prefix', 'AVC')->where('company.payment_terms_days', 45));

    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload([
        'invoice_prefix' => 'F-1', 'credit_note_prefix' => 'F-1', 'payment_terms_days' => '61', 'invoice_footer' => str_repeat('a', 2001),
    ]))->assertSessionHasErrors([
        'invoice_prefix' => 'Le préfixe ne peut contenir que des lettres majuscules et des chiffres (10 caractères au plus).',
        'credit_note_prefix' => 'Le préfixe des avoirs doit être différent de celui des factures.',
        'payment_terms_days' => 'Le délai de paiement doit être compris entre 0 et 60 jours.',
        'invoice_footer' => 'Les mentions de pied de facture ne peuvent pas dépasser 2 000 caractères.',
    ]);
    // A form without the fields keeps them.
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload())->assertSessionDoesntHaveErrors();
    expect($company->fresh()->invoice_prefix)->toBe('FC');
});

it('refuses an invoice or credit-note prefix that already numbered the other kind of piece of the company', function () {
    $dossier = fpDossier();
    $company = Company::query()->findOrFail($dossier->company_id);
    $piece = fn (string $kind, string $number, ?string $corrects = null) => tap((string) Str::uuid7(), fn (string $id) => DB::table('invoices')->insert([
        'id' => $id, 'tenant_id' => tenant()->id, 'company_id' => $company->id, 'kind' => $kind, 'number' => $number, 'status' => 'validated',
        'dossier_id' => $dossier->id, 'recipient_type' => 'organisation', 'recipient_id' => $dossier->account_organisation_id, 'issued_on' => '2026-11-17',
        'rhythm' => 'end', 'basis' => 'realised', 'total_ht_cents' => $kind === 'invoice' ? 1000 : -1000, 'corrected_invoice_id' => $corrects,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $piece('credit_note', 'AV-2026-00001', $piece('invoice', 'F-2026-00001'));
    $admin = l2User([CorePermissions::COMPANIES_MANAGE => null]);
    $used = 'Ce préfixe a déjà servi pour un autre type de pièce.';

    // Swapped: each prefix numbered the other kind.
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload(['invoice_prefix' => 'AV', 'credit_note_prefix' => 'F']))
        ->assertSessionHasErrors(['invoice_prefix' => $used, 'credit_note_prefix' => $used]);
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload(['invoice_prefix' => 'AV', 'credit_note_prefix' => 'AVR']))
        ->assertSessionHasErrors(['invoice_prefix' => $used])->assertSessionDoesntHaveErrors('credit_note_prefix');
    expect($company->fresh()->only(['invoice_prefix', 'credit_note_prefix']))->toBe(['invoice_prefix' => 'F', 'credit_note_prefix' => 'AV']);

    // New prefixes, then back to the former ones of the same kind: accepted.
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload(['invoice_prefix' => 'FAC', 'credit_note_prefix' => 'AVR']))->assertSessionDoesntHaveErrors();
    $this->actingAs($admin)->put("/admin/companies/{$company->id}", fpCompanyPayload(['invoice_prefix' => 'F', 'credit_note_prefix' => 'AV']))->assertSessionDoesntHaveErrors();

    // Another company numbers apart: « AV » may be its invoices' prefix.
    $other = Company::factory()->create();
    $this->actingAs($admin)->put("/admin/companies/{$other->id}", fpCompanyPayload(['invoice_prefix' => 'AV', 'credit_note_prefix' => 'F']))->assertSessionDoesntHaveErrors();
});

// ---------------------------------------------------------------- RLS

it('keeps plans and settings inside their organisme', function () {
    $dossier = fpDossier();
    fpPlans()->save($dossier->id, ['rhythm' => 'monthly', 'basis' => 'realised']);
    FinanceSettings::saveAbsences('bill_contractor');

    $foreign = Tenant::factory()->create();
    app(TenantContext::class)->run($foreign, function () use ($dossier) {
        expect(BillingPlan::query()->count())->toBe(0)
            ->and(FinanceSettings::query()->count())->toBe(0)
            ->and(FinanceSettings::absencesDefault())->toBe('not_billed')
            // The dossier itself is out of reach: no plan is read nor written there.
            ->and(fpPlans()->for($dossier->id)['rhythm'])->toBe('end');
    });
});
