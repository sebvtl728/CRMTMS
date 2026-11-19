<?php

use App\Modules\Core\Audit\AuditLog;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

const EXPORT_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

beforeEach(fn () => fakeImportDisk());

/** The HTML of the summary sent to Gotenberg. */
function exportedSummaryHtml(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}

/** @return array<string, string> entry name => content */
function zipEntries(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $entries;
}

it('exports the audit file: a summary of the 33 indicators and their files, by indicator', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 sommaire', 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs($admin = admin(twoFactor: true));
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Procédure VSS', 'file' => UploadedFile::fake()->createWithContent('procedure.pdf', EXPORT_PDF)]);
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Procédure révisée', 'file' => UploadedFile::fake()->createWithContent('procedure.pdf', EXPORT_PDF)]);
    $this->post('/qualite/qualiopi/8/proofs', ['kind' => 'link', 'title' => 'Procédure de positionnement', 'url' => 'https://intranet.example.test/positionnement']);
    $this->put('/qualite/qualiopi/33', ['applicable' => false, 'not_applicable_reason' => 'Nous ne sommes pas un CFA.', 'status' => 'ready'])->assertSessionHasNoErrors();

    $response = $this->get('/qualite/qualiopi/export')->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('dossier-qualiopi-');

    $entries = zipEntries($response->baseResponse->getFile()->getPathname());
    expect(array_keys($entries))->toContain('sommaire.pdf', 'indicateur-12/procedure.pdf', 'indicateur-12/procedure (2).pdf')
        ->and($entries['sommaire.pdf'])->toBe('%PDF-1.7 sommaire')
        ->and($entries['indicateur-12/procedure.pdf'])->toBe(EXPORT_PDF);

    $html = exportedSummaryHtml();
    expect($html)->toContain('Indicateur 33', 'Non applicable', 'Nous ne sommes pas un CFA.', 'Procédure VSS', 'indicateur-12/procedure (2).pdf',
        'https://intranet.example.test/positionnement', 'Taux d&#039;abandon (12 derniers mois)', 'Indicateur 1 ');
    expect(AuditLog::query()->where('event', 'qualite.qualiopi.exported')->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('answers 503 with a French message when the PDF engine is down', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);

    $this->actingAs(admin(twoFactor: true))->get('/qualite/qualiopi/export')->assertStatus(503);
});

it('refuses the export to a user without the Qualité right', function () {
    $this->actingAs(commercial())->get('/qualite/qualiopi/export')->assertForbidden();
});
