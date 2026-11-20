<?php

namespace App\Modules\Sessions\Dashboard;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\DashboardIndicators;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\FollowUpSettings;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\SessionsVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * « Taux de rétention » of the CRM dashboards (lot 1e), declared by Sessions
 * (Crm\Public\DashboardIndicators): clients (dossier accounts) with two
 * dossiers or more whose training started in the sliding window (24 months
 * by default, CRM settings) ending with the period / clients with one or
 * more; the note counts the repurchases (a client's second dossier starting
 * in the period). Dossiers not cancelled nor trashed, in the scope of
 * sessions.dossiers.view; commercial filter: the responsible of the client's
 * fiche.
 */
final class Retention
{
    public const KEY = 'sessions.retention';

    /**
     * @param  array<string, string|null>  $filters
     * @return array{value: float|null, unit: string, note: string|null}
     */
    public static function compute(array $filters, User $user): array
    {
        $to = (string) $filters['to'];
        $from = (string) $filters['from'];
        $windowStart = CarbonImmutable::parse($to)->subMonthsNoOverflow(FollowUpSettings::retentionMonths())->addDay()->toDateString();

        $starts = DB::table('enrolments')
            ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
            ->where('enrolments.status', '!=', 'cancelled')
            ->where('training_sessions.status', '!=', 'cancelled')->whereNull('training_sessions.deleted_at')
            ->groupBy('enrolments.dossier_id')
            ->selectRaw('enrolments.dossier_id, min(training_sessions.starts_on) as started_on');
        $dossiers = SessionsVisibility::dossiers(Dossier::query(), $user)
            ->joinSub($starts, 's', 's.dossier_id', '=', 'dossiers.id')
            ->where('dossiers.status', '!=', 'cancelled')
            ->when($filters['das'] ?? null, fn ($q, string $das) => $q->where('dossiers.das_id', $das))
            ->when(($filters['target'] ?? null) === 'organisation', fn ($q) => $q->whereNotNull('dossiers.account_organisation_id'))
            ->when(($filters['target'] ?? null) === 'person', fn ($q) => $q->whereNull('dossiers.account_organisation_id'))
            ->where('s.started_on', '<=', $to)
            ->orderBy('s.started_on')
            ->get(['dossiers.account_organisation_id', 'dossiers.account_person_id', 's.started_on']);

        $byClient = [];
        foreach ($dossiers as $d) {
            $key = $d->account_organisation_id !== null ? 'organisation:'.$d->account_organisation_id : 'person:'.$d->account_person_id;
            $byClient[$key][] = substr((string) $d->started_on, 0, 10);
        }
        if (($responsible = $filters['responsible'] ?? null) !== null) {
            $byClient = self::ofResponsible($byClient, $responsible);
        }

        $clients = 0;
        $retained = 0;
        $repurchases = 0;
        foreach ($byClient as $startsOn) {
            $inWindow = count(array_filter($startsOn, fn (string $d) => $d >= $windowStart));
            $clients += $inWindow >= 1 ? 1 : 0;
            $retained += $inWindow >= 2 ? 1 : 0;
            $repurchases += isset($startsOn[1]) && $startsOn[1] >= $from && $startsOn[1] <= $to ? 1 : 0;
        }

        return [
            'value' => $clients === 0 ? null : round(100 * $retained / $clients, 1),
            'unit' => 'percent',
            'note' => $clients === 0 ? null : "{$retained} client(s) sur {$clients} ; réachats dans la période : {$repurchases}",
        ];
    }

    /**
     * @param  array<string, list<string>>  $byClient
     * @return array<string, list<string>>
     */
    private static function ofResponsible(array $byClient, string $responsible): array
    {
        $fiches = app(Fiches::class);
        $ids = ['organisation' => [], 'person' => []];
        foreach (array_keys($byClient) as $key) {
            [$type, $id] = explode(':', $key, 2);
            $ids[$type][] = $id;
        }
        $owners = $fiches->responsibleUsers('organisation', $ids['organisation']) + $fiches->responsibleUsers('person', $ids['person']);

        return array_filter($byClient, fn (string $key) => ($owners[explode(':', $key, 2)[1]] ?? null) === $responsible, ARRAY_FILTER_USE_KEY);
    }

    /** Declares the card (SessionsServiceProvider::boot). */
    public static function register(): void
    {
        DashboardIndicators::define(self::KEY, DashboardIndicators::MONTH, 'Taux de rétention',
            'Clients ayant au moins deux dossiers commencés dans la fenêtre glissante (réglages du CRM) / clients ayant au moins un dossier',
            Permissions::DOSSIERS_VIEW, fn (array $filters, User $user): array => self::compute($filters, $user));
    }
}
