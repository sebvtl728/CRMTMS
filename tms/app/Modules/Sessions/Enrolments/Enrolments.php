<?php

namespace App\Modules\Sessions\Enrolments;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Sessions\SessionKind;
use App\Modules\Sessions\Sessions\SessionRows;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Enrolments of trainees (spec lot 2a § 5.4, § 5.6): the one writer of
 * their states. Every change locks the dossier row, then the session row
 * (the order DossierWriter follows too), so that the places of an inter
 * session, the waiting list and the shares of the line (PriceShares) are
 * read and written by one request at a time.
 *
 *  - enrol(): a training line of the dossier whose training is the
 *    session's; an intra session takes the dossiers of its entreprise only;
 *    a cancelled session or dossier takes nobody; a trainee once per
 *    session. An inter session full (enrolled + confirmed ≥ places) puts
 *    the enrolment on the waiting list, in order of arrival;
 *    An individual session takes one trainee (cancelled enrolments aside),
 *    from any contractant, and never has a waiting list;
 *  - promote(): manual, a waitlisted enrolment while a place is free;
 *  - abandon(): a leaving day and a reason, the share kept;
 *  - cancel(): the place freed, nobody promoted automatically;
 *  - planned hours: the session's by default, else 0 < h ≤ the session's
 *    (any positive value when the session has none); GroupEnrolment enrols
 *    several trainees in one transaction through enrol().
 */
final class Enrolments
{
    public const DOSSIER_CLOSED = 'Ce dossier est annulé ou à la corbeille : il ne reçoit plus d\'inscription.';

    public const SESSION_CLOSED = 'Cette session est annulée ou à la corbeille : elle ne reçoit plus d\'inscription.';

    public const INDIVIDUAL_TAKEN = 'Une session individuelle n\'accueille qu\'un stagiaire.';

    public const NOT_WAITLISTED = 'Seule une inscription en attente peut être promue.';

    public const FULL = 'La session est complète : ajoutez des places ou désinscrivez un stagiaire avant de promouvoir.';

    public const NOT_ENROLLED = 'Seule une inscription « Inscrit » peut être confirmée.';

    public const NOT_ATTENDING = 'Seul un stagiaire inscrit ou confirmé peut abandonner.';

    public const ALREADY_CANCELLED = 'Cette inscription est déjà annulée.';

    public const CANCELLED = 'Une inscription annulée ne se modifie plus.';

    /** An enrolment of a dossier not locked arrived between the two locks of lockSession(). */
    public const ENROLMENT_ADDED = 'Une inscription vient d\'être ajoutée : réessayez.';

    public const HOURS_INVALID = 'Heures prévues invalides (ex. 21 ou 17,5).';

    public const HOURS_ZERO = 'Les heures prévues doivent être supérieures à zéro.';

    public function __construct(private readonly Fiches $fiches) {}

