<?php

namespace App\Modules\Sessions\Sessions;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes a training session: validation with French messages (the one
 * place, for the form and for the other writers such as the dossier
 * assistant), number at creation in the same transaction, DAS always the
 * training's, hours the training's unless typed. Intra: an entreprise of the
 * CRM (Crm\Public\Fiches, not trashed), no places; inter: places ≥ 1, no
 * entreprise; individual: no places, entreprise optional. Every session has a
 * modality (« Modalités de formation »): required, « Présentiel » when the key
 * is missing on creation, unchanged when missing on update. A cancelled session no longer changes (409); a trashed intra
 * session comes back only with its entreprise (409). Once trainees are
 * enrolled (cancelled enrolments aside), its training, type and entreprise
 * no longer change and it does not go to the trash (409); cancelling it
 * cancels its enrolments. Lowering its hours lowers those of the trainees
 * planned above them (journaled).
 *
 * @phpstan-type SessionData array<string, mixed>
 */
final class SessionWriter
{
    public const CANCELLED = 'Une session annulée ne se modifie plus.';

    public const ALREADY_CANCELLED = 'Cette session est déjà annulée.';

    public const ORGANISATION_GONE = 'L\'entreprise de cette session intra est à la corbeille : restaurez-la d\'abord.';

    public const LOCKED = 'Des stagiaires sont inscrits à cette session : sa formation, son type et son entreprise ne changent plus.';

    public const ENROLLED = 'Des stagiaires sont inscrits à cette session : désinscrivez-les avant de la mettre à la corbeille.';

    public function __construct(
        private readonly SessionNumbering $numbering,
        private readonly Trainings $trainings,
        private readonly Fiches $fiches,
    ) {}

    /**
     * @param  SessionData  $data
     *
     * @throws ValidationException
     */
    public function create(array $data): TrainingSession
    {
        // Creation without the key (other writers, imports): the default modality.
        if (! array_key_exists('modality_id', $data)) {
            $data['modality_id'] = self::defaultModalityId();
        }
        $values = $this->validated($data, null);

        return DB::transaction(function () use ($values): TrainingSession {
            $session = new TrainingSession($values);
            $session->number = $this->numbering->next(CarbonImmutable::now());
            $session->save();

            return $session;
        });
    }

