<?php

use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\CandidatureWriter;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('moves forward one stage or more', function () {
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::PositioningSent, admin());
    expect($c->fresh()->status)->toBe(CandidatureStatus::PositioningSent);

    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Admitted, admin());
    expect($c->fresh()->status)->toBe(CandidatureStatus::Admitted);
});

it('admits only a candidate with a training', function () {
    $c = app(CandidatureWriter::class)->create(['person_id' => adPerson()->id, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id], admin());

    try {
        app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['training_id'][0])->toBe('Choisissez la formation visée avant d\'admettre le candidat.');
    }
});

it('needs a reason to reject, and keeps it', function () {
    $c = adCandidature();
    try {
        app(CandidatureTransitions::class)->move($c, CandidatureStatus::Rejected, admin());
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['decision_reason_id'][0])->toBe('Indiquez le motif.');
    }

    $reason = crmItem(ReferenceList::CandidatureRefusalReason, 'Niveau insuffisant');
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Rejected, admin(), $reason->id);
    expect($c->fresh()->status)->toBe(CandidatureStatus::Rejected)
        ->and($c->fresh()->decision_reason_id)->toBe($reason->id);

    // Back on the path: the reason goes.
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin());
    expect($c->fresh()->decision_reason_id)->toBeNull();
});

it('goes back on the path while no dossier exists', function () {
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin());

    expect($c->fresh()->status)->toBe(CandidatureStatus::Interview);
});

it('refuses to withdraw an admitted candidate or to stay on the same stage', function () {
    $c = adCandidature();
    expect(fn () => app(CandidatureTransitions::class)->move($c, CandidatureStatus::Identified, admin()))->toThrow(HttpException::class);

    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
    expect(fn () => app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Withdrawn, admin()))->toThrow(HttpException::class);
});

it('journals the change of stage and moves through the route', function () {
    $c = adCandidature();
    $this->actingAs(commercial())->post("/admissions/candidatures/{$c->id}/status", ['status' => 'positioning_sent'])->assertRedirect();

    expect($c->fresh()->status)->toBe(CandidatureStatus::PositioningSent)
        ->and(AuditLog::query()->where('auditable_id', $c->id)->where('event', 'updated')->latest('id')->first()?->new_values['status'] ?? null)->toBe('positioning_sent');

    $this->post("/admissions/candidatures/{$c->id}/status", ['status' => 'nope'])->assertSessionHasErrors('status');
});
