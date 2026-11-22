<?php

namespace App\Modules\Sessions\Fundings;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Public\BillingGuards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes the fundings of a dossier, under the lock of the dossier (the
 * order dossier → session → enrolment of the module; DossierWriter takes
 * the same lock before deleting a line), French validation messages:
 *
 *  - funder: an entreprise or a person of the CRM (Crm\Public\Fiches), exactly
 *    one; the current one is kept even once trashed;
 *  - type: an active item of « Types de financeur » (or the current one);
 *  - target: none, a line of this dossier, or an enrolment of this dossier on
 *    a line priced per trainee (its share is the price it is balanced
 *    against; a group line is targeted itself), neither cancelled nor
 *    waitlisted (not checked again for the target already stored); never
 *    both; another dossier's: 422; a « target »
 *    sent (dossier, line, enrolment) requires its id;
 *  - amount HT typed in French (« 2 100,50 »), integer cents, never a float;
 *  - « paie directement l'organisme » (pays_directly, 0.9: the funder receives
 *    its own invoice), ignored when the funder is the contractant itself
 *    (he is invoiced the rest anyway).
 *
 * A cancelled dossier no longer changes (409). A refused funding stays: the
 * user moves its amount to another funder himself (no automatic transfer).
 *
 * @phpstan-type FundingData array<string, mixed>
 */
final class FundingWriter
{
    /** What the funding pays, as the form says it (« target »): the ids follow. */
    public const TARGETS = ['dossier', 'line', 'enrolment'];

    public function __construct(private readonly Fiches $fiches) {}

    /**
     * @param  FundingData  $data
     *
     * @throws ValidationException
     */
    public function create(Dossier $dossier, array $data): Funding
    {
        return $this->locked($dossier->id, function (Dossier $locked) use ($data): Funding {
            $funding = new Funding(['dossier_id' => $locked->id, ...$this->validated($locked, $data, null)]);
            $funding->save();

            return $funding;
        });
    }

    /**
     * Only the keys sent change: the others keep their stored value.
     *
     * @param  FundingData  $data
     *
     * @throws ValidationException
     */
    public function update(Funding $funding, array $data): Funding
    {
        return $this->locked($funding->dossier_id, function (Dossier $locked) use ($funding, $data): Funding {
            $current = Funding::query()->where('dossier_id', $locked->id)->lockForUpdate()->findOrFail($funding->id);
            $values = $this->validated($locked, [...self::input($current), ...$data], $current);
            // The invoicing may have frozen who pays directly (a validated invoice: a credit note first).
            if ($values['pays_directly'] !== $current->pays_directly && ($refusal = BillingGuards::fundingLocked($locked->id)) !== null) {
                throw ValidationException::withMessages(['pays_directly' => $refusal]);
            }
            // Nor the funder of a funding invoiced (its invoices and credit notes go to that funder).
            $funder = $values['funder_organisation_id'] !== $current->funder_organisation_id || $values['funder_person_id'] !== $current->funder_person_id;
            if ($funder && ($refusal = BillingGuards::funderLocked($current->id)) !== null) {
                throw ValidationException::withMessages(['funder' => $refusal]);
            }
            $current->fill($values)->save();
            $funding->setRawAttributes($current->getAttributes(), true);

            return $current;
        });
    }

    /**
     * @throws ValidationException the funding is invoiced (BillingGuards::FUNDER_LOCKED)
     */
    public function delete(Funding $funding): void
    {
        $this->locked($funding->dossier_id, function (Dossier $locked) use ($funding): void {
            $current = Funding::query()->where('dossier_id', $locked->id)->lockForUpdate()->findOrFail($funding->id);
            // A funding invoiced keeps its funder, and stays (its invoices and credit notes go to that funder).
            if (($refusal = BillingGuards::funderLocked($current->id)) !== null) {
                throw ValidationException::withMessages(['funder' => $refusal]);
            }
            $current->delete();
        });
    }

