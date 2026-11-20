<?php

use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../../Sessions/Support/lot2a.php';
require_once __DIR__.'/../../Sessions/Support/planning.php';
require_once __DIR__.'/../../Documents/Support/documents.php';

/** The organisme, its references, 1 September 2026 (Paris). */
function svSetUp(string $now = '2026-09-01 08:00'): void
{
    plSetUp();
    test()->travelTo(CarbonImmutable::parse($now, 'Europe/Paris'));
}

/**
 * A session ended on $endsOn with trainees (last name => e-mail or null),
 * in one entreprise dossier whose contact is Paul Martin.
 *
 * @param  array<string, string|null>  $trainees
 * @return array{0: TrainingSession, 1: array<string, Person>}
 */
function svSession(string $endsOn = '2026-08-31', array $trainees = ['Durand' => 'lea.durand@example.test', 'Petit' => 'tom.petit@example.test', 'Roux' => null]): array
{
    $session = plSession(CarbonImmutable::parse($endsOn)->subDays(2)->toDateString(), $endsOn);
    $contact = Person::query()->where('last_name', 'Martin')->first() ?? makePerson('Martin', ['first_name' => 'Paul', 'email' => 'paul.martin@hotel-parc.test']);
    $dossier = l2Dossier(null, null, ['contact_person_id' => $contact->id]);
    $people = [];
    foreach ($trainees as $lastName => $email) {
        $people[$lastName] = makePerson($lastName, ['first_name' => ['Durand' => 'Léa', 'Petit' => 'Tom'][$lastName] ?? 'Alex', 'email' => $email]);
        l2Enrol($dossier, $session, $people[$lastName]);
    }

    return [$session, $people];
}