    /**
     * @param  array<string, mixed>  $extra  entered_on (Y-m-d, default the session start), planned_hours (default the session's), bpf_category_id, adaptation_needs, notes
     *
     * @throws ValidationException
     */
    public function enrol(Dossier $dossier, DossierLine $line, TrainingSession $session, string $personId, array $extra = []): Enrolment
    {
        $values = $this->validatedExtra($extra, null);

        return DB::transaction(function () use ($dossier, $line, $session, $personId, $values): Enrolment {
            $dossier = Dossier::withTrashed()->lockForUpdate()->findOrFail($dossier->id);
            $session = TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);
            if ($dossier->isCancelled() || $dossier->trashed()) {
                throw new UserFacingHttpException(409, self::DOSSIER_CLOSED);
            }
            if ($session->isCancelled() || $session->trashed()) {
                throw new UserFacingHttpException(409, self::SESSION_CLOSED);
            }

            $line = DossierLine::query()->where('dossier_id', $dossier->id)->find($line->id);
            $errors = [];
            if ($line === null) {
                $errors['dossier_line_id'] = 'Ligne du dossier introuvable.';
            } elseif (! $line->isTraining()) {
                $errors['dossier_line_id'] = 'Seule une ligne de formation reçoit des inscriptions.';
            } elseif ($line->training_id !== $session->training_id) {
                $errors['training_session_id'] = 'La formation de cette session n\'est pas celle de la ligne du dossier.';
            }
            if ($session->kind === SessionKind::Intra->value && $dossier->account_organisation_id !== $session->organisation_id) {
                $errors['training_session_id'] = 'Cette session intra est réservée à son entreprise : le contractant de ce dossier est différent.';
            }
            if ($session->kind === SessionKind::Individual->value && SessionWriter::hasEnrolments($session)) {
                $errors['training_session_id'] = self::INDIVIDUAL_TAKEN;
            }
            if ($this->fiches->person($personId) === null) {
                $errors['person_id'] = 'Stagiaire introuvable.';
            } elseif (Enrolment::query()->where('training_session_id', $session->id)->where('person_id', $personId)
                ->where('status', '<>', EnrolmentStatus::Cancelled->value)->exists()) {
                $errors['person_id'] = 'Ce stagiaire est déjà inscrit à cette session.';
            }
            $hoursError = self::hoursError($values['planned_hours'], $session);
            if ($hoursError !== null) {
                $errors['planned_hours'] = $hoursError;
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            assert($line !== null);

            $full = $session->kind === SessionKind::Inter->value && $this->placesTaken($session) >= (int) $session->capacity;
            $enrolment = Enrolment::query()->create([
                ...$values,
                'person_id' => $personId,
                'training_session_id' => $session->id,
                'dossier_id' => $dossier->id,
                'dossier_line_id' => $line->id,
                'status' => $full ? EnrolmentStatus::Waitlisted : EnrolmentStatus::Enrolled,
                'waitlist_position' => $full ? $this->nextWaitlistPosition($session) : null,
                'entered_on' => $values['entered_on'] ?? $session->starts_on->toDateString(),
                'planned_hours' => $values['planned_hours'] ?? $session->planned_hours,
            ]);
            PriceShares::recompute($line);

            return $enrolment->refresh();
        });
    }

    /**
     * A waitlisted enrolment takes a free place (manual promotion).
     */
    public function promote(Enrolment $enrolment): Enrolment
    {
        return $this->locked($enrolment, function (Enrolment $locked, TrainingSession $session): void {
            if ($locked->status !== EnrolmentStatus::Waitlisted) {
                throw new UserFacingHttpException(409, self::NOT_WAITLISTED);
            }
            if ($session->isCancelled() || $session->trashed()) {
                throw new UserFacingHttpException(409, self::SESSION_CLOSED);
            }
            if ($session->kind === SessionKind::Inter->value && $this->placesTaken($session) >= (int) $session->capacity) {
                throw new UserFacingHttpException(409, self::FULL);
            }
            $locked->status = EnrolmentStatus::Enrolled;
            $locked->waitlist_position = null;
            $locked->save();
        });
    }

    public function confirm(Enrolment $enrolment): Enrolment
    {
        return $this->locked($enrolment, function (Enrolment $locked): void {
            if ($locked->status !== EnrolmentStatus::Enrolled) {
                throw new UserFacingHttpException(409, self::NOT_ENROLLED);
            }
            $locked->status = EnrolmentStatus::Confirmed;
            $locked->save();
        });
    }

    /**
     * The trainee leaves on $leftOn for a reason of « Motifs d'abandon »;
     * his share of the line stays his.
     *
     * @throws ValidationException
     */
    public function abandon(Enrolment $enrolment, CarbonImmutable $leftOn, string $reasonId): Enrolment
    {
        Validator::make(['abandon_reason_id' => $reasonId], [
            'abandon_reason_id' => ['bail', 'required', 'uuid', self::referenceRule(ReferenceList::AbandonReason)],
        ], [
            'abandon_reason_id.required' => 'Choisissez le motif d\'abandon.',
            'abandon_reason_id.*' => 'Motif d\'abandon inconnu.',
        ])->validate();

        return $this->locked($enrolment, function (Enrolment $locked) use ($leftOn, $reasonId): void {
            if (! in_array($locked->status, EnrolmentStatus::HOLDING_A_PLACE, true)) {
                throw new UserFacingHttpException(409, self::NOT_ATTENDING);
            }
            if ($locked->entered_on !== null && $leftOn->toDateString() < $locked->entered_on->toDateString()) {
                throw ValidationException::withMessages(['left_on' => 'La date de sortie ne peut pas précéder la date d\'entrée.']);
            }
            $locked->status = EnrolmentStatus::Abandoned;
            $locked->left_on = $leftOn;
            $locked->abandon_reason_id = $reasonId;
            $locked->save();
        });
    }

