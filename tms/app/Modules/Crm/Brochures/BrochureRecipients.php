<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Facades\DB;

/**
 * Who a brochure can be sent to from a fiche: the people attached to it that
 * the user sees (CrmVisibility). A person fiche: herself; an entreprise: its
 * active interlocuteurs (work e-mail of the link first); an opportunity: its
 * contacts and its account. Anonymised people are left out; one without an
 * e-mail is listed (email null) but refused at sending.
 */
final class BrochureRecipients
{
    /**
     * @return list<array{id: string, name: string, email: string|null}>
     */
    public static function for(Organisation|Person|Opportunity $subject, User $user): array
    {
        $organisationId = $subject instanceof Organisation ? $subject->id : ($subject instanceof Opportunity ? $subject->organisation_id : null);

        $ids = match (true) {
            $subject instanceof Person => [$subject->id],
            $subject instanceof Organisation => Affiliation::query()->where('organisation_id', $subject->id)->whereNull('ended_on')->pluck('person_id')->all(),
            default => [
                ...DB::table('opportunity_contacts')->where('opportunity_id', $subject->id)->pluck('person_id')->all(),
                ...($subject->person_id === null ? [] : [$subject->person_id]),
            ],
        };
        if ($ids === []) {
            return [];
        }

        // The work e-mail of the person at the entreprise, when the account is one.
        $work = $organisationId === null ? collect() : Affiliation::query()->where('organisation_id', $organisationId)->whereNull('ended_on')
            ->whereIn('person_id', $ids)->whereNotNull('work_email')->pluck('work_email', 'person_id');

        return CrmVisibility::people(Person::query(), $user)->whereIn('people.id', array_values(array_unique($ids)))
            ->whereNull('people.anonymised_at')->orderBy('people.last_name')->orderBy('people.first_name')->get()
            ->map(function (Person $p) use ($work): array {
                $email = trim((string) ($work[$p->id] ?? '')) ?: trim((string) $p->email);

                return ['id' => $p->id, 'name' => $p->fullName(), 'email' => $email === '' ? null : $email];
            })->values()->all();
    }
}
