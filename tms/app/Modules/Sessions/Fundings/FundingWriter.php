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
 *    against; a group line is targeted itself), not cancelled unless it is
 *    already the target; never both; another dossier's: 422;
 *  - amount HT typed in French (« 2 100,50 »), integer cents, never a float.
 *
 * A cancelled dossier no longer changes (409). A refused funding stays: the
 * user moves its amount to another funder himself (no automatic transfer).
 *
 * @phpstan-type FundingData array<string, mixed>
 */
final class FundingWriter
{
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
            $current->fill($this->validated($locked, [...self::input($current), ...$data], $current))->save();
            $funding->setRawAttributes($current->getAttributes(), true);

            return $current;
        });
    }

    public function delete(Funding $funding): void
    {
        $this->locked($funding->dossier_id, function (Dossier $locked) use ($funding): void {
            Funding::query()->where('dossier_id', $locked->id)->lockForUpdate()->findOrFail($funding->id)->delete();
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
            'amount' => str_replace('.', ',', (string) $funding->amount),
            'status' => $funding->status->value,
            'agreement_number' => $funding->agreement_number,
            'agreed_on' => $funding->agreed_on?->toDateString(),
            'notes' => $funding->notes,
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
            'dossier_line_id' => ['nullable', 'uuid'],
            'enrolment_id' => ['nullable', 'uuid'],
            'amount' => ['bail', 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'status' => ['required', Rule::enum(FundingStatus::class)],
            'agreement_number' => ['nullable', 'string', 'max:100'],
            'agreed_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], self::messages());

        $validator->after(function ($v) use ($data, $dossier, $current): void {
            $this->checkFunder($v, $data, $current);
            $this->checkTarget($v, $data, $dossier, $current);
        });
        $validator->validate();

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
        } elseif ($enrolment->line?->pricing_mode !== DossierLine::PER_TRAINEE) {
            $v->errors()->add('enrolment_id', 'Cette inscription relève d\'une ligne au prix de groupe : visez la ligne.');
        } elseif ($enrolment->isCancelled() && $enrolmentId !== $current?->enrolment_id) {
            $v->errors()->add('enrolment_id', 'Cette inscription est annulée.');
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
        foreach (['funder_organisation_id', 'funder_person_id', 'funding_type_id', 'dossier_line_id', 'enrolment_id', 'amount', 'status', 'agreement_number', 'agreed_on', 'notes'] as $key) {
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
            'dossier_line_id.*' => 'Cette ligne n\'appartient pas à ce dossier.',
            'enrolment_id.*' => 'Cette inscription n\'appartient pas à ce dossier.',
            'amount.required' => 'Indiquez le montant HT.',
            'amount.*' => 'Montant invalide (ex. 2 100,50).',
            'status.*' => 'État inconnu.',
            'agreement_number.*' => 'Le n° de prise en charge ne doit pas dépasser 100 caractères.',
            'agreed_on.*' => 'Date d\'accord invalide.',
            'notes.*' => 'Les notes ne doivent pas dépasser 5 000 caractères.',
        ];
    }
}
