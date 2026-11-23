<?php

use App\Modules\Catalogue\BpfObjective;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Nsf;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingExport;
use App\Modules\Catalogue\TrainingModules;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/** @param  array<string, mixed>  $overrides */
function bpfTrainingPayload(array $overrides = []): array
{
    return [
        'title' => 'HACCP 3 jours',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '21',
        ...$overrides,
    ];
}

it('embeds the official NSF groups and the BPF objectives', function () {
    expect(Nsf::CODES)->toHaveCount(93)
        ->and(Nsf::label('334'))->toBe('Accueil, hôtellerie, tourisme')
        ->and(Nsf::label('221'))->toBe('Agro-alimentaire, alimentation, cuisine')
        ->and(Nsf::label('999'))->toBeNull()
        ->and(array_column(BpfObjective::options(), 'value'))->toBe([
            'rncp_6_8', 'rncp_5', 'rncp_4', 'rncp_3', 'rncp_2', 'rncp_cqp', 'rs', 'cqp', 'other', 'skills_assessment', 'vae',
        ]);
});

it('stores the BPF objective and the NSF code of a training, and sends them to the form', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', bpfTrainingPayload(['bpf_objective' => 'other', 'nsf_code' => '221']))->assertRedirect('/catalogue');

    $training = Training::query()->sole();
    expect($training->bpf_objective)->toBe(BpfObjective::Other)->and($training->nsf_code)->toBe('221');

    $this->get("/catalogue/{$training->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('training.bpf_objective', 'other')
        ->where('training.nsf_code', '221')
        ->has('options.bpf_objective', 11)
        ->has('options.nsf', 93));

    $this->get("/catalogue/{$training->id}")->assertInertia(fn (Assert $p) => $p
        ->where('training.bpf_objective', 'Autres formations professionnelles')
        ->where('training.nsf', '221 - Agro-alimentaire, alimentation, cuisine'));
});

it('refuses an unknown BPF objective or NSF code', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/catalogue/create', bpfTrainingPayload(['bpf_objective' => 'robot', 'nsf_code' => '999']))
        ->assertSessionHasErrors(['bpf_objective', 'nsf_code']);
    expect(Training::query()->count())->toBe(0);
});

it('exports the BPF objective and the NSF code', function () {
    $training = Training::query()->create([...bpfTrainingPayload(), 'bpf_objective' => 'rncp_5', 'nsf_code' => '334']);
    $export = new TrainingExport;

    expect($export->columns())->toMatchArray(['bpf_objective' => 'Objectif BPF', 'nsf_code' => 'Code NSF'])
        ->and($export->value($training, 'bpf_objective'))->toBe('Diplôme ou titre RNCP, niveau 5')
        ->and($export->value($training, 'nsf_code'))->toBe('334 - Accueil, hôtellerie, tourisme');
});

it('renders the programme PDF of a training with its last update date', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $html = null;
    Http::fake(['gotenberg:3000/*' => function (HttpRequest $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['name'] ?? null) === 'files' && ($part['filename'] ?? null) === 'index.html') {
                $html = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 programme', 200, ['Content-Type' => 'application/pdf']);
    }]);
    Company::factory()->default()->create(['name' => 'CAP FORMATION']);

    $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00', 'Europe/Paris'));
    $training = Training::query()->create([...bpfTrainingPayload(), 'code' => 'HACCP-3', 'objectives' => ['Appliquer la méthode HACCP'], 'price_company' => '1250.50']);
    $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'Europe/Paris'));
    TrainingModules::sync($training, [['title' => 'Les dangers', 'duration_hours' => '7', 'modality_id' => null, 'content' => 'Biologiques <b>et</b> chimiques']]);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));

    $this->actingAs(userWithPermissions(['catalogue.view']));
    $response = $this->get("/catalogue/{$training->id}/programme.pdf")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('programme-haccp-3.pdf')
        ->and($response->getContent())->toBe('%PDF-1.7 programme')
        ->and($html)->toContain('HACCP 3 jours')
        ->and($html)->toContain('CAP FORMATION')
        ->and($html)->toContain('Appliquer la méthode HACCP')
        ->and($html)->toContain('Les dangers')
        ->and($html)->toContain('Biologiques &lt;b&gt;et&lt;/b&gt; chimiques')
        ->and($html)->toContain('Mis à jour le 05/11/2026');
});

it('needs catalogue.view for the programme PDF and answers 503 in French when Gotenberg is down', function () {
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);
    $training = Training::query()->create(bpfTrainingPayload());

    $this->actingAs(userWithPermissions(['crm.opportunities.view']))->get("/catalogue/{$training->id}/programme.pdf")->assertForbidden();
    $this->actingAs(userWithPermissions(['catalogue.view']))->get("/catalogue/{$training->id}/programme.pdf")->assertStatus(503);
});
