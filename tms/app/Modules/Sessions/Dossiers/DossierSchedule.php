<?php

namespace App\Modules\Sessions\Dossiers;

use Carbon\CarbonImmutable;

/**
 * The span of the sessions a dossier enrols trainees on: earliest start and
 * latest end, from which « En cours » / « Réalisé » are computed
 * (DossierStatus::effective()).
 *
 * The enrolments arrive with lot 2a task 5 (table enrolments): until then
 * a dossier has no session, so its stored state is its effective state.
 * Task 5 reads here, in one query for a page of dossiers, the sessions of
 * their non-cancelled enrolments.
 */
final class DossierSchedule
{
    /**
     * @param  list<string>  $dossierIds
     * @return array<string, array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}> by dossier id, dossiers without session left out
     */
    public static function spans(array $dossierIds): array
    {
        return [];
    }
}
