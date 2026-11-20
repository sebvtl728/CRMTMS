<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Support\Str;

/**
 * What the satisfaction questionnaires (module Questionnaires) read of the
 * sessions: a session, its followed trainees (enrolled or confirmed, not
 * left), the contact person of its entreprise dossiers, the sessions ended
 * between two days, and the viewer's rights on a session.
 */
final class Surveys
{
    /**
     * @return array{id: string, label: string, training_id: string, ends_on: string, cancelled: bool}|null
     */
    public static function session(string $sessionId): ?array
    {
        $session = Str::isUuid($sessionId) ? TrainingSession::query()->find($sessionId) : null;
        if ($session === null) {
            return null;
        }
        $title = app(Trainings::class)->findMany([$session->training_id])[$session->training_id]->title ?? null;

        return [
            'id' => $session->id,
            'label' => $session->label($title),
            'training_id' => $session->training_id,
            'ends_on' => $session->ends_on->toDateString(),
            'cancelled' => $session->isCancelled(),
        ];
    }

    /**
     * @return list<array{enrolment_id: string, person_id: string, dossier_id: string}>
     */
    public static function trainees(string $sessionId): array
    {
        return Enrolment::query()->where('training_session_id', $sessionId)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::HOLDING_A_PLACE))->whereNull('left_on')
            ->orderByRaw('entered_on asc nulls first')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'person_id', 'dossier_id'])
            ->map(fn (Enrolment $e) => ['enrolment_id' => (string) $e->id, 'person_id' => (string) $e->person_id, 'dossier_id' => (string) $e->dossier_id])
            ->values()->all();
    }

    /**
     * The contact persons of the (not cancelled) entreprise dossiers having a
     * followed trainee in the session, each once.
     *
     * @return list<string>
     */
    public static function clientContacts(string $sessionId): array
    {
        $dossierIds = array_values(array_unique(array_column(self::trainees($sessionId), 'dossier_id')));

        return Dossier::query()->whereKey($dossierIds)->where('status', '!=', 'cancelled')
            ->whereNotNull('account_organisation_id')->whereNotNull('contact_person_id')
            ->orderBy('contact_person_id')->distinct()->pluck('contact_person_id')
            ->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * The number of followed trainees (as trainees()) of these sessions, in
     * one query; a session without any is left out.
     *
     * @param  list<string>  $sessionIds
     * @return array<string, int>
     */
    public static function traineeCounts(array $sessionIds): array
    {
        return Enrolment::query()->whereIn('training_session_id', $sessionIds)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::HOLDING_A_PLACE))->whereNull('left_on')
            ->groupBy('training_session_id')->selectRaw('training_session_id, count(*) as n')->toBase()->get()
            ->mapWithKeys(fn (object $r) => [(string) $r->training_session_id => (int) $r->n])->all();
    }

    /**
     * The last day (Y-m-d) of these sessions, in one query (unknown ids left out).
     *
     * @param  list<string>  $sessionIds
     * @return array<string, string>
     */
    public static function endDays(array $sessionIds): array
    {
        return TrainingSession::query()->whereKey(array_values(array_filter($sessionIds, fn (string $id) => Str::isUuid($id))))
            ->get(['id', 'ends_on'])->mapWithKeys(fn (TrainingSession $s) => [(string) $s->id => $s->ends_on->toDateString()])->all();
    }

    /**
     * The sessions (not cancelled, not trashed) whose last day is between
     * $from and $to (Y-m-d, both included).
     *
     * @return list<string>
     */
    public static function endedOn(string $from, string $to): array
    {
        return TrainingSession::query()->where('status', '!=', 'cancelled')
            ->whereBetween('ends_on', [$from, $to])->orderBy('ends_on')->orderBy('id')
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** The viewer may see ($update false) or update the session. */
    public static function allows(User $user, string $sessionId, bool $update): bool
    {
        $session = Str::isUuid($sessionId) ? TrainingSession::query()->find($sessionId) : null;

        return $session !== null && SessionsVisibility::allows($user, $update ? Permissions::SESSIONS_UPDATE : Permissions::SESSIONS_VIEW, $session);
    }
}
