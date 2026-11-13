<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;

/**
 * The fiches an opportunity may be created on (visible, not trashed) and the
 * people that may be linked to it as interlocuteurs.
 */
final class OpportunityAccounts
{
    public static function organisation(?string $id, User $user): ?Organisation
    {
        return $id === null ? null : CrmVisibility::organisations(Organisation::query(), $user)->find($id);
    }

    public static function person(?string $id, User $user): ?Person
    {
        return $id === null ? null : CrmVisibility::people(Person::query(), $user)->find($id);
    }

    /**
     * Interlocuteurs that may be linked: the active contacts of the
     * entreprise, or the particulier himself.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function contactOptions(?string $organisationId, ?string $personId): array
    {
        if ($personId !== null) {
            $person = Person::query()->find($personId);

            return $person === null ? [] : [['value' => $person->id, 'label' => $person->fullName()]];
        }
        if ($organisationId === null) {
            return [];
        }

        return Person::query()
            ->whereIn('id', fn ($q) => $q->select('person_id')->from('affiliations')
                ->where('organisation_id', $organisationId)->whereNull('ended_on')->whereNull('deleted_at'))
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->map(fn (Person $p) => ['value' => $p->id, 'label' => $p->fullName()])->values()->all();
    }
}
