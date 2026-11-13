<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Gdpr\Consent;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    tenant();
    Storage::fake('local');
});

function exportedPerson(): Person
{
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Durand', [
        'civility' => 'Mme', 'first_name' => 'Claire', 'email' => 'claire@example.test', 'mobile' => '0612345678',
        'address_line1' => '1 rue du Lac', 'postal_code' => '74000', 'city' => 'Annecy', 'birth_date' => '1980-01-02', 'project' => 'BTS',
    ]);
    affiliate($person, $org, ['job_title' => 'Gérante', 'work_email' => 'c.durand@lac.test', 'work_phone' => '0450000000', 'started_on' => '2020-01-01']);
    $user = commercial();
    test()->actingAs($user)->post("/crm/people/{$person->id}/notes", ['body' => 'Souhaite un BTS'])->assertSessionHasNoErrors();
    test()->actingAs($user)->post("/crm/people/{$person->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('CV.txt', 'cv')])->assertSessionHasNoErrors();
    Consent::query()->create(['person_id' => $person->id, 'channel' => 'email', 'granted' => true, 'source' => 'Formulaire salon']);

    return $person->refresh();
}

it('builds the full data of a person', function () {
    $person = exportedPerson();
    $absorbed = makePerson('Fusionnée');
    DB::table('people')->where('id', $absorbed->id)->update(['merged_into_id' => $person->id]);

    $data = PersonDataExport::for($person);

    expect($data['identite'])->toMatchArray([
        'id' => $person->id, 'civilite' => 'Mme', 'prenom' => 'Claire', 'nom' => 'Durand', 'date_de_naissance' => '1980-01-02',
        'base_legale' => 'Intérêt légitime', 'projet' => 'BTS',
    ])
        ->and($data['coordonnees'])->toMatchArray(['email' => 'claire@example.test', 'mobile' => '+33612345678', 'ville' => 'Annecy', 'code_postal' => '74000'])
        ->and($data['rattachements'][0])->toMatchArray(['entreprise' => 'Hôtel du Lac', 'fonction' => 'Gérante', 'email_professionnel' => 'c.durand@lac.test', 'debut' => '2020-01-01'])
        ->and($data['notes'][0]['texte'])->toBe('Souhaite un BTS')
        ->and($data['consentements'][0])->toMatchArray(['canal' => 'E-mail', 'accorde' => true, 'source' => 'Formulaire salon'])
        ->and($data['pieces_jointes'][0])->toMatchArray(['nom' => 'CV.txt'])
        ->and($data['pieces_jointes'][0])->not->toHaveKey('path')
        ->and($data['fiches_fusionnees'])->toBe([$absorbed->id])
        ->and($data['dates'])->toHaveKeys(['creee_le', 'modifiee_le', 'dernier_contact']);
});

it('downloads the json, audited without values', function () {
    $person = exportedPerson();
    $user = direction(twoFactor: true);

    $response = $this->actingAs($user)->get("/crm/people/{$person->id}/gdpr-export")->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('.json')
        ->and($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    $json = json_decode((string) $response->getContent(), true);
    expect($json['identite']['nom'])->toBe('Durand');

    $audit = AuditLog::query()->where('event', 'gdpr.exported')->sole();
    expect($audit->auditable_id)->toBe($person->id)->and($audit->user_id)->toBe($user->id)
        ->and(json_encode([$audit->old_values, $audit->new_values]))->not->toContain('Durand')->not->toContain('claire');
});

it('needs crm.gdpr and stays in the organisme', function () {
    $person = exportedPerson();
    $this->actingAs(commercial())->get("/crm/people/{$person->id}/gdpr-export")->assertForbidden();

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => makePerson('Etranger'));
    inTenant();
    $this->actingAs(admin(twoFactor: true))->get("/crm/people/{$foreign->id}/gdpr-export")->assertNotFound();
});
