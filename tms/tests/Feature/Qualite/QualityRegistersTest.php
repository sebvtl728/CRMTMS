<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Qualite\Public\Measures;
use App\Modules\Qualite\Registers\Complaint;
use App\Modules\Qualite\Registers\ImprovementAction;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

function qrItem(ReferenceList $list, string $label): string
{
    return ReferenceItem::query()->where('list', $list)->where('label', $label)->firstOrFail()->id;
}

/** @param array<string, mixed> $overrides */
function qrComplaint(array $overrides = []): array
{
    return [
        'received_on' => '2026-11-10', 'channel_id' => qrItem(ReferenceList::ComplaintChannel, 'E-mail'),
        'complainant' => 'Léa Durand (stagiaire)', 'subject' => 'Salle trop froide', 'description' => 'Chauffage en panne le 2e jour.',
        'status' => 'open', ...$overrides,
    ];
}

it('seeds the channels of a complaint and the sources of an improvement action', function () {
    expect(ReferenceItem::query()->where('list', ReferenceList::ComplaintChannel)->orderBy('position')->pluck('label')->all())
        ->toBe(['E-mail', 'Téléphone', 'Courrier', 'Questionnaire', 'Oral', 'Autre'])
        ->and(ReferenceItem::query()->where('list', ReferenceList::ImprovementSource)->orderBy('position')->pluck('label')->all())
        ->toBe(['Réclamation', 'Questionnaire', 'Audit', 'Interne', 'Veille']);
});

it('records a complaint, closes it only with its answer, and keeps it from a reader', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin)->post('/qualite/reclamations', qrComplaint())->assertRedirect()->assertSessionHas('status', 'Réclamation enregistrée.');
    $complaint = Complaint::query()->sole();
    expect($complaint->subject)->toBe('Salle trop froide')->and($complaint->created_by)->toBe($admin->id);

    $this->put("/qualite/reclamations/{$complaint->id}", qrComplaint(['status' => 'closed']))
        ->assertSessionHasErrors(['response' => 'Indiquez la réponse apportée avant de clore la réclamation.']);
    $this->put("/qualite/reclamations/{$complaint->id}", qrComplaint(['status' => 'closed', 'response' => 'Salle changée.', 'responded_on' => '2026-11-05']))
        ->assertSessionHasErrors(['responded_on' => 'La réponse ne peut pas précéder la réception.']);
    $this->put("/qualite/reclamations/{$complaint->id}", qrComplaint(['status' => 'closed', 'response' => 'Salle changée.', 'responded_on' => '2026-11-12']))
        ->assertSessionHasNoErrors();
    expect($complaint->fresh()->status)->toBe('closed');

    $this->get('/qualite/reclamations')->assertInertia(fn (Assert $p) => $p
        ->component('qualite/registers/complaints')->where('complaints.0.subject', 'Salle trop froide')->where('can.manage', true));
    $this->actingAs(commercial())->get('/qualite/reclamations')->assertForbidden();
});

it('records an improvement action from a complaint, done only with its date and result', function () {
    $this->actingAs(admin(twoFactor: true))->post('/qualite/reclamations', qrComplaint());
    $complaint = Complaint::query()->sole();
    $action = [
        'title' => 'Vérifier le chauffage avant chaque session', 'source_id' => qrItem(ReferenceList::ImprovementSource, 'Réclamation'),
        'complaint_id' => $complaint->id, 'due_on' => '2026-12-01', 'status' => 'planned',
    ];

    $this->post('/qualite/amelioration', $action)->assertRedirect()->assertSessionHas('status', 'Action enregistrée.');
    $row = ImprovementAction::query()->sole();
    expect($row->complaint_id)->toBe($complaint->id);

    $this->put("/qualite/amelioration/{$row->id}", [...$action, 'status' => 'done'])
        ->assertSessionHasErrors(['done_on' => 'Indiquez la date de réalisation.', 'result' => 'Décrivez le résultat de l\'action.']);
    $this->put("/qualite/amelioration/{$row->id}", [...$action, 'status' => 'done', 'done_on' => '2026-11-18', 'result' => 'Contrôle ajouté à la check-list.'])
        ->assertSessionHasNoErrors();

    $this->get('/qualite/amelioration')->assertInertia(fn (Assert $p) => $p
        ->component('qualite/registers/actions')->where('actions.0.complaint.subject', 'Salle trop froide'));
});

it('measures the complaints (indicator 31) and the improvement actions (indicator 32) of the last 12 months', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/qualite/reclamations', qrComplaint(['status' => 'closed', 'response' => 'OK', 'responded_on' => '2026-11-14']));
    $this->post('/qualite/reclamations', qrComplaint(['received_on' => '2026-11-15']));
    $this->post('/qualite/reclamations', qrComplaint(['received_on' => '2025-09-01', 'status' => 'closed', 'response' => 'Ancienne', 'responded_on' => '2025-09-02']));
    $source = qrItem(ReferenceList::ImprovementSource, 'Interne');
    $this->post('/qualite/amelioration', ['title' => 'A', 'source_id' => $source, 'due_on' => '2026-11-01', 'status' => 'planned']);
    $this->post('/qualite/amelioration', ['title' => 'B', 'source_id' => $source, 'status' => 'done', 'done_on' => '2026-11-10', 'result' => 'Fait']);

    $complaints = collect(Measures::for(31))->firstWhere('key', 'qualite.complaints');
    expect($complaints['value'])->toBe('2')
        ->and($complaints['detail'])->toBe('1 close, délai moyen de réponse : 4 jours ; 1 ouverte.');

    $actions = collect(Measures::for(32))->firstWhere('key', 'qualite.improvements');
    expect($actions['value'])->toBe('1 réalisée sur 2')
        ->and($actions['detail'])->toBe('1 en retard.')
        ->and($actions['tone'])->toBe('warn');
});
