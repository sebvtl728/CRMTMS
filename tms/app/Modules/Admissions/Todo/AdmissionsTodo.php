<?php

namespace App\Modules\Admissions\Todo;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Booklet\BookletAccess;
use App\Modules\Admissions\Booklet\BookletMonths;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;

/** What Admissions declares to « À traiter » (2026-11-22). */
final class AdmissionsTodo
{
    public static function register(): void
    {
        TodoSources::register('admissions.identified', 'Admissions', 'Candidatures à traiter (identifiées)', Permissions::CANDIDATURES_VIEW,
            fn (User $u): int => AdmissionsVisibility::candidatures(Candidature::query(), $u)
                ->where('candidatures.status', CandidatureStatus::Identified->value)->count(),
            fn (User $u): string => '/admissions/candidatures?status='.CandidatureStatus::Identified->value);
        TodoSources::register('admissions.booklet_visa', 'Admissions', 'Livrets avec des pages non visées depuis plus de 30 jours', Permissions::BOOKLET_MANAGE,
            fn (User $u): int => self::unvisaed($u),
            fn (User $u): string => '/admissions/livret');
    }

    /**
     * The booklets the user follows with a page of a month ended more than
     * 30 days ago (Paris) and not visaed.
     */
    public static function unvisaed(User $user): int
    {
        $limit = BookletMonths::today()->subDays(30);
        $count = 0;
        foreach (BookletAccess::candidatures(Candidature::query(), $user)->get() as $candidature) {
            foreach (BookletMonths::months($candidature) as $month) {
                $ended = CarbonImmutable::createFromFormat('!Y-m', $month['month'], 'Europe/Paris')?->endOfMonth();
                if ($ended !== null && $ended->lessThan($limit) && collect($month['pages'])->contains(fn (array $page) => $page['visa'] === null)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }
}
