<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceDrafts;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceValidator;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Dossiers\Dossier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/Support/invoices.php';
require_once __DIR__.'/Support/payments.php';

beforeEach(function () {
    plSetUp();
    fakeImportDisk();
    payGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/** A dossier whose contractant is a particulier (or an entreprise), its contract signed on $signed. */
function partDossier(?string $signed, bool $particulier = true): Dossier
{
    $dossier = l2Dossier($particulier ? makePerson('Martin', ['first_name' => 'Paul', 'is_individual' => true]) : null);
    DB::table('dossiers')->where('id', $dossier->id)->update(['convention_signed_on' => $signed]);

    return $dossier->refresh();
}

/** A contractor draft of one free line of 100 € on the dossier. */
function partDraft(Dossier $dossier, User $user): Invoice
{
    $draft = app(InvoiceDrafts::class)->manual($user, $dossier->id, 'contractor');

    return app(InvoiceDrafts::class)->update($draft, ['lines' => [[
        'label' => 'Frais de dossier', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '100,00', 'vat_rate' => '0',
    ]]]);
}

function partUser(): User
{
    return fiManager([Permissions::VALIDATE => null]);
}

it('validates no invoice to a particulier during the withdrawal delay', function () {
    $user = partUser();
    $dossier = partDossier('2026-11-10');
    $draft = partDraft($dossier, $user);

    expect(fn () => app(InvoiceValidator::class)->validate($draft, $user))
        ->toThrow(UserFacingHttpException::class, 'Délai de rétractation du particulier : aucune facture avant le 25/11/2026.');

    // The delay runs through 24/11 (14 days from the day after the signature).
    $this->travelTo(CarbonImmutable::parse('2026-11-24 23:00', 'Europe/Paris'));
    expect(fn () => app(InvoiceValidator::class)->validate($draft->refresh(), $user))->toThrow(UserFacingHttpException::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 09:00', 'Europe/Paris'));
    expect(app(InvoiceValidator::class)->validate($draft->refresh(), $user)->status)->toBe(InvoiceStatus::Validated);
});

it('validates without a signature date, with a warning on the draft', function () {
    $user = partUser();
    $dossier = partDossier(null);
    $draft = partDraft($dossier, $user);

    $this->actingAs($user)->get('/finance/invoices/'.$draft->id)->assertInertia(fn (Assert $p) => $p
        ->where('warnings.0', 'Date de signature du contrat inconnue : vérifiez le délai de rétractation du particulier.'));
    expect(app(InvoiceValidator::class)->validate($draft->refresh(), $user)->status)->toBe(InvoiceStatus::Validated);
});

it('leaves an entreprise contractant alone', function () {
    $user = partUser();
    $draft = partDraft(partDossier('2026-11-16', particulier: false), $user);

    expect(app(InvoiceValidator::class)->validate($draft, $user)->status)->toBe(InvoiceStatus::Validated);
    $this->actingAs($user)->get('/finance/invoices/'.$draft->id)->assertInertia(fn (Assert $p) => $p->where('warnings', []));
});

it('caps the first instalment of a particulier at 30 %, after the withdrawal delay', function () {
    $dossier = partDossier('2026-11-10');
    $session = plSession('2026-12-01', '2027-03-31');
    l2Enrol($dossier, $session, Person::query()->findOrFail($dossier->account_person_id));
    $errors = function (array $schedule) use ($dossier): array {
        try {
            app(BillingPlans::class)->save($dossier->id, ['rhythm' => 'schedule', 'basis' => 'planned', 'schedule' => $schedule]);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };

    expect($errors([['due_on' => '2026-12-01', 'percent' => 40], ['due_on' => '2027-01-01', 'percent' => 60]])['schedule.0.percent'][0] ?? null)
        ->toBe('Un particulier ne peut pas verser plus de 30 % du prix avant le début du solde échelonné.')
        ->and($errors([['due_on' => '2026-12-01', 'percent' => 30], ['due_on' => '2027-01-01', 'percent' => 70]]))->toBe([]);

    // The delay ends after the first day of the training: no instalment before it.
    DB::table('dossiers')->where('id', $dossier->id)->update(['convention_signed_on' => '2026-11-25']);
    expect($errors([['due_on' => '2026-12-01', 'percent' => 30], ['due_on' => '2027-01-01', 'percent' => 70]])['schedule.0.due_on'][0] ?? null)
        ->toBe('Délai de rétractation du particulier : aucune échéance avant le 10/12/2026.');
});

it('leaves the schedule of an entreprise alone', function () {
    $dossier = partDossier('2026-11-10', particulier: false);
    $session = plSession('2026-12-01', '2027-03-31');
    l2Enrol($dossier, $session, makePerson('Martin', ['first_name' => 'Léon']));

    $plan = app(BillingPlans::class)->save($dossier->id, ['rhythm' => 'schedule', 'basis' => 'planned', 'schedule' => [['due_on' => '2026-12-01', 'percent' => 50], ['due_on' => '2027-01-01', 'percent' => 50]]]);
    expect($plan['rhythm'])->toBe('schedule');
});
