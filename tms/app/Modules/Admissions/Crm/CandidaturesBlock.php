<?php

namespace App\Modules\Admissions\Crm;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Permissions;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Admission;

/**
 * Block « Candidatures » of a person's CRM fiche (Crm\Public\FicheBlocks):
 * her candidatures within the viewer's scope, badged « Apprenant » or
 * « Alternant », and the action « Ouvrir une candidature » (the transformation
 * of an interlocuteur into a candidate) for who may create one.
 */
final class CandidaturesBlock
{
    /**
     * @return array<string, mixed>|null
     */
    public static function for(string $personId, User $viewer): ?array
    {
        if (! $viewer->can(Permissions::CANDIDATURES_VIEW)) {
            return null;
        }
        $candidatures = AdmissionsVisibility::candidatures(Candidature::query(), $viewer)
            ->where('person_id', $personId)->orderByDesc('created_at')->get();
        $titles = [];
        foreach (app(Trainings::class)->findMany(array_values(array_filter($candidatures->pluck('training_id')->unique()->all()))) as $id => $training) {
            $titles[$id] = $training->title;
        }
        $contracts = AlternanceContract::query()->whereIn('candidature_id', $candidatures->pluck('id'))->pluck('candidature_id')->flip();

        return [
            'title' => 'Candidatures',
            'empty' => 'Aucune candidature.',
            'rows' => $candidatures->map(fn (Candidature $c) => [
                'id' => $c->id,
                'title' => $c->number.(isset($titles[$c->training_id]) ? ' · '.$titles[$c->training_id] : ''),
                'subtitle' => $c->status->label(),
                'badge' => self::kind($c, $contracts->has($c->id)),
                'href' => "/admissions/candidatures/{$c->id}",
            ])->values()->all(),
            'action' => $viewer->can(Permissions::CANDIDATURES_UPDATE)
                ? ['label' => 'Ouvrir une candidature', 'href' => "/admissions/candidatures/create?person={$personId}"]
                : null,
        ];
    }

    /** « Alternant », « Apprenant » or null: read from the live dossier and the contract. */
    public static function kind(Candidature $c, bool $hasContract): ?string
    {
        if ($c->dossier_id === null || Admission::dossierState($c->dossier_id) !== 'live') {
            return null;
        }

        return $hasContract ? 'Alternant' : 'Apprenant';
    }
}
