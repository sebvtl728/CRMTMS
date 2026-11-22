<?php

namespace App\Modules\Sessions\Public;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsVisibility;
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
     * @return string the dossier id
     */
    public static function createDossier(array $data): string
    {
        return DB::transaction(function () use ($data): string {
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

            return $dossier->id;
        });
    }
}
