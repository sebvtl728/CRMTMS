<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Support\Str;

/**
 * What a new action may hang on: fiches and opportunities the user sees,
 * not trashed (an opportunity's fiche neither), a person not anonymised.
 */
final class ActivityFiches
{
    public static function organisation(?string $id, User $user): ?Organisation
    {
        return self::valid($id) ? CrmVisibility::organisations(Organisation::query(), $user)->find($id) : null;
    }

    public static function person(?string $id, User $user): ?Person
    {
        return self::valid($id) ? CrmVisibility::people(Person::query(), $user)->whereNull('anonymised_at')->find($id) : null;
    }

    public static function opportunity(?string $id, User $user): ?Opportunity
    {
        $opportunity = self::valid($id) ? CrmVisibility::opportunities(Opportunity::query(), $user)->with(['organisation', 'person'])->find($id) : null;
        if ($opportunity === null) {
            return null;
        }
        $fiche = $opportunity->organisation_id !== null ? $opportunity->organisation : $opportunity->person;

        return $fiche === null || $fiche->trashed() || ($fiche instanceof Person && $fiche->anonymised_at !== null) ? null : $opportunity;
    }

    private static function valid(?string $id): bool
    {
        return $id !== null && Str::isUuid($id);
    }
}
