<?php

namespace App\Modules\Sessions\Dossiers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Enrolments\Enrolments;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Changes of state of a dossier (spec § 5.3), each on the locked row
 * (lockForUpdate) and journaled by the audit trail of the model (status
 * and dates, old and new values):
 *
 *  - the three manual steps of 2a, in order, with the day they happened
 *    (not before the previous step): convention sent, convention signed,
 *    convocations sent (2c will set them when generating the documents);
 *  - « En cours » and « Réalisé » set by hand (then no longer computed from
 *    the sessions);
 *  - cancellation with a reason of the « Motifs d'annulation » list, from
 *    any state before « Réalisé ».
 *
 * A refused transition is a 409 with a French message.
 */
final class DossierTransitions
{
    public const CANCELLED = 'Ce dossier est annulé : son état ne change plus.';

    public const ALREADY_CANCELLED = 'Ce dossier est déjà annulé.';

    public const DONE = 'Un dossier réalisé ne peut plus être annulé.';

    public const NOT_CREATED = 'La convention s\'envoie depuis un dossier à l\'état « Créé ».';

    public const NOT_CONVENTION_SENT = 'La convention doit avoir été envoyée avant d\'être signée.';

    public const NOT_CONVENTION_SIGNED = 'La convention doit être signée avant l\'envoi des convocations.';

    public const ALREADY_RUNNING = 'Ce dossier est déjà en cours ou réalisé.';

    public const ALREADY_DONE = 'Ce dossier est déjà réalisé.';

    public function sendConvention(Dossier $dossier, CarbonImmutable $on): Dossier
    {
        return $this->step($dossier, DossierStatus::Created, self::NOT_CREATED, DossierStatus::ConventionSent, 'convention_sent_on', $on, null);
    }

    public function signConvention(Dossier $dossier, CarbonImmutable $on): Dossier
    {
        return $this->step($dossier, DossierStatus::ConventionSent, self::NOT_CONVENTION_SENT, DossierStatus::ConventionSigned, 'convention_signed_on', $on, 'convention_sent_on');
    }

    public function sendConvocations(Dossier $dossier, CarbonImmutable $on): Dossier
    {
        return $this->step($dossier, DossierStatus::ConventionSigned, self::NOT_CONVENTION_SIGNED, DossierStatus::ConvocationsSent, 'convocations_sent_on', $on, 'convention_signed_on');
    }

    /**
     * « En cours » by hand, from any step before it.
     */
    public function markRunning(Dossier $dossier): Dossier
    {
        return $this->transition($dossier, function (Dossier $locked): void {
            if (! in_array($locked->status, DossierStatus::BEFORE_RUNNING, true)) {
                throw new UserFacingHttpException(409, self::ALREADY_RUNNING);
            }
            $locked->status = DossierStatus::Running;
        });
    }

    /**
     * « Réalisé » by hand, from any step before it (« En cours » included).
     */
    public function markDone(Dossier $dossier): Dossier
    {
        return $this->transition($dossier, function (Dossier $locked): void {
            if ($locked->status->isDoneOrAfter()) {
                throw new UserFacingHttpException(409, self::ALREADY_DONE);
            }
            $locked->status = DossierStatus::Done;
        });
    }

    /**
     * Cancels today (Paris) with a reason; refused once « Réalisé », stored or
     * computed from the sessions.
     *
     * @throws ValidationException
     */
    public function cancel(Dossier $dossier, ?string $reasonId): Dossier
    {
        // The state first: a cancelled or done dossier answers 409 whatever the reason.
        self::assertCancellable($dossier);
        Validator::make(['cancelled_reason_id' => $reasonId], [
            'cancelled_reason_id' => ['bail', 'required', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', app(TenantContext::class)->id())
                ->where('list', ReferenceList::CancelReason->value)
                ->whereNull('deleted_at')
                ->where('is_active', true)],
        ], [
            'cancelled_reason_id.required' => 'Choisissez le motif d\'annulation.',
            'cancelled_reason_id.*' => 'Motif d\'annulation inconnu.',
        ])->validate();

        return $this->transition($dossier, function (Dossier $locked) use ($reasonId): void {
            self::assertCancellable($locked);
            $locked->status = DossierStatus::Cancelled;
            $locked->cancelled_reason_id = $reasonId;
            $locked->cancelled_on = Dossier::today();
            // Its trainees are unenrolled (sessions locked after the dossier).
            app(Enrolments::class)->cancelAllOfDossier($locked);
        }, checkCancelled: false);
    }

    private static function assertCancellable(Dossier $dossier): void
    {
        if ($dossier->isCancelled()) {
            throw new UserFacingHttpException(409, self::ALREADY_CANCELLED);
        }
        if ($dossier->effectiveStatus(Dossier::today())->isDoneOrAfter()) {
            throw new UserFacingHttpException(409, self::DONE);
        }
    }

    /**
     * One of the three dated steps: from $from only, on a day not before the
     * previous step's.
     */
    private function step(Dossier $dossier, DossierStatus $from, string $refusal, DossierStatus $to, string $column, CarbonImmutable $on, ?string $previous): Dossier
    {
        return $this->transition($dossier, function (Dossier $locked) use ($from, $refusal, $to, $column, $on, $previous): void {
            if ($locked->status !== $from) {
                throw new UserFacingHttpException(409, $refusal);
            }
            $before = $previous === null ? null : $locked->getAttribute($previous);
            if ($before instanceof CarbonImmutable && $on->toDateString() < $before->toDateString()) {
                throw ValidationException::withMessages(['on' => 'La date ne peut pas précéder celle de l\'étape précédente ('.$before->format('d/m/Y').').']);
            }
            $locked->status = $to;
            $locked->setAttribute($column, $on->toDateString());
        });
    }

    /**
     * @param  Closure(Dossier): void  $change
     */
    private function transition(Dossier $dossier, Closure $change, bool $checkCancelled = true): Dossier
    {
        return DB::transaction(function () use ($dossier, $change, $checkCancelled): Dossier {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->getKey());
            if ($checkCancelled && $locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            $change($locked);
            $locked->save();
            $dossier->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
