<?php

use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Training;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/* Fixtures of the brochure library tests. */

const BROCHURE_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

function brochureFile(string $name = 'Plaquette été.pdf', string $content = BROCHURE_PDF): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function brochureDas(string $label = 'Hôtellerie-restauration'): string
{
    return crmItem(ReferenceList::Das, $label)->id;
}

function brochureTraining(string $title, ?string $dasLabel = null): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => brochureDas($dasLabel ?? 'Hôtellerie-restauration')]);
}

/**
 * Deposits a brochure through the screen (as an administrator unless a user
 * is already acting) and returns it.
 *
 * @param  array<string, mixed>  $payload
 */
function depositBrochure(string $title = 'HACCP', array $payload = []): Brochure
{
    test()->post('/catalogue/brochures', ['title' => $title, 'file' => brochureFile(), ...$payload])->assertRedirect('/catalogue/brochures');

    return Brochure::query()->where('title', $title)->latest('id')->firstOrFail();
}

/**
 * @return list<string> every file on the private disk
 */
function brochureDiskFiles(): array
{
    return Storage::disk('local')->allFiles();
}
