<?php

namespace App\Modules\Crm\Public;

use App\Modules\Crm\Settings\CrmFollowUpSettings;

/** The CRM follow-up settings other modules read (Sessions plans the reactivation calls). */
final class FollowUpSettings
{
    /** @return list<int> months after a dossier, sorted; empty = disabled */
    public static function reactivationMonths(): array
    {
        return CrmFollowUpSettings::reactivationMonths();
    }

    public static function retentionMonths(): int
    {
        return CrmFollowUpSettings::retentionMonths();
    }
}
