<?php

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochureStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/* Fixtures of the positioning tests (needs Support/admissions.php and fakeImportDisk()). */

const POSITIONING_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

/** A positioning test of the library attached to these DAS and/or trainings. */
function posTest(string $title, array $dasIds = [], array $trainingIds = []): Brochure
{
    return app(BrochureStore::class)->create($title, UploadedFile::fake()->createWithContent($title.'.pdf', POSITIONING_PDF), $trainingIds, $dasIds, Brochure::POSITIONING_TEST);
}

/** A candidature of Léa Durand (lea.durand@example.test) for « CAP Cuisine », with a test of its DAS. */
function posCandidature(): array
{
    $training = adTraining();
    $test = posTest('Test cuisine', [$training->das_id]);
    $c = adCandidature(['person_id' => adPerson('Durand', ['email' => 'lea.durand@example.test'])->id, 'training_id' => $training->id]);

    return [$c, $test];
}

/** @return array<string, mixed> */
function posPayload(Brochure $test, array $over = []): array
{
    return [
        'version_ids' => [$test->current_version_id],
        'to' => 'lea.durand@example.test',
        'subject' => 'Votre test de positionnement',
        'body' => "Bonjour Léa,\n\nVoici votre test : {lien}\n\nCordialement",
        ...$over,
    ];
}

function posSend(Candidature $c, Brochure $test, array $over = []): TestResponse
{
    return test()->post("/admissions/candidatures/{$c->id}/positioning/send", posPayload($test, $over));
}
