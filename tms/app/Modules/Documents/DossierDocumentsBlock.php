<?php

namespace App\Modules\Documents;

use App\Modules\Core\Users\User;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;

/**
 * The « Documents » block of a dossier page (Sessions\Public\DossierBlocks):
 * its documents, newest first, and what the viewer may do. Null without
 * documents.view on the dossier.
 */
final class DossierDocumentsBlock
{
    /**
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>, component: string, props: array<string, mixed>}|null
     */
    public static function for(string $dossierId, User $viewer): ?array
    {
        if (! Paperwork::allows($viewer, Permissions::VIEW, $dossierId)) {
            return null;
        }
        $dossier = Paperwork::dossier($dossierId);
        if ($dossier === null) {
            return null;
        }
        $canManage = Paperwork::allows($viewer, Permissions::MANAGE, $dossierId);
        $documents = Document::query()->where('dossier_id', $dossierId)->orderByDesc('issued_at')->orderByDesc('id')->get();
        // An alternance dossier: the convention of its contract type replaces the classic one.
        $issuable = $dossier['alternance'] !== null ? DocumentType::forContract((string) $dossier['alternance']['contract_type'])
            : ($dossier['contractor']['type'] === 'organisation' ? DocumentType::Convention : DocumentType::Contract);

        return [
            'title' => 'Documents',
            // The component lists the documents itself: the empty text only when there is none.
            'empty' => $documents->isEmpty() ? 'Aucun document émis.' : '',
            'rows' => [],
            'component' => 'documents.dossier',
            'props' => [
                'dossier_id' => $dossierId,
                'can_manage' => $canManage,
                // The document of the dossier, then the trainee's forms (one per trainee).
                'issuable' => $canManage ? array_map(fn (DocumentType $t) => ['value' => $t->value, 'label' => $t->label(), 'per_trainee' => $t->perEnrolment()],
                    [$issuable, ...DocumentType::fromDossier()]) : [],
                'documents' => $documents->map(fn (Document $d) => [
                    'id' => $d->id,
                    'type' => $d->type,
                    'type_label' => DocumentType::labelOf($d->type),
                    'number' => $d->number,
                    'status' => $d->status->value,
                    'status_label' => $d->status->label(),
                    'issued_at' => $d->issued_at->toIso8601String(),
                    'recipient' => $d->recipient_snapshot['name'] ?? null,
                    'sent_at' => $d->sent_at?->toIso8601String(),
                    'email_status' => $d->email_status,
                    'email_error' => $d->email_status === 'failed'
                        ? DocumentSending::query()->where('document_id', $d->id)->where('status', 'failed')->latest('created_at')->value('error') : null,
                    'cancel_reason' => $d->cancel_reason,
                    'defaults' => $canManage && ! $d->isCancelled() && DocumentType::tryFrom($d->type) !== null ? DocumentMail::defaults($d) : null,
                    'signature' => DocumentSignatures::state($d),
                    'can_sign' => $canManage && (DocumentType::tryFrom($d->type)?->signable() ?? false)
                        && ! $d->isCancelled() && $d->status !== DocumentStatus::Signed,
                ])->values()->all(),
            ],
        ];
    }
}
