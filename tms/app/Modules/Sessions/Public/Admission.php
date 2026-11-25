<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Attendance\TraineeFollowUp;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlacementPeriod;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What the Admissions module needs from Sessions (which never imports it):
 * the sessions a candidate may aim at, and the dossier created at the
 * admission (written by DossierWriter, Enrolments and FundingWriter).
 */
final class Admission
{
    /**
     * Sessions of $trainingId a candidate may aim at: planned or confirmed,
     * not finished (Paris days), out of the trash, by start date.
     *
     * @return list<array{id: string, label: string, starts_on: string}>
     */
    public static function sessionOptions(string $trainingId, ?string $dasId = null): array
    {
        if (! Str::isUuid($trainingId)) {
            return [];
        }
        $title = app(Trainings::class)->find($trainingId)?->title;

        return TrainingSession::query()->where('training_id', $trainingId)
            ->when($dasId !== null, fn ($q) => $q->where('das_id', $dasId))
            ->whereIn('status', [SessionStatus::Planned->value, SessionStatus::Confirmed->value])
            ->whereDate('ends_on', '>=', TrainingSession::today()->toDateString())
            ->orderBy('starts_on')->orderBy('number')->get()
            ->map(fn (TrainingSession $s) => ['id' => $s->id, 'label' => $s->label($title), 'starts_on' => $s->starts_on->toDateString()])
            ->values()->all();
    }

    /**
     * A session (trashed included) as a candidature shows it; null when unknown.
     *
     * @return array{id: string, label: string, training_id: string, das_id: string, kind: string, organisation_id: string|null, starts_on: string, ends_on: string, trashed: bool}|null
     */
    public static function session(string $sessionId): ?array
    {
        $session = Str::isUuid($sessionId) ? TrainingSession::withTrashed()->find($sessionId) : null;
        if ($session === null) {
            return null;
        }

        return [
            'id' => $session->id,
            'label' => $session->label(app(Trainings::class)->find($session->training_id)?->title),
            'training_id' => $session->training_id,
            'das_id' => $session->das_id,
            'kind' => $session->kind,
            'organisation_id' => $session->organisation_id,
            'starts_on' => $session->starts_on->toDateString(),
            'ends_on' => $session->ends_on->toDateString(),
            'trashed' => $session->trashed(),
        ];
    }

    /**
     * $user may create a dossier in DAS $dasId (sessions.dossiers.update and its scope).
     */
    public static function mayCreateDossier(User $user, string $dasId): bool
    {
        return SessionsVisibility::allowsDas($user, Permissions::DOSSIERS_UPDATE, $dasId);
    }

    /**
     * Ids of the dossiers out of the trash, as a subquery (whereIn).
     */
    public static function liveDossierIds(): Builder
    {
        return Dossier::query()->select('id')->toBase();
    }

    /**
     * Ids of the dossiers « of the trainer » linked to $user (the
     * apprenticeship booklet, « Ses séances »): those with a live enrolment
     * in a session holding a non-cancelled séance of that trainer; none
     * when the user is no trainer. A subquery (whereIn).
     */
    public static function trainerDossierIds(User $user): Builder
    {
        $trainer = Trainers::forUser($user);
        if ($trainer === null) {
            return Dossier::query()->select('id')->whereRaw('false')->toBase();
        }

        return Enrolment::query()->select('dossier_id')
            ->whereNotIn('status', [EnrolmentStatus::Cancelled->value, EnrolmentStatus::Waitlisted->value])
            ->whereIn('training_session_id', Seance::query()->select('training_session_id')
                ->where('trainer_id', $trainer->id)->where('status', '<>', Seance::CANCELLED))
            ->toBase();
    }

    /**
     * « live », « trashed », or null when the dossier is gone.
     */
    public static function dossierState(string $dossierId): ?string
    {
        $dossier = Str::isUuid($dossierId) ? Dossier::withTrashed()->find($dossierId) : null;

        return $dossier === null ? null : ($dossier->trashed() ? 'trashed' : 'live');
    }

