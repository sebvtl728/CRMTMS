<?php

namespace App\Modules\Sessions\Sessions;

use App\Modules\Core\Companies\Company;

/**
 * The place of a session as it is printed (documents, reminders, calendars).
 */
final class SessionPlace
{
    /**
     * In our premises: « <company>, <address> (<detail>) »; otherwise the
     * address typed on the session, null when there is none.
     */
    public static function of(TrainingSession $session): ?string
    {
        $text = trim((string) $session->location_text);
        if ($session->location_kind !== SessionLocation::Company->value) {
            return $text === '' ? null : $text;
        }
        $company = $session->location_company_id === null ? null : Company::query()->withTrashed()->find($session->location_company_id);
        if ($company === null) {
            return $text === '' ? SessionLocation::Company->label() : $text;
        }
        $city = trim(implode(' ', array_filter([$company->address_postal_code, $company->address_city])));
        $place = implode(', ', array_filter([$company->name, $company->address_line1, $company->address_line2, $city]));

        return $text === '' ? $place : "{$place} ({$text})";
    }

    /**
     * Our companies to choose from for « Dans nos locaux », the default one
     * first; a deleted one only when it is the current place.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function companyOptions(?string $current = null): array
    {
        return Company::query()->withTrashed()
            ->where(fn ($q) => $q->whereNull('deleted_at')->when($current, fn ($q2, $id) => $q2->orWhere('id', $id)))
            ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'deleted_at'])
            ->map(fn (Company $c) => ['value' => $c->id, 'label' => $c->trashed() ? $c->name.' (supprimée)' : $c->name])->values()->all();
    }
}
