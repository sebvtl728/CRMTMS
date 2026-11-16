<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * Choices of the enrolment dialogs: from a session, the dossiers the user
 * sees with a training line of its training (an intra session: the
 * dossiers of its entreprise only); from a dossier, the sessions of the
 * training of each line he may enrol on. Enrolments re-checks everything.
 */
final class EnrolmentOptions
{
    public const LIMIT = 100;

    /**
     * @return list<array{id: string, number: string, account: ?string, account_organisation_id: ?string, lines: list<array{id: string, label: string}>}>
     */
    public static function dossiersFor(TrainingSession $session, User $user): array
    {
        $dossiers = SessionsVisibility::dossiers(Dossier::query(), $user)
            ->where('dossiers.status', '<>', DossierStatus::Cancelled->value)
            ->when($session->kind === SessionKind::Intra->value, fn (Builder $q) => $q->where('dossiers.account_organisation_id', $session->organisation_id))
            ->whereHas('lines', fn (Builder $l) => $l->where('dossier_lines.training_id', $session->training_id))
            ->with(['lines' => fn ($l) => $l->where('dossier_lines.training_id', $session->training_id)])
            ->orderByDesc('dossiers.created_at')->limit(self::LIMIT)->get();
        $fiches = app(Fiches::class);
        $accounts = $fiches->organisationLabels($dossiers->pluck('account_organisation_id')->filter()->values()->all())
            + $fiches->personLabels($dossiers->pluck('account_person_id')->filter()->values()->all());

        return $dossiers->map(fn (Dossier $d) => [
            'id' => $d->id,
            'number' => $d->number,
            'account' => $accounts[$d->accountId()]['label'] ?? null,
            'account_organisation_id' => $d->account_organisation_id,
            'lines' => $d->lines->map(fn (DossierLine $l) => ['id' => $l->id, 'label' => $l->label])->values()->all(),
        ])->values()->all();
    }

    /**
     * Sessions open to enrolment for each training line of $dossier, by
     * line id: the training's, not cancelled, in the scope of
     * sessions.sessions.update; intra ones of the contractant only.
     *
     * @return array<string, list<array{id: string, number: string, title: ?string, starts_on: string, ends_on: string, kind_label: string, capacity: ?int, planned_hours: ?string}>>
     */
    public static function sessionsFor(Dossier $dossier, User $user): array
    {
        $lines = $dossier->lines->filter(fn (DossierLine $l) => $l->isTraining());
        if ($lines->isEmpty() || ! $user->can(Permissions::SESSIONS_UPDATE)) {
            return [];
        }
        $sessions = self::openSessions($lines->pluck('training_id')->unique()->values()->all(), $dossier->account_organisation_id, $user)
            ->orderByDesc('training_sessions.starts_on')->limit(self::LIMIT)->get();

        $byLine = [];
        foreach ($lines as $line) {
            $byLine[$line->id] = $sessions->where('training_id', $line->training_id)->map(fn (TrainingSession $s) => self::sessionOption($s))->values()->all();
        }

        return $byLine;
    }

    /**
     * Sessions of $trainingIds a dossier of the contractant
     * $accountOrganisationId (null: a particulier) may enrol on, in the scope
     * of sessions.sessions.update: not cancelled; inter ones; intra ones of
     * that entreprise; individual ones still free (any contractant).
     *
     * @param  list<string>  $trainingIds
     * @return Builder<TrainingSession>
     */
    public static function openSessions(array $trainingIds, ?string $accountOrganisationId, User $user): Builder
    {
        return SessionsVisibility::sessions(TrainingSession::query(), $user, Permissions::SESSIONS_UPDATE)
            ->whereIn('training_sessions.training_id', $trainingIds)
            ->where('training_sessions.status', '<>', SessionStatus::Cancelled->value)
            ->where(fn (Builder $q) => $q->where('training_sessions.kind', SessionKind::Inter->value)
                // An individual session still free takes any contractant.
                ->orWhere(fn (Builder $i) => $i->where('training_sessions.kind', SessionKind::Individual->value)
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')->from('enrolments')
                        ->whereColumn('enrolments.training_session_id', 'training_sessions.id')
                        ->where('enrolments.status', '<>', EnrolmentStatus::Cancelled->value)))
                // An intra session: its entreprise's dossiers only (an individual one of it is above).
                ->when($accountOrganisationId !== null, fn (Builder $o) => $o->orWhere(fn (Builder $intra) => $intra
                    ->where('training_sessions.kind', SessionKind::Intra->value)
                    ->where('training_sessions.organisation_id', $accountOrganisationId))));
    }

    /**
     * @return array{id: string, number: string, title: ?string, starts_on: string, ends_on: string, kind_label: string, capacity: ?int, planned_hours: ?string}
     */
    public static function sessionOption(TrainingSession $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'title' => $s->title,
            'starts_on' => $s->starts_on->toDateString(),
            'ends_on' => $s->ends_on->toDateString(),
            'kind_label' => SessionKind::from($s->kind)->label(),
            'capacity' => $s->capacity,
            'planned_hours' => SessionRows::hours($s->planned_hours),
        ];
    }

    /**
     * @return array{bpf_category: list<array{value: string, label: string}>, abandon_reason: list<array{value: string, label: string}>}
     */
    public static function references(): array
    {
        return [
            'bpf_category' => SessionListQuery::reference(ReferenceList::BpfCategory),
            'abandon_reason' => SessionListQuery::reference(ReferenceList::AbandonReason),
        ];
    }
}