    /**
     * Creates the dossier of an admitted candidate in one transaction: one
     * training line priced per trainee, the enrolment of the person on the
     * session when one is given, the funding when one is given (it pays the
     * organisme directly). Validation errors of the writers bubble unchanged.
     *
     * @param  array{account: array{type: string, id: string}, company_id?: ?string, das_id: string, responsible_user_id?: ?string,
     *     training_id: string, label: string, quantity: string, unit: string, unit_price: string, session_id?: ?string, person_id: string,
     *     funding?: ?array{funder_organisation_id: string, funding_type_id: string, amount: string}}  $data
     * @param  User|null  $by  who creates it: the other modules act once it is committed (DossierHooks)
     * @return string the dossier id
     */
    public static function createDossier(array $data, ?User $by = null): string
    {
        return DB::transaction(function () use ($data, $by): string {
            $account = $data['account'];
            $dossier = app(DossierWriter::class)->create([
                'company_id' => $data['company_id'] ?? DossierWriter::defaultCompanyId(),
                'account_organisation_id' => $account['type'] === 'organisation' ? $account['id'] : null,
                'account_person_id' => $account['type'] === 'person' ? $account['id'] : null,
                'das_id' => $data['das_id'],
                'responsible_user_id' => $data['responsible_user_id'] ?? null,
                'lines' => [[
                    'kind' => DossierLine::TRAINING, 'training_id' => $data['training_id'], 'label' => $data['label'],
                    'quantity' => $data['quantity'], 'unit' => $data['unit'], 'unit_price' => $data['unit_price'],
                    'discount_percent' => '', 'pricing_mode' => DossierLine::PER_TRAINEE,
                ]],
            ]);

            if (($data['session_id'] ?? null) !== null) {
                $session = Str::isUuid($data['session_id']) ? TrainingSession::query()->find($data['session_id']) : null;
                if ($session === null || $session->training_id !== $data['training_id']) {
                    throw ValidationException::withMessages(['session_id' => 'Choisissez une session de la formation.']);
                }
                try {
                    app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, $data['person_id']);
                } catch (ValidationException $e) {
                    // The admission form has one « session » field: every refusal of the enrolment shows there.
                    throw ValidationException::withMessages(['session_id' => array_values(array_unique(array_merge(...array_values($e->errors()))))]);
                }
            }

            if (($data['funding'] ?? null) !== null) {
                app(FundingWriter::class)->create($dossier, [
                    'funder_organisation_id' => $data['funding']['funder_organisation_id'],
                    'funder_person_id' => null,
                    'funding_type_id' => $data['funding']['funding_type_id'],
                    'dossier_line_id' => null,
                    'enrolment_id' => null,
                    'amount' => $data['funding']['amount'],
                    'status' => 'requested',
                    'agreement_number' => null,
                    'agreed_on' => null,
                    'notes' => null,
                    'pays_directly' => true,
                ]);
            }

            if ($by !== null) {
                DossierHooks::createdFromCandidature($dossier->id, $by);
            }

            return $dossier->id;
        });
    }

    /**
     * What the apprenticeship booklet prints of a trainee's session: the
     * session, the planned séances (between his entry and leaving days) by
     * month of Paris and in total, his trainers with the modules they teach,
     * and his placements (at his own dates) with their host. Null when the
     * person has no live enrolment in the dossier.
     *
     * @return array{session: string, starts_on: string, ends_on: string, centre_minutes: int, months: array<string, int>, teachers: list<array{name: string, modules: list<string>}>, placements: list<array{label: string, from: string, to: string, host: ?string}>}|null
     */
    public static function booklet(string $dossierId, string $personId): ?array
    {
        if (! Str::isUuid($dossierId) || ! Str::isUuid($personId)) {
            return null;
        }
        $enrolment = Enrolment::query()->where('dossier_id', $dossierId)->where('person_id', $personId)
            ->whereNotIn('status', [EnrolmentStatus::Cancelled->value, EnrolmentStatus::Waitlisted->value])
            ->orderByDesc('created_at')->first();
        $session = $enrolment === null ? null : TrainingSession::withTrashed()->find($enrolment->training_session_id);
        if ($enrolment === null || $session === null) {
            return null;
        }
        $from = $enrolment->entered_on?->toDateString();
        $to = $enrolment->left_on?->toDateString();
        $seances = Seance::query()->where('training_session_id', $session->id)->where('status', Seance::PLANNED)
            ->when($from !== null, fn ($q) => $q->where('date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('date', '<=', $to))
            ->orderBy('date')->orderBy('starts_at')->get(['id', 'date', 'session_module_id', 'trainer_id', 'duration_minutes']);

        $months = [];
        foreach ($seances as $seance) {
            $month = $seance->date->format('Y-m');
            $months[$month] = ($months[$month] ?? 0) + (int) $seance->duration_minutes;
        }
        $modules = SessionModule::withTrashed()->whereIn('id', $seances->pluck('session_module_id')->unique()->values()->all())->pluck('title', 'id');
        $trainers = Trainer::withTrashed()->whereIn('id', $seances->pluck('trainer_id')->filter()->unique()->values()->all())->pluck('person_id', 'id');
        $names = app(Fiches::class)->personLabels(array_values(array_map('strval', $trainers->all())));
        $taught = [];
        foreach ($seances as $seance) {
            if ($seance->trainer_id === null || ! isset($trainers[$seance->trainer_id])) {
                continue;
            }
            $name = $names[$trainers[$seance->trainer_id]]['label'] ?? null;
            if ($name === null) {
                continue;
            }
            $taught[$name] ??= [];
            $title = $modules[$seance->session_module_id] ?? null;
            if ($title !== null && ! in_array($title, $taught[$name], true)) {
                $taught[$name][] = $title;
            }
        }

        $periods = PlacementPeriod::query()->with('hosts')->where('training_session_id', $session->id)->orderBy('starts_on')->orderBy('id')->get();
        $hostIds = $periods->flatMap(fn (PlacementPeriod $p) => $p->hosts)->where('enrolment_id', $enrolment->id)->pluck('organisation_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $hosts = app(Fiches::class)->organisationLabels($hostIds);
        $placements = $periods->map(function (PlacementPeriod $period) use ($enrolment, $hosts): array {
            $dates = PlacementPeriods::datesFor($period, $enrolment->id);
            $host = $period->hosts->firstWhere('enrolment_id', $enrolment->id);

            return [
                'label' => $period->label,
                'from' => $dates['from'],
                'to' => $dates['to'],
                'host' => $host?->organisation_id === null ? null : ($hosts[$host->organisation_id]['label'] ?? null),
            ];
        })->values()->all();

        return [
            'session' => $session->label(app(Trainings::class)->find($session->training_id)?->title),
            'starts_on' => $session->starts_on->toDateString(),
            'ends_on' => $session->ends_on->toDateString(),
            'centre_minutes' => (int) $seances->sum('duration_minutes'),
            'months' => $months,
            'teachers' => array_map(fn (string $name, array $mods) => ['name' => $name, 'modules' => $mods], array_keys($taught), array_values($taught)),
            'placements' => $placements,
        ];
    }

    /**
     * The individual follow-up of the person in the dossier, as $user may see
     * it (TraineeFollowUp): the séances of his session; with the attendance
     * right, his attendance, realised hours and signatures. Null when the
     * person has no live enrolment there or $user does not see the session.
     *
     * @return array<string, mixed>|null
     */
    public static function followUp(string $dossierId, string $personId, User $user): ?array
    {
        return TraineeFollowUp::of($dossierId, $personId, $user);
    }
}
