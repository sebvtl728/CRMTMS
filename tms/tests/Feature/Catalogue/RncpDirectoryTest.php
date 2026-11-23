<?php

use App\Modules\Catalogue\BpfObjective;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Rncp\RncpDirectory;
use App\Modules\Catalogue\Rncp\RncpSync;
use App\Modules\Catalogue\Training;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Todo\TodoSources;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.40']);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Paris'));
});
afterEach(fn () => HostPolicy::resolveUsing(null));

/** A zip shaped like France compétences' « export-fiches-csv » (file names with accents). */
function rncpExportZip(): string
{
    $path = tempnam(sys_get_temp_dir(), 'rncp').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('export_fiches_CSV_Standard_2026_11_22.csv', "\u{FEFF}".implode("\r\n", [
        '"Id_Fiche";"Numero_Fiche";"Intitule";"Abrege_Libelle";"Abrege_Intitule";"Nomenclature_Europe_Niveau";"Nomenclature_Europe_Intitule";"Accessible_Nouvelle_Caledonie";"Accessible_Polynesie_Francaise";"Date_dernier_jo";"Date_Decision";"Date_Fin_Enregistrement";"Date_Effet";"Type_Enregistrement";"Validation_Partielle";"Actif"',
        '"1";"RNCP38663";"Employé polyvalent en restauration";"TP";"Titre professionnel";"NIV3";"Niveau 3";"Non";"Non";;;"04/06/2029";;"Enregistrement de droit";;"ACTIVE"',
        '"2";"RNCP31053";"Commis de cuisine";"TP";"Titre professionnel";"NIV3";"Niveau 3";"Non";"Non";;;"21/07/2023";;"Enregistrement de droit";;"INACTIVE"',
        '"3";"RNCP40001";"Barista";"TP";"Titre professionnel";"NIV4";"Niveau 4";"Non";"Non";;;"01/03/2027";;"Enregistrement sur demande";;"ACTIVE"',
        '"4";"RS5000";"Habilitation";"";"";"";"";"Non";"Non";;;;;"";;"ACTIVE"',
    ]));
    $zip->addFromString('export_fiches_CSV_Nsf_2026_11_22.csv', implode("\n", [
        '"Numero_Fiche";"Nsf_Code";"Nsf_Intitule"',
        '"RNCP38663";"221t";"221t : Agro-alimentaire"',
        '"RNCP38663";"334t";"334t : Accueil"',
    ]));
    $zip->addFromString('export_fiches_CSV_Blocs_De_Compétences_2026_11_22.csv', implode("\n", [
        '"Numero_Fiche";"Bloc_Competences_Code";"Bloc_Competences_Libelle"',
        '"RNCP38663";"RNCP38663BC01";"Préparer et dresser des entrées et des desserts"',
        '"RNCP38663";"RNCP38663BC02";"Réaliser la plonge et le nettoyage"',
    ]));
    $zip->addFromString('export_fiches_CSV_Rome_2026_11_22.csv', '"Numero_Fiche";"Code_Rome"');
    $zip->close();

    return $path;
}

function fakeRncpPortal(): void
{
    $zip = (string) file_get_contents(rncpExportZip());
    Http::fake([
        'www.data.gouv.fr/api/2/datasets/*' => fn () => Http::response(['data' => [
            ['title' => 'export-fiches-rncp-v4-1-2026-11-23.zip', 'url' => 'https://static.data.gouv.fr/x/export-fiches-rncp-v4-1-2026-11-23.zip'],
            ['title' => 'export-fiches-csv-2026-11-23.zip', 'url' => 'https://static.data.gouv.fr/x/export-fiches-csv-2026-11-23.zip'],
            ['title' => 'export-fiches-csv-2026-11-22.zip', 'url' => 'https://static.data.gouv.fr/x/export-fiches-csv-2026-11-22.zip'],
        ]]),
        'static.data.gouv.fr/x/export-fiches-csv-2026-11-23.zip' => fn () => Http::response($zip, 200, ['Content-Type' => 'application/zip']),
    ]);
}

it('loads the RNCP certifications of the latest export, with their NSF codes and blocks', function () {
    fakeRncpPortal();

    expect(app(RncpSync::class)->run())->toBe(3);
    $epr = app(RncpDirectory::class)->find('rncp38663');
    expect($epr)->toMatchArray([
        'code' => 'RNCP38663', 'title' => 'Employé polyvalent en restauration', 'level' => 3, 'active' => true, 'end_on' => '2029-06-04',
        'nsf_codes' => ['221t', '334t'],
        'blocks' => [['code' => 'RNCP38663BC01', 'label' => 'Préparer et dresser des entrées et des desserts'], ['code' => 'RNCP38663BC02', 'label' => 'Réaliser la plonge et le nettoyage']],
    ])->and(app(RncpDirectory::class)->find('RS5000'))->toBeNull()
        ->and(app(RncpDirectory::class)->find('RNCP99999'))->toBeNull();

    // A second run replaces the table, never doubles it.
    app(RncpSync::class)->run();
    expect(DB::table('rncp_certifications')->count())->toBe(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'export-fiches-csv-2026-11-23.zip'));
});

it('suggests the BPF objective, the NSF group and the competence grid, and says the state', function () {
    fakeRncpPortal();
    app(RncpSync::class)->run();
    $this->actingAs(admin(twoFactor: true));

    $this->getJson('/catalogue/rncp/RNCP38663')->assertOk()->assertJson([
        'found' => true, 'title' => 'Employé polyvalent en restauration', 'level' => 3, 'state' => 'active', 'end_on' => '04/06/2029',
        'suggestion' => ['bpf_objective' => BpfObjective::Rncp3->value, 'nsf_code' => '221',
            'competences_text' => "AT Préparer et dresser des entrées et des desserts\nAT Réaliser la plonge et le nettoyage"],
    ]);
    $this->getJson('/catalogue/rncp/RNCP31053')->assertOk()->assertJson(['found' => true, 'state' => 'inactive']);
    $this->getJson('/catalogue/rncp/RNCP40001')->assertOk()->assertJson(['found' => true, 'state' => 'ending']);
    $this->getJson('/catalogue/rncp/RNCP12345')->assertOk()->assertJson(['found' => false]);
});

it('counts in « À traiter » the active trainings whose certification is inactive, ending or unknown', function () {
    fakeRncpPortal();
    app(RncpSync::class)->run();
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id;
    foreach (['RNCP38663', 'RNCP31053', 'RNCP40001', 'RNCP12345'] as $code) {
        Training::query()->create(['title' => $code, 'das_id' => $das, 'rncp_code' => $code]);
    }
    Training::query()->create(['title' => 'Désactivée', 'das_id' => $das, 'rncp_code' => 'RNCP31053', 'is_active' => false]);
    $this->actingAs($admin = admin(twoFactor: true));

    $row = collect(TodoSources::for($admin))->firstWhere('key', 'catalogue.rncp');
    expect($row['count'])->toBe(3)->and($row['url'])->toBe('/catalogue?rncp=alerte');

    $titles = collect($this->get('/catalogue?rncp=alerte')->assertOk()->original->getData()['page']['props']['trainings']['data'])->pluck('title')->sort()->values()->all();
    expect($titles)->toBe(['RNCP12345', 'RNCP31053', 'RNCP40001']);
});