    /**
     * The stored values of a funding as an input (amount in French).
     *
     * @return FundingData
     */
    public static function input(Funding $funding): array
    {
        return [
            'funder_organisation_id' => $funding->funder_organisation_id,
            'funder_person_id' => $funding->funder_person_id,
            'funding_type_id' => $funding->funding_type_id,
            'dossier_line_id' => $funding->dossier_line_id,
            'enrolment_id' => $funding->enrolment_id,
            'target' => $funding->dossier_line_id !== null ? 'line' : ($funding->enrolment_id !== null ? 'enrolment' : 'dossier'),
            'amount' => str_replace('.', ',', (string) $funding->amount),
            'status' => $funding->status->value,
            'agreement_number' => $funding->agreement_number,
            'agreed_on' => $funding->agreed_on?->toDateString(),
            'notes' => $funding->notes,
            'pays_directly' => $funding->pays_directly,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(Dossier): T  $change
     * @return T
     */
    private function locked(string $dossierId, callable $change): mixed
    {
        return DB::transaction(function () use ($dossierId, $change) {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossierId);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, DossierWriter::CANCELLED);
            }

            return $change($locked);
        });
    }

    /**
     * The columns of a funding, from untrusted input.
     *
     * @param  FundingData  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Dossier $dossier, array $data, ?Funding $current): array
    {
        $data = self::normalise($data);
        $tenant = app(TenantContext::class)->id();

        $validator = Validator::make($data, [
            'funder_organisation_id' => ['nullable', 'uuid'],
            'funder_person_id' => ['nullable', 'uuid'],
            'funding_type_id' => ['bail', 'required', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', $tenant)->where('list', ReferenceList::FundingType->value)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->funding_type_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'target' => ['nullable', Rule::in(self::TARGETS)],
            'dossier_line_id' => ['nullable', 'required_if:target,line', 'uuid'],
            'enrolment_id' => ['nullable', 'required_if:target,enrolment', 'uuid'],
            'amount' => ['bail', 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'status' => ['required', Rule::enum(FundingStatus::class)],
            'agreement_number' => ['nullable', 'string', 'max:100'],
            'agreed_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'pays_directly' => ['boolean'],
        ], self::messages());

        $validator->after(function ($v) use ($data, $dossier, $current): void {
            $this->checkFunder($v, $data, $current);
            $this->checkTarget($v, $data, $dossier, $current);
        });
        $validator->validate();
        // The contractant already receives the invoice of what is left: « paie directement » means nothing for him (ignored, never refused).
        $contractor = ($data['funder_organisation_id'] !== null && $data['funder_organisation_id'] === $dossier->account_organisation_id)
            || ($data['funder_person_id'] !== null && $data['funder_person_id'] === $dossier->account_person_id);

        return [
            'funder_organisation_id' => $data['funder_organisation_id'],
            'funder_person_id' => $data['funder_person_id'],
            'funding_type_id' => $data['funding_type_id'],
            'dossier_line_id' => $data['dossier_line_id'],
            'enrolment_id' => $data['enrolment_id'],
            'amount' => Amounts::decimal(Amounts::cents($data['amount'])),
            'status' => $data['status'],
            'agreement_number' => $data['agreement_number'],
            'agreed_on' => $data['agreed_on'],
            'notes' => $data['notes'],
            'pays_directly' => ! $contractor && filter_var($data['pays_directly'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * @param  \Illuminate\Validation\Validator  $v
     * @param  FundingData  $data
     */
    private function checkFunder($v, array $data, ?Funding $current): void
    {
        $organisation = $data['funder_organisation_id'];
        $person = $data['funder_person_id'];
        if (($organisation === null) === ($person === null)) {
            $v->errors()->add('funder', 'Choisissez l\'entreprise ou la personne qui finance.');

            return;
        }
        if (is_string($organisation) && ! $v->errors()->has('funder_organisation_id')
            && $organisation !== $current?->funder_organisation_id && $this->fiches->organisation($organisation) === null) {
            $v->errors()->add('funder_organisation_id', 'Entreprise introuvable.');
        }
        if (is_string($person) && ! $v->errors()->has('funder_person_id')
            && $person !== $current?->funder_person_id && $this->fiches->person($person) === null) {
            $v->errors()->add('funder_person_id', 'Personne introuvable.');
        }
    }

    /**
     * @param  \Illuminate\Validation\Validator  $v
     * @param  FundingData  $data
     */
    private function checkTarget($v, array $data, Dossier $dossier, ?Funding $current): void
    {
        $lineId = $data['dossier_line_id'];
        $enrolmentId = $data['enrolment_id'];
        if ($lineId !== null && $enrolmentId !== null) {
            $v->errors()->add('target', 'Visez une ligne ou une inscription, pas les deux.');

            return;
        }
        if (is_string($lineId) && ! $v->errors()->has('dossier_line_id')
            && ! DossierLine::query()->where('dossier_id', $dossier->id)->whereKey($lineId)->exists()) {
            $v->errors()->add('dossier_line_id', 'Cette ligne n\'appartient pas à ce dossier.');
        }
        if (! is_string($enrolmentId) || $v->errors()->has('enrolment_id')) {
            return;
        }
        $enrolment = Enrolment::query()->with('line')->where('dossier_id', $dossier->id)->find($enrolmentId);
        if ($enrolment === null) {
            $v->errors()->add('enrolment_id', 'Cette inscription n\'appartient pas à ce dossier.');

            return;
        }
        // The target already stored is kept whatever became of it (cancelled, ...).
        if ($enrolmentId === $current?->enrolment_id) {
            return;
        }
        if ($enrolment->line?->pricing_mode !== DossierLine::PER_TRAINEE) {
            $v->errors()->add('enrolment_id', 'Cette inscription relève d\'une ligne au prix de groupe : visez la ligne.');
        } elseif ($enrolment->isCancelled()) {
            $v->errors()->add('enrolment_id', 'Cette inscription est annulée.');
        } elseif ($enrolment->status === EnrolmentStatus::Waitlisted) {
            $v->errors()->add('enrolment_id', 'Ce stagiaire est sur liste d\'attente : il n\'a pas encore de part du prix.');
        }
    }

    /**
     * Trimmed strings, empty ones as null; a French amount as « 1234.50 »;
     * no state: « À demander ».
     *
     * @param  FundingData  $data
     * @return FundingData
     */
    private static function normalise(array $data): array
    {
        foreach (['target', 'funder_organisation_id', 'funder_person_id', 'funding_type_id', 'dossier_line_id', 'enrolment_id', 'amount', 'status', 'agreement_number', 'agreed_on', 'notes'] as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $data[$key] = $value;
        }
        if ($data['amount'] !== null) {
            $data['amount'] = Amounts::normalise($data['amount']) ?? $data['amount'];
        }
        $data['status'] ??= FundingStatus::ToRequest->value;
        $data['pays_directly'] ??= false;
        // A target chosen in the form: the id of the other kind is dropped.
        if ($data['target'] === 'dossier' || $data['target'] === 'enrolment') {
            $data['dossier_line_id'] = null;
        }
        if ($data['target'] === 'dossier' || $data['target'] === 'line') {
            $data['enrolment_id'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'funder_organisation_id.*' => 'Entreprise introuvable.',
            'funder_person_id.*' => 'Personne introuvable.',
            'funding_type_id.required' => 'Choisissez le type de financement.',
            'funding_type_id.*' => 'Type de financement inconnu.',
            'target.*' => 'Choisissez ce que finance ce financement.',
            'dossier_line_id.required_if' => 'Choisissez la ligne financée.',
            'dossier_line_id.*' => 'Cette ligne n\'appartient pas à ce dossier.',
            'enrolment_id.required_if' => 'Choisissez le stagiaire financé.',
            'enrolment_id.*' => 'Cette inscription n\'appartient pas à ce dossier.',
            'amount.required' => 'Indiquez le montant HT.',
            'amount.*' => 'Montant invalide (ex. 2 100,50).',
            'status.*' => 'État inconnu.',
            'agreement_number.*' => 'Le n° de prise en charge ne doit pas dépasser 100 caractères.',
            'agreed_on.*' => 'Date d\'accord invalide.',
            'notes.*' => 'Les notes ne doivent pas dépasser 5 000 caractères.',
            'pays_directly.*' => 'Cochez ou décochez la case.',
        ];
    }
}