    /**
     * The enrolment is cancelled: its place is freed, nobody is promoted
     * (the waiting list is promoted by hand), the shares of the line are
     * computed again.
     */
    public function cancel(Enrolment $enrolment): Enrolment
    {
        return $this->locked($enrolment, function (Enrolment $locked): void {
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
            }
            $locked->status = EnrolmentStatus::Cancelled;
            $locked->waitlist_position = null;
            $locked->save();
        });
    }

    /**
     * Entry day, planned hours (empty: the session's), BPF category,
     * adaptation needs and notes.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Enrolment $enrolment, array $data): Enrolment
    {
        if ($enrolment->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
        // Only the keys sent change: the others keep their stored value.
        $values = array_intersect_key($this->validatedExtra($data, $enrolment), $data);

        return $this->locked($enrolment, function (Enrolment $locked, TrainingSession $session) use ($values): void {
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            if (array_key_exists('planned_hours', $values)) {
                $values['planned_hours'] ??= $session->planned_hours;
                $hoursError = self::hoursError($values['planned_hours'], $session);
                if ($hoursError !== null) {
                    throw ValidationException::withMessages(['planned_hours' => $hoursError]);
                }
            }
            if (array_key_exists('entered_on', $values) && $values['entered_on'] === null) {
                unset($values['entered_on']);
            }
            $entered = $values['entered_on'] ?? $locked->entered_on?->toDateString();
            if ($locked->left_on !== null && $entered !== null && $entered > $locked->left_on->toDateString()) {
                throw ValidationException::withMessages(['entered_on' => 'La date d\'entrée ne peut pas suivre la date de sortie.']);
            }
            $locked->fill($values)->save();
        });
    }

    /**
     * The dossier is being cancelled ($dossier locked by the caller, in its
     * transaction): every enrolment not yet cancelled is cancelled, its
     * sessions locked in id order (dossier → session, as everywhere), the
     * shares of its lines computed again. Journaled per enrolment.
     */
    public function cancelAllOfDossier(Dossier $dossier): void
    {
        $active = fn () => Enrolment::query()->where('dossier_id', $dossier->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value);
        $sessionIds = $active()->distinct()->orderBy('training_session_id')->pluck('training_session_id')->all();
        TrainingSession::withTrashed()->whereKey($sessionIds)->orderBy('id')->lockForUpdate()->get();
        $this->cancelEach($active()->lockForUpdate()->get());
    }

    /**
     * The session is being cancelled (in the caller's transaction): the
     * dossiers of its enrolments are locked in id order, then the session,
     * then every enrolment not yet cancelled is cancelled and the shares of
     * the lines concerned computed again.
     */
    public function cancelAllOfSession(TrainingSession $session): void
    {
        $this->lockSession($session);
        $this->cancelEach(Enrolment::query()->where('training_session_id', $session->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value)->lockForUpdate()->get());
    }

    /**
     * Locks, in the caller's transaction, the dossiers of the enrolments of
     * $session in id order, then the session (the order Enrolments follows):
     * returns the session locked.
     */
    public function lockSession(TrainingSession $session): TrainingSession
    {
        $dossierIds = fn (): array => Enrolment::query()->where('training_session_id', $session->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value)->distinct()->pluck('dossier_id')->all();
        $locked = $dossierIds();
        Dossier::withTrashed()->whereKey($locked)->orderBy('id')->lockForUpdate()->get();
        $session = TrainingSession::withTrashed()->lockForUpdate()->findOrFail($session->id);

        // An enrolment of another dossier may have been committed between the
        // read above and the session lock (enrolling locks its dossier, then
        // the session): its dossier is not locked, so the caller stops (rare).
        if (array_diff($dossierIds(), $locked) !== []) {
            throw new UserFacingHttpException(409, self::ENROLMENT_ADDED);
        }

        return $session;
    }

    /**
     * @param  Collection<int, Enrolment>  $enrolments
     */
    private function cancelEach($enrolments): void
    {
        foreach ($enrolments as $enrolment) {
            $enrolment->status = EnrolmentStatus::Cancelled;
            $enrolment->waitlist_position = null;
            $enrolment->save();
        }
        DossierLine::query()->whereKey($enrolments->pluck('dossier_line_id')->unique()->values()->all())->get()
            ->each(fn (DossierLine $line) => PriceShares::recompute($line));
    }

    /**
     * Places of the session held (enrolled and confirmed).
     */
    public function placesTaken(TrainingSession $session): int
    {
        return Enrolment::query()->where('training_session_id', $session->id)
            ->whereIn('status', EnrolmentStatus::values(EnrolmentStatus::HOLDING_A_PLACE))->count();
    }

    /**
     * Locks the dossier, then the session, then the enrolment; applies
     * $change and computes the shares of the line again.
     *
     * @param  callable(Enrolment, TrainingSession): void  $change
     */
    private function locked(Enrolment $enrolment, callable $change): Enrolment
    {
        return DB::transaction(function () use ($enrolment, $change): Enrolment {
            Dossier::withTrashed()->lockForUpdate()->findOrFail($enrolment->dossier_id);
            $session = TrainingSession::withTrashed()->lockForUpdate()->findOrFail($enrolment->training_session_id);
            $locked = Enrolment::query()->lockForUpdate()->findOrFail($enrolment->id);
            $change($locked, $session);
            $line = DossierLine::query()->find($locked->dossier_line_id);
            if ($line !== null) {
                PriceShares::recompute($line);
            }
            $enrolment->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $locked;
        });
    }

    private function nextWaitlistPosition(TrainingSession $session): int
    {
        return (int) Enrolment::query()->where('training_session_id', $session->id)
            ->where('status', EnrolmentStatus::Waitlisted->value)->max('waitlist_position') + 1;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validatedExtra(array $data, ?Enrolment $current): array
    {
        foreach (['entered_on', 'planned_hours', 'bpf_category_id', 'adaptation_needs', 'notes'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $data[$key] = $value === '' ? null : $value;
        }
        if (is_string($data['planned_hours'])) {
            // French decimals (« 17,5 »).
            $data['planned_hours'] = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $data['planned_hours']);
        }

        /** @var array{entered_on: ?string, planned_hours: ?string, bpf_category_id: ?string, adaptation_needs: ?string, notes: ?string} $validated */
        $validated = Validator::make($data, [
            'entered_on' => ['nullable', 'date_format:Y-m-d'],
            'planned_hours' => ['nullable', 'regex:/^\d{1,4}(\.\d{1,2})?$/'],
            'bpf_category_id' => ['nullable', 'bail', 'uuid', self::referenceRule(ReferenceList::BpfCategory, $current?->bpf_category_id)],
            'adaptation_needs' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'entered_on.*' => 'Date d\'entrée invalide.',
            'planned_hours.*' => self::HOURS_INVALID,
            'bpf_category_id.*' => 'Catégorie BPF inconnue.',
            'adaptation_needs.*' => 'Les besoins d\'adaptation ne doivent pas dépasser 2 000 caractères.',
            'notes.*' => 'Les notes ne doivent pas dépasser 5 000 caractères.',
        ])->validate();

        return [
            'entered_on' => $validated['entered_on'] ?? null,
            'planned_hours' => $validated['planned_hours'] ?? null,
            'bpf_category_id' => $validated['bpf_category_id'] ?? null,
            'adaptation_needs' => $validated['adaptation_needs'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * The planned hours of a trainee (null: the session's) are above zero
     * and at most the session's, compared in hundredths (never a float).
     */
    private static function hoursError(?string $hours, TrainingSession $session): ?string
    {
        if ($hours === null) {
            return null;
        }
        if (Amounts::cents($hours) <= 0) {
            return self::HOURS_ZERO;
        }
        if ($session->planned_hours !== null && Amounts::cents($hours) > Amounts::cents($session->planned_hours)) {
            return 'Les heures prévues ne peuvent pas dépasser celles de la session ('.SessionRows::hours($session->planned_hours).' h).';
        }

        return null;
    }

    /**
     * An active item of $list in the organisme, or the one already stored.
     */
    private static function referenceRule(ReferenceList $list, ?string $keep = null): mixed
    {
        return Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q2, $id) => $q2->orWhere('id', $id)));
    }
}
