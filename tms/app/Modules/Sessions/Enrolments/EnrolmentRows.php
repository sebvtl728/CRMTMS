<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\TraineePlanning;
use App\Modules\Sessions\Public\EnrolmentSatisfaction;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Rows of the enrolments shown on a session page, a dossier page and the
 * fiche of a person. On a session, the names of the trainees of every
 * dossier are shown, but the dossier (number, contractant) and the price
 * share only to who sees that dossier (sessions.dossiers.view and its
 * scope, spec lot 2a § 9). The adaptation needs and the notes (personal
 * data) only to who may update the enrolment; a cancelled session offers
 * no action. One query per source for the whole list.
 */
final class EnrolmentRows
{
    /**
     * @param  Collection<int, Enrolment>  $enrolments
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $enrolments, User $viewer): array
    {
        $enrolments->loadMissing(['session', 'dossier', 'line']);
        $fiches = app(Fiches::class);
        $personIds = $enrolments->pluck('person_id')->unique()->values()->all();
        $people = $fiches->personLabels($personIds);
        $openable = $fiches->openable($viewer, 'person', $personIds);
        $dossiers = $enrolments->pluck('dossier')->filter()->unique('id');
        $visibleDossiers = SessionsVisibility::dossiers(Dossier::withTrashed(), $viewer)
            ->whereKey($dossiers->pluck('id')->all())->pluck('id')->flip()->all();
        $accounts = $fiches->organisationLabels($dossiers->pluck('account_organisation_id')->filter()->values()->all())
            + $fiches->personLabels($dossiers->pluck('account_person_id')->filter()->values()->all());
        $sessions = $enrolments->pluck('session')->filter()->unique('id');
        $visibleSessions = SessionsVisibility::sessions(TrainingSession::withTrashed(), $viewer)
            ->whereKey($sessions->pluck('id')->all())->pluck('id')->flip()->all();
        $updatable = SessionsVisibility::sessions(TrainingSession::query(), $viewer, Permissions::SESSIONS_UPDATE)
            ->whereKey($sessions->pluck('id')->all())->pluck('id')->flip()->all();
        $trainings = app(Trainings::class)->findMany($sessions->pluck('training_id')->unique()->values()->all());
        $labels = ReferenceItem::query()->whereKey($enrolments->pluck('abandon_reason_id')->merge($enrolments->pluck('bpf_category_id'))->filter()->unique()->values()->all())
            ->pluck('label', 'id')->all();

        return $enrolments->map(function (Enrolment $e) use ($people, $openable, $visibleDossiers, $accounts, $visibleSessions, $updatable, $trainings, $labels): array {
            $session = $e->session;
            $dossier = $e->dossier;
            $seesDossier = $dossier !== null && isset($visibleDossiers[$dossier->id]);
            // A cancelled session takes no action; personal details only for who manages the enrolment.
            $canUpdate = $session !== null && ! $session->isCancelled() && isset($updatable[$session->id]);

            return [
                'id' => $e->id,
                'person' => [
                    'id' => $e->person_id,
                    'label' => $people[$e->person_id]['label'] ?? 'Fiche introuvable',
                    'can_open' => isset($openable[$e->person_id]),
                    // A trainee sent to the trash keeps her enrolments (restorable).
                    'trashed' => $people[$e->person_id]['trashed'] ?? false,
                ],
                'session' => $session === null ? null : [
                    'id' => $session->id,
                    'number' => $session->number,
                    'title' => $session->title,
                    'label' => $session->label($trainings[$session->training_id]->title ?? null),
                    'training' => $trainings[$session->training_id]->title ?? null,
                    'planned_hours' => SessionRows::hours($session->planned_hours),
                    'starts_on' => $session->starts_on->toDateString(),
                    'ends_on' => $session->ends_on->toDateString(),
                    'can_open' => isset($visibleSessions[$session->id]),
                    'cancelled' => $session->isCancelled(),
                ],
                'dossier' => ! $seesDossier ? null : [
                    'id' => $dossier->id,
                    'number' => $dossier->number,
                    'account' => $accounts[$dossier->accountId()]['label'] ?? null,
                ],
                'line' => $seesDossier && $e->line instanceof DossierLine ? ['id' => $e->line->id, 'label' => $e->line->label] : null,
                'status' => $e->status->value,
                'status_label' => $e->status->label(),
                'waitlist_position' => $e->waitlist_position,
                'entered_on' => $e->entered_on?->toDateString(),
                'left_on' => $e->left_on?->toDateString(),
                'abandon_reason' => $e->abandon_reason_id === null ? null : ($labels[$e->abandon_reason_id] ?? null),
                'planned_hours' => SessionRows::hours($e->planned_hours),
                // His planned hours before his individual planning (null: none): what « Heures prévues » edits then.
                'planned_hours_base' => SessionRows::hours($e->planned_hours_base),
                'price_share' => $seesDossier && $e->price_share !== null ? Amounts::display(Amounts::cents((string) $e->price_share)) : null,
                'bpf_category_id' => $e->bpf_category_id,
                'bpf_category' => $e->bpf_category_id === null ? null : ($labels[$e->bpf_category_id] ?? null),
                'adaptation_needs' => $canUpdate ? $e->adaptation_needs : null,
                'notes' => $canUpdate ? $e->notes : null,
                'can_update' => $canUpdate,
                // « Suivi »: planning, attendance and signatures of the trainee.
                'follow_up_url' => $session !== null && isset($visibleSessions[$session->id])
                    && ! in_array($e->status, [EnrolmentStatus::Cancelled, EnrolmentStatus::Waitlisted], true)
                    ? route('sessions.enrolments.follow-up', [$session->id, $e->id], false) : null,
            ];
        })->values()->all();
    }

    /**
     * The enrolments of a session: the waiting list in its order, then the
     * others by dossier and name order of arrival.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSession(TrainingSession $session, User $viewer): array
    {
        return self::rows(Enrolment::query()->where('training_session_id', $session->id)
            ->orderByRaw('waitlist_position nulls first')->orderBy('dossier_id')->orderBy('created_at')->orderBy('id')->get(), $viewer);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function forDossier(Dossier $dossier, User $viewer): array
    {
        return self::rows(Enrolment::query()->where('dossier_id', $dossier->id)
            ->orderBy('dossier_line_id')->orderBy('created_at')->orderBy('id')->get(), $viewer);
    }

    /**
     * « Formations suivies » of the fiche of a person: her enrolments on the
     * sessions the viewer sees (sessions.sessions.view and its scope); null
     * without that right.
     *
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>}|null
     */
    public static function personBlock(string $personId, User $viewer): ?array
    {
        if (! $viewer->can(Permissions::SESSIONS_VIEW)) {
            return null;
        }
        /** @var Builder<TrainingSession> $sessions */
        $sessions = SessionsVisibility::sessions(TrainingSession::query(), $viewer)->select('training_sessions.id');
        $enrolments = Enrolment::query()->where('person_id', $personId)->whereIn('training_session_id', $sessions)
            ->with('session')->get()
            ->sortByDesc(fn (Enrolment $e) => $e->session?->starts_on->toDateString())->values();
        $trainings = app(Trainings::class)->findMany($enrolments->map(fn (Enrolment $e) => (string) $e->session?->training_id)->unique()->values()->all());
        // His planning: next séances and placements of each enrolment.
        $planning = TraineePlanning::details($enrolments, TrainingSession::today());

        $today = TrainingSession::today()->toDateString();
        // The satisfaction of each enrolment, given by the questionnaires (EnrolmentSatisfaction).
        $satisfaction = EnrolmentSatisfaction::for($enrolments->pluck('id')->map(fn ($id) => (string) $id)->values()->all());

        return [
            'title' => 'Formations suivies',
            'empty' => 'Aucune inscription à une session.',
            'rows' => $enrolments->map(function (Enrolment $e) use ($trainings, $planning, $today, $satisfaction): array {
                $s = $e->session;
                // Lot 1e: the end of a training over, and its satisfaction (questionnaires).
                $over = $s !== null && $e->left_on === null && ! $s->isCancelled() && $s->ends_on->toDateString() < $today
                    ? "Terminée le {$s->ends_on->format('d/m/Y')} · Satisfaction : ".($satisfaction[$e->id] ?? '-') : null;

                return [
                    'id' => $e->id,
                    'title' => $s === null ? 'Session introuvable' : ($s->title ?? $trainings[$s->training_id]->title ?? 'Formation introuvable'),
                    'subtitle' => $s === null ? null : "Session {$s->number} · du {$s->starts_on->format('d/m/Y')} au {$s->ends_on->format('d/m/Y')}",
                    'meta' => $e->left_on === null ? $over : "Sortie le {$e->left_on->format('d/m/Y')}",
                    'badge' => $s?->isCancelled() ? 'Session annulée' : $e->status->label(),
                    'href' => $s === null ? null : route('sessions.show', $s->id, false),
                    'details' => $planning[$e->id] ?? [],
                ];
            })->all(),
        ];
    }
}
