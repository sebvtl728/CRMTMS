<?php

namespace App\Modules\Sessions\Dossiers;

use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Sessions\SessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The span of the sessions a dossier enrols trainees on: earliest start and
 * latest end of the sessions of its enrolments (cancelled enrolments, and
 * cancelled or trashed sessions, aside), from which « En cours » /
 * « Réalisé » are computed (DossierStatus::effective()). spans() reads a
 * page of dossiers in one query; firstStart() / lastEnd() are the same
 * values as correlated sub-queries, for the state filter of the list.
 */
final class DossierSchedule
{
    /**
     * @param  list<string>  $dossierIds
     * @return array<string, array{starts_on: CarbonImmutable, ends_on: CarbonImmutable}> by dossier id, dossiers without session left out
     */
    public static function spans(array $dossierIds): array
    {
        if ($dossierIds === []) {
            return [];
        }

        return self::sessions()
            ->whereIn('enrolments.dossier_id', array_values(array_unique($dossierIds)))
            ->groupBy('enrolments.dossier_id')
            ->get(['enrolments.dossier_id', DB::raw('min(training_sessions.starts_on) as starts_on'), DB::raw('max(training_sessions.ends_on) as ends_on')])
            ->mapWithKeys(fn (object $row) => [(string) $row->dossier_id => [
                'starts_on' => CarbonImmutable::parse((string) $row->starts_on, 'Europe/Paris')->startOfDay(),
                'ends_on' => CarbonImmutable::parse((string) $row->ends_on, 'Europe/Paris')->startOfDay(),
            ]])->all();
    }

    /**
     * SQL of the earliest start of the sessions of dossiers.id (correlated).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function firstStart(): array
    {
        return self::correlated('min(training_sessions.starts_on)');
    }

    /**
     * SQL of the latest end of the sessions of dossiers.id (correlated).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function lastEnd(): array
    {
        return self::correlated('max(training_sessions.ends_on)');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private static function correlated(string $aggregate): array
    {
        $query = self::sessions()->selectRaw($aggregate)->whereColumn('enrolments.dossier_id', 'dossiers.id');

        return ['('.$query->toSql().')', $query->getBindings()];
    }

    private static function sessions(): Builder
    {
        return DB::table('enrolments')
            ->join('training_sessions', 'training_sessions.id', '=', 'enrolments.training_session_id')
            ->where('enrolments.status', '<>', EnrolmentStatus::Cancelled->value)
            ->where('training_sessions.status', '<>', SessionStatus::Cancelled->value)
            ->whereNull('training_sessions.deleted_at');
    }
}