    /**
     * @param  SessionData  $data
     *
     * @throws ValidationException
     */
    public function update(TrainingSession $session, array $data): TrainingSession
    {
        if ($session->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
        if (! array_key_exists('modality_id', $data)) {
            $data['modality_id'] = $session->modality_id;
        }
        if (! array_key_exists('title', $data)) {
            $data['title'] = $session->title;
        }
        $values = $this->validated($data, $session);

        return DB::transaction(function () use ($session, $values): TrainingSession {
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            // Under the lock Enrolments takes too: no enrolment slips in meanwhile.
            $moved = $values['training_id'] !== $locked->training_id || $values['kind'] !== $locked->kind
                || $values['organisation_id'] !== $locked->organisation_id;
            if ($moved && self::hasEnrolments($locked)) {
                throw new UserFacingHttpException(409, self::LOCKED);
            }
            $lowered = $values['planned_hours'] !== null && ($locked->planned_hours === null
                || Amounts::cents((string) $values['planned_hours']) < Amounts::cents($locked->planned_hours));
            $locked->update($values);
            if ($lowered) {
                self::clampEnrolmentHours($locked);
            }

            return $locked;
        });
    }

    /**
     * The session's hours went down: the trainees planned above them are
     * brought down to them, one by one (journaled), under the session lock.
     */
    private static function clampEnrolmentHours(TrainingSession $session): void
    {
        $hours = (string) $session->planned_hours;
        Enrolment::query()->where('training_session_id', $session->id)->where('planned_hours', '>', $hours)
            ->orderBy('id')->lockForUpdate()->get()
            ->each(function (Enrolment $enrolment) use ($hours): void {
                $enrolment->planned_hours = $hours;
                $enrolment->save();
            });
    }

    /**
     * Puts the session in the trash, unless trainees are enrolled on it (409).
     */
    public function trash(TrainingSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = TrainingSession::query()->lockForUpdate()->findOrFail($session->id);
            if (self::hasEnrolments($locked)) {
                throw new UserFacingHttpException(409, self::ENROLLED);
            }
            $locked->delete();
        });
    }

    /**
     * Enrolments other than cancelled ones hang on the session.
     */
    public static function hasEnrolments(TrainingSession $session): bool
    {
        return Enrolment::query()->where('training_session_id', $session->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value)->exists();
    }

    /**
     * Cancels with a reason of the « Motifs d'annulation » list, today (Paris).
     *
     * @throws ValidationException
     */
    public function cancel(TrainingSession $session, ?string $reasonId): TrainingSession
    {
        // The state first: a cancelled session answers 409 whatever the reason.
        if ($session->isCancelled()) {
            throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
        }
        Validator::make(['cancel_reason_id' => $reasonId], [
            'cancel_reason_id' => ['bail', 'required', ...self::referenceRule(ReferenceList::CancelReason)],
        ], [
            'cancel_reason_id.required' => 'Choisissez le motif d\'annulation.',
            'cancel_reason_id.*' => 'Motif d\'annulation inconnu.',
        ])->validate();

        return DB::transaction(function () use ($session, $reasonId): TrainingSession {
            // Dossiers of its trainees, then the session (the order of Enrolments).
            $enrolments = app(Enrolments::class);
            $locked = $enrolments->lockSession($session);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
            }
            $locked->status = SessionStatus::Cancelled;
            $locked->cancel_reason_id = $reasonId;
            $locked->cancelled_on = TrainingSession::today();
            $locked->save();
            // Its trainees are unenrolled (shares of their lines computed again).
            $enrolments->cancelAllOfSession($locked);

            return $locked;
        });
    }

    /**
     * Restores a trashed session; an intra session only while its entreprise
     * is live (out of the trash): otherwise 409.
     */
    public function restore(TrainingSession $session): TrainingSession
    {
        return DB::transaction(function () use ($session): TrainingSession {
            $locked = TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
            if ($locked->organisation_id !== null && $this->fiches->organisation($locked->organisation_id) === null) {
                throw new UserFacingHttpException(409, self::ORGANISATION_GONE);
            }
            $locked->restore();
            $session->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * The columns to write, from untrusted input.
     *
     * @param  SessionData  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validated(array $data, ?TrainingSession $current): array
    {
        $data = self::normalise($data);

        $validator = Validator::make($data, [
            'title' => ['nullable', 'string', 'max:150'],
            'training_id' => ['bail', 'required', 'uuid'],
            'kind' => ['required', Rule::enum(SessionKind::class)],
            'organisation_id' => ['bail', 'nullable', 'required_if:kind,intra', 'uuid'],
            'modality_id' => ['bail', 'required', ...self::referenceRule(ReferenceList::TrainingModality, $current?->modality_id)],
            'starts_on' => ['bail', 'required', 'date_format:Y-m-d'],
            'ends_on' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'planned_hours' => ['nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'location_kind' => ['required', Rule::enum(SessionLocation::class)],
            'location_text' => ['nullable', 'required_if:location_kind,address', 'string', 'max:500'],
            'capacity' => ['nullable', 'required_if:kind,inter', 'integer', 'between:1,1000'],
            'responsible_user_id' => ['nullable', 'bail', 'uuid', Rule::exists('users', 'id')
                ->where('tenant_id', app(TenantContext::class)->id())
                ->where(fn ($q) => $q->whereNull('deactivated_at')->when($current?->responsible_user_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'status' => ['required', Rule::in(array_map(fn (SessionStatus $s) => $s->value, SessionStatus::SETTABLE))],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], self::messages());

        $training = null;
        $validator->after(function ($v) use ($data, $current, &$training): void {
            if ($v->errors()->has('training_id')) {
                return;
            }
            $training = $this->trainings->find((string) $data['training_id']);
            if ($training === null) {
                $v->errors()->add('training_id', 'Formation introuvable.');
            } elseif (! $training->is_active && $training->id !== $current?->training_id) {
                $v->errors()->add('training_id', 'Cette formation est désactivée.');
            }
            if (in_array($data['kind'] ?? null, [SessionKind::Intra->value, SessionKind::Individual->value], true) && is_string($data['organisation_id'] ?? null)
                && ! $v->errors()->has('organisation_id') && $this->fiches->organisation($data['organisation_id']) === null) {
                $v->errors()->add('organisation_id', 'Entreprise introuvable.');
            }
        });
        $validator->validate();
        assert($training !== null);

        $kind = SessionKind::from($data['kind']);
        $hours = $data['planned_hours'] ?? null;
        if ($hours === null && ($current === null || $current->training_id !== $training->id)) {
            $hours = $training->duration_hours;
        }

        return [
            'title' => $data['title'] ?? null,
            'training_id' => $training->id,
            'das_id' => $training->das_id,
            'kind' => $data['kind'],
            'modality_id' => $data['modality_id'],
            'organisation_id' => $kind === SessionKind::Inter ? null : $data['organisation_id'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'planned_hours' => $hours,
            'location_kind' => $data['location_kind'],
            'location_text' => $data['location_text'] ?? null,
            'capacity' => $kind === SessionKind::Inter ? (int) $data['capacity'] : null,
            'responsible_user_id' => $data['responsible_user_id'] ?? ($current === null ? Auth::id() : null),
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * Trimmed strings, empty ones as null, French decimals (« 17,5 ») as « 17.5 ».
     *
     * @param  SessionData  $data
     * @return SessionData
     */
    private static function normalise(array $data): array
    {
        foreach (['title', 'training_id', 'kind', 'organisation_id', 'modality_id', 'starts_on', 'ends_on', 'planned_hours', 'location_kind', 'location_text', 'capacity', 'responsible_user_id', 'status', 'notes'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $data[$key] = $value;
        }
        if (is_string($data['planned_hours'])) {
            $data['planned_hours'] = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $data['planned_hours']);
        }

        return $data;
    }

    /**
     * An active item of $list in the organisme.
     *
     * @return list<mixed>
     */
    private static function referenceRule(ReferenceList $list, ?string $keep = null): array
    {
        return ['uuid', Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q2, $id) => $q2->orWhere('id', $id)))];
    }

    /**
     * The « Présentiel » item of the organisme (null when it deleted it).
     */
    public static function defaultModalityId(): ?string
    {
        $id = ReferenceItem::query()->where('list', ReferenceList::TrainingModality)->where('code', 'presentiel')
            ->where('is_active', true)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'title.*' => 'L\'intitulé ne doit pas dépasser 150 caractères.',
            'training_id.required' => 'Choisissez la formation.',
            'training_id.uuid' => 'Formation introuvable.',
            'kind.required' => 'Choisissez le type de session.',
            'kind.*' => 'Type de session inconnu.',
            'modality_id.required' => 'Choisissez la modalité de formation.',
            'modality_id.*' => 'Modalité de formation inconnue.',
            'organisation_id.required_if' => 'Choisissez l\'entreprise de la session intra.',
            'organisation_id.uuid' => 'Entreprise introuvable.',
            'starts_on.required' => 'Indiquez la date de début.',
            'starts_on.date_format' => 'Date de début invalide.',
            'ends_on.required' => 'Indiquez la date de fin.',
            'ends_on.date_format' => 'Date de fin invalide.',
            'ends_on.after_or_equal' => 'La date de fin doit être postérieure ou égale à la date de début.',
            'planned_hours.regex' => 'Heures prévues invalides (ex. 21 ou 17,5).',
            'location_kind.required' => 'Choisissez le lieu.',
            'location_kind.*' => 'Lieu inconnu.',
            'location_text.required_if' => 'Indiquez l\'adresse.',
            'location_text.max' => 'Le lieu ne doit pas dépasser 500 caractères.',
            'capacity.required_if' => 'Indiquez le nombre de places de la session inter.',
            'capacity.*' => 'Le nombre de places doit être compris entre 1 et 1 000.',
            'responsible_user_id.*' => 'Responsable inconnu.',
            'status.required' => 'Choisissez l\'état.',
            'status.*' => 'État inconnu.',
            'notes.max' => 'Les notes ne doivent pas dépasser 5 000 caractères.',
        ];
    }
}
