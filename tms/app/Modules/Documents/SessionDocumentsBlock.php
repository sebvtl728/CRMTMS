<?php

namespace App\Modules\Documents;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Carbon\CarbonImmutable;

/**
 * The « Documents » block of a session (Sessions\Public\SessionBlocks) and
 * its summary cards (SessionCards): the per-trainee documents of the
 * trainees whose dossier the viewer may see the documents of.
 */
final class SessionDocumentsBlock
{
    /** @var list<DocumentType> */
    public const TYPES = [DocumentType::Convocation, DocumentType::CompletionCertificate, DocumentType::RealisationCertificate];

    /**
     * @return array{title: string, component: string, props: array<string, mixed>}|null
     */
    public static function for(string $sessionId, User $viewer): ?array
    {
        $visible = [];
        $manage = false;
        foreach (Paperwork::sessionEnrolments($sessionId) as $enrolmentId) {
            $enrolment = Paperwork::enrolment($enrolmentId);
            if ($enrolment !== null && Paperwork::allows($viewer, Permissions::VIEW, $enrolment['dossier_id'])) {
                $visible[] = $enrolment;
                $manage = $manage || Paperwork::allows($viewer, Permissions::MANAGE, $enrolment['dossier_id']);
            }
        }
        if ($visible === []) {
            return null;
        }
        $names = app(Fiches::class)->personLabels(array_column($visible, 'person_id'));
        $documents = Document::query()->whereIn('enrolment_id', array_column($visible, 'id'))->where('status', '!=', DocumentStatus::Cancelled->value)
            ->get()->groupBy(fn (Document $d) => $d->enrolment_id.'|'.$d->type);

        return [
            'title' => 'Documents',
            'component' => 'documents.session',
            'props' => [
                'session_id' => $sessionId,
                'can_manage' => $manage,
                'types' => DocumentType::options(self::TYPES),
                'trainees' => array_map(fn (array $e) => [
                    'enrolment_id' => $e['id'],
                    'name' => $names[$e['person_id']]['label'] ?? 'Stagiaire',
                    'left_on' => $e['left_on'],
                    'documents' => collect(self::TYPES)->mapWithKeys(function (DocumentType $type) use ($documents, $e) {
                        $doc = $documents->get($e['id'].'|'.$type->value)?->first();

                        return [$type->value => $doc === null ? null : [
                            'id' => $doc->id, 'number' => $doc->number, 'status' => $doc->status->value, 'status_label' => $doc->status->label(),
                            'email_status' => $doc->email_status,
                        ]];
                    })->all(),
                ], $visible),
            ],
        ];
    }

    /**
     * « Convocations » (sent / trainees) and « Attestations » (issued /
     * trainees; nothing to do before the end of the training).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, list<array{key: string, title: string, value: string, state: string, text: string, step: string, anchor: ?string}>>
     */
    public static function cards(array $sessionIds, User $viewer): array
    {
        if ($sessionIds === [] || ! $viewer->can(Permissions::VIEW)) {
            return [];
        }
        $overview = Paperwork::sessionsOverview($sessionIds);
        $all = array_merge(...array_values(array_map(fn (array $o) => $o['enrolment_ids'], $overview)) ?: [[]]);
        $docs = Document::query()->whereIn('enrolment_id', $all)->whereIn('type', [DocumentType::Convocation->value, DocumentType::CompletionCertificate->value])
            ->where('status', '!=', DocumentStatus::Cancelled->value)->get(['enrolment_id', 'type', 'sent_at']);
        $sent = $docs->where('type', DocumentType::Convocation->value)->whereNotNull('sent_at')->pluck('enrolment_id')->flip();
        $issued = $docs->where('type', DocumentType::CompletionCertificate->value)->pluck('enrolment_id')->flip();
        $today = CarbonImmutable::now('Europe/Paris')->toDateString();

        $out = [];
        foreach ($overview as $sessionId => $o) {
            $n = count($o['enrolment_ids']);
            if ($n === 0) {
                continue;
            }
            $convened = count(array_filter($o['enrolment_ids'], fn (string $id) => isset($sent[$id])));
            $attested = count(array_filter($o['enrolment_ids'], fn (string $id) => isset($issued[$id])));
            $over = $o['ends_on'] <= $today; // from the last day of the training
            $out[$sessionId] = [
                ['key' => 'documents.convocations', 'title' => 'Convocations', 'value' => "{$convened} / {$n}",
                    'state' => $convened === $n ? 'done' : 'todo',
                    'text' => $convened === $n ? 'Tous les stagiaires ont reçu leur convocation.' : ($n - $convened).' stagiaire(s) sans convocation envoyée.',
                    'step' => 'stagiaires', 'anchor' => 'documents'],
                ['key' => 'documents.attestations', 'title' => 'Attestations', 'value' => "{$attested} / {$n}",
                    'state' => ! $over ? 'na' : ($attested === $n ? 'done' : 'todo'),
                    'text' => ! $over ? 'À émettre à la fin de la formation.' : ($attested === $n ? 'Toutes les attestations sont émises.' : ($n - $attested).' attestation(s) à émettre.'),
                    'step' => 'stagiaires', 'anchor' => 'documents'],
            ];
        }

        return $out;
    }
}
