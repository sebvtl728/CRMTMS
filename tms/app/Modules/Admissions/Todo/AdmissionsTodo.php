<?php

namespace App\Modules\Admissions\Todo;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;

/** What Admissions declares to « À traiter » (2026-11-22). */
final class AdmissionsTodo
{
    public static function register(): void
    {
        TodoSources::register('admissions.identified', 'Admissions', 'Candidatures à traiter (identifiées)', Permissions::CANDIDATURES_VIEW,
            fn (User $u): int => AdmissionsVisibility::candidatures(Candidature::query(), $u)
                ->where('candidatures.status', CandidatureStatus::Identified->value)->count(),
            fn (User $u): string => '/admissions/candidatures?status='.CandidatureStatus::Identified->value);
    }
}
