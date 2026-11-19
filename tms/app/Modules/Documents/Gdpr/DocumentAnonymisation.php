<?php

namespace App\Modules\Documents\Gdpr;

use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentSending;
use App\Modules\Documents\Texts\DocumentType;
use Carbon\CarbonImmutable;

/**
 * What the anonymisation of a person does to the documents (called by the
 * CRM Anonymiser in its transaction, FicheLinks): the documents naming her
 * (a particulier's contract, the contact of a convention) keep their
 * number and archived PDF (proof of the training) but lose the frozen
 * recipient's personal data; their e-mails lose address, subject and
 * message. The export lists the documents naming her.
 */
final class DocumentAnonymisation
{
    /**
     * @param  list<string>  $personIds
     */
    public static function clear(array $personIds): void
    {
        if ($personIds === []) {
            return;
        }
        $documents = Document::query()->whereIn('recipient_snapshot->person_id', $personIds)->lockForUpdate()->get();
        foreach ($documents as $document) {
            $snapshot = $document->recipient_snapshot;
            $snapshot = DocumentType::from($document->type) === DocumentType::Contract
                ? ['name' => 'Personne anonymisée', 'attention' => null, 'address_line1' => null, 'address_line2' => null, 'postal_code' => null, 'city' => null, 'email' => null, 'person_id' => null]
                : [...$snapshot, 'attention' => null, 'email' => null, 'person_id' => null];
            // Only the columns the trigger lets change; no journal of the values (personal).
            Document::query()->whereKey($document->id)->toBase()
                ->update(['recipient_snapshot' => json_encode($snapshot), 'updated_at' => CarbonImmutable::now()]);
        }
        DocumentSending::query()->whereIn('document_id', $documents->pluck('id')->all())
            ->update(['email' => null, 'subject' => null, 'body' => null]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function export(string $personId): array
    {
        return ['documents' => Document::query()->where('recipient_snapshot->person_id', $personId)
            ->orderBy('issued_at')->orderBy('number')->get()
            ->map(fn (Document $d) => [
                'numero' => $d->number,
                'document' => DocumentType::from($d->type)->label(),
                'etat' => $d->status->label(),
                'emis_le' => $d->issued_at->setTimezone('Europe/Paris')->toDateString(),
                'envoye_le' => $d->sent_at?->setTimezone('Europe/Paris')->toDateString(),
            ])->values()->all()];
    }
}
