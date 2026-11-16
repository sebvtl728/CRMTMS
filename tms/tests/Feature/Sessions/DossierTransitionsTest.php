<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
});

function dtDossier(): Dossier
{
    $training = Training::query()->create(['title' => 'HACCP', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Parc'])->id,
        'das_id' => $training->das_id,
        'lines' => [['kind' => 'training', 'training_id' => $training->id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '900']],
    ]);
}

function dtDay(string $day): CarbonImmutable
{
    return CarbonImmutable::parse($day, 'Europe/Paris');
}

function dtReason(): string
{
    return crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id;
}

it('goes through the steps in order, recording each date', function () {
    $dossier = dtDossier();
    $t = app(DossierTransitions::class);

    expect(fn () => $t->signConvention($dossier, dtDay('2026-11-20')))->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(409))
        ->and(fn () => $t->sendConvocations($dossier, dtDay('2026-11-20')))->toThrow(HttpException::class);

    $t->sendConvention($dossier, dtDay('2026-11-18'));
    expect($dossier->status)->toBe(DossierStatus::ConventionSent)->and($dossier->convention_sent_on->toDateString())->toBe('2026-11-18');

    // A step before the previous one is refused.
    expect(fn () => $t->signConvention($dossier, dtDay('2026-11-17')))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['on'])->toBe(['La date ne peut pas précéder celle de l\'étape précédente (18/11/2026).']));

    $t->signConvention($dossier, dtDay('2026-11-19'));
    $t->sendConvocations($dossier, dtDay('2026-11-20'));
    $dossier->refresh();
    expect($dossier->status)->toBe(DossierStatus::ConvocationsSent)
        ->and($dossier->convention_signed_on->toDateString())->toBe('2026-11-19')
        ->and($dossier->convocations_sent_on->toDateString())->toBe('2026-11-20');

    // Sent twice: 409.
    expect(fn () => $t->sendConvention($dossier, dtDay('2026-11-20')))->toThrow(HttpException::class);

    // Journaled: the status change with its old value.
    $log = AuditLog::query()->where('auditable_id', $dossier->id)->where('event', 'updated')->orderBy('id')->get()->last();
    expect($log->old_values['status'] ?? null)->toBe('convention_signed')->and($log->new_values['status'] ?? null)->toBe('convocations_sent');
});

it('marks running then done by hand, no longer computed afterwards', function () {
    $dossier = dtDossier();
    $t = app(DossierTransitions::class);

    $t->markRunning($dossier);
    expect($dossier->status)->toBe(DossierStatus::Running);
    expect(fn () => $t->markRunning($dossier))->toThrow(HttpException::class);
    $t->markDone($dossier);
    expect($dossier->fresh()->status)->toBe(DossierStatus::Done)
        ->and(fn () => $t->markDone($dossier))->toThrow(HttpException::class);

    // Set by hand: the session dates no longer change it.
    expect(DossierStatus::Done->effective(dtDay('2027-01-04'), dtDay('2027-01-05'), dtDay('2026-11-20')))->toBe(DossierStatus::Done);
});

it('computes « En cours » and « Réalisé » from the session dates while a step before them is stored', function () {
    $start = dtDay('2026-12-07');
    $end = dtDay('2026-12-09');

    expect(DossierStatus::ConventionSigned->effective($start, $end, dtDay('2026-12-06')))->toBe(DossierStatus::ConventionSigned)
        ->and(DossierStatus::ConventionSigned->effective($start, $end, dtDay('2026-12-07')))->toBe(DossierStatus::Running)
        ->and(DossierStatus::Created->effective($start, $end, dtDay('2026-12-09')))->toBe(DossierStatus::Running)
        ->and(DossierStatus::ConvocationsSent->effective($start, $end, dtDay('2026-12-10')))->toBe(DossierStatus::Done)
        ->and(DossierStatus::Cancelled->effective($start, $end, dtDay('2026-12-10')))->toBe(DossierStatus::Cancelled)
        // No session yet: the stored state.
        ->and(DossierStatus::Created->effective(null, null, dtDay('2026-12-10')))->toBe(DossierStatus::Created);
});

it('cancels with a reason only, refuses after « Réalisé » and a second time', function () {
    $dossier = dtDossier();
    $this->actingAs(commercial());

    $this->post("/dossiers/{$dossier->id}/cancel", [])->assertSessionHasErrors(['cancelled_reason_id' => 'Choisissez le motif d\'annulation.']);
    $this->post("/dossiers/{$dossier->id}/cancel", ['cancelled_reason_id' => crmItem(ReferenceList::BpfCategory, 'Particuliers')->id])
        ->assertSessionHasErrors(['cancelled_reason_id' => 'Motif d\'annulation inconnu.']);

    $this->post("/dossiers/{$dossier->id}/cancel", ['cancelled_reason_id' => dtReason()])->assertRedirect("/dossiers/{$dossier->id}");
    $dossier->refresh();
    expect($dossier->status)->toBe(DossierStatus::Cancelled)
        ->and($dossier->cancelled_reason_id)->toBe(dtReason())
        ->and($dossier->cancelled_on->toDateString())->toBe('2026-11-20')
        ->and(crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->isInUse())->toBeTrue();

    // Already cancelled: 409 even without a reason; no more steps nor changes.
    $this->post("/dossiers/{$dossier->id}/cancel", [])->assertStatus(409);
    $this->post("/dossiers/{$dossier->id}/steps/convention-sent", ['on' => '2026-11-20'])->assertStatus(409);
    $this->put("/dossiers/{$dossier->id}", [])->assertStatus(409);
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dossier.status_label', 'Annulé')->where('dossier.cancel_reason', 'Effectif insuffisant')
        ->where('can.update', false)->where('can.cancel', false));

    // After « Réalisé »: refused, in French, whatever the reason.
    $done = dtDossier();
    app(DossierTransitions::class)->markDone($done);
    $this->post("/dossiers/{$done->id}/cancel", [])->assertStatus(409);
    expect(fn () => app(DossierTransitions::class)->cancel($done, dtReason()))
        ->toThrow(fn (HttpException $e) => expect($e->getMessage())->toBe('Un dossier réalisé ne peut plus être annulé.'));
    expect($done->fresh()->status)->toBe(DossierStatus::Done);
});

it('changes the state from the page with the update right and its scope', function () {
    $dossier = dtDossier();

    $this->actingAs(direction(twoFactor: true))->post("/dossiers/{$dossier->id}/steps/convention-sent", ['on' => '2026-11-19'])->assertForbidden();

    $this->actingAs(commercial())->post("/dossiers/{$dossier->id}/steps/convention-sent", ['on' => '2026-11-19'])->assertRedirect("/dossiers/{$dossier->id}");
    expect($dossier->fresh()->convention_sent_on->toDateString())->toBe('2026-11-19');

    $this->post("/dossiers/{$dossier->id}/steps/convention-signed", ['on' => 'hier'])->assertSessionHasErrors(['on' => 'Date invalide.']);
    // Without a date: today in Paris.
    $this->post("/dossiers/{$dossier->id}/steps/convention-signed")->assertRedirect();
    expect($dossier->fresh()->convention_signed_on->toDateString())->toBe('2026-11-20');

    $this->post("/dossiers/{$dossier->id}/steps/unknown")->assertNotFound();
    $this->post("/dossiers/{$dossier->id}/steps/running")->assertRedirect();
    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $page) => $page
        ->where('dossier.status_label', 'En cours')->where('can.steps.running', false)->where('can.steps.done', true));
});
