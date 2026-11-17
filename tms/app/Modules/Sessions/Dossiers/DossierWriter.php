<?php

namespace App\Modules\Sessions\Dossiers;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\AbandonRule;
use App\Modules\Sessions\Amounts;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Enrolments\PriceShares;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Public\BillingGuards;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes a dossier and its lines together: validation with French
 * messages (the one place, for the form and for the creation from an
 * accepted quote), number at creation in the same transaction, totals in
 * integer cents (Amounts::lineTotal()).
 *
 *  - contractant: an entreprise (out of the trash) or a particulier of the
 *    CRM (Crm\Public\Fiches), exactly one; a contact person optional;
 *  - principal DAS required (proposed from the training of the first line
 *    by the form);
 *  - a training line needs a training of the catalogue (its DAS is copied)
 *    and a pricing mode, proposed from the unit when not given; a fee line
 *    has neither and carries the dossier's DAS;
 *  - abandon rule: the organisme's, or the dossier's own (mode + percent).
 *
 * Existing lines are matched by id (the enrolments point to them): a line
 * left out is deleted, a line without id is added; a line with trainees
 * enrolled is neither deleted nor given another training, and a line that
 * vanished meanwhile is a 409 (checkLines(), under the lock of the dossier).
 * The shares of the lines priced per trainee follow (PriceShares). A
 * cancelled dossier no longer changes (409).
 *
 * @phpstan-type DossierData array<string, mixed>
 */
final class DossierWriter
{
    public const CANCELLED = 'Un dossier annulé ne se modifie plus.';

    public const MAX_LINES = 100;

    /** « La ligne « X » … »: a line with an enrolment (cancelled included) is never deleted. */
    public const LINE_ENROLLED_PREFIX = 'La ligne « ';

    public const LINE_ENROLLED_SUFFIX = ' » a des stagiaires inscrits (inscriptions annulées comprises) : elle ne se supprime pas.';

    public const LINE_GONE = 'Une ligne de ce dossier a été supprimée entre-temps : rechargez la page.';

    public const ENROLLED = 'Désinscrivez d\'abord les stagiaires de ce dossier.';

    public function __construct(
        private readonly DossierNumbering $numbering,
        private readonly Trainings $trainings,
        private readonly Fiches $fiches,
    ) {}

    /**
     * @param  DossierData  $data
     * @param  array{quote_id?: string, opportunity_id?: string}  $origin  the accepted quote it comes from (DossierFromQuote), never typed by the user
     *
     * @throws ValidationException
     */
    public function create(array $data, array $origin = []): Dossier
    {
        [$values, $lines] = $this->validated($data, null);

        return DB::transaction(function () use ($values, $lines, $origin): Dossier {
            $dossier = new Dossier($values);
            $dossier->quote_id = $origin['quote_id'] ?? null;
            $dossier->opportunity_id = $origin['opportunity_id'] ?? null;
            $dossier->number = $this->numbering->next(CarbonImmutable::now());
            $dossier->save();
            $this->writeLines($dossier, $lines);

            return $dossier->unsetRelation('lines');
        });
    }

    /**
     * @param  DossierData  $data
     *
     * @throws ValidationException
     */
    public function update(Dossier $dossier, array $data): Dossier
    {
        if ($dossier->isCancelled()) {
            throw new UserFacingHttpException(409, self::CANCELLED);
        }
        [$values, $lines] = $this->validated($data, $dossier);

        return DB::transaction(function () use ($dossier, $values, $lines): Dossier {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if ($locked->isCancelled()) {
                throw new UserFacingHttpException(409, self::CANCELLED);
            }
            $this->checkLines($locked, $lines);
            $locked->update($values);
            $this->writeLines($locked, $lines);

            return $locked->unsetRelation('lines');
        });
    }

    /**
     * Puts the dossier in the trash, unless trainees are still enrolled on
     * it (cancelled enrolments aside) or the invoicing refuses it (a
     * validated invoice, BillingGuards): 409.
     */
    public function trash(Dossier $dossier): void
    {
        DB::transaction(function () use ($dossier): void {
            $locked = Dossier::query()->lockForUpdate()->findOrFail($dossier->id);
            if (Enrolment::query()->where('dossier_id', $locked->id)->where('status', '<>', EnrolmentStatus::Cancelled->value)->exists()) {
                throw new UserFacingHttpException(409, self::ENROLLED);
            }
            if (($refusal = BillingGuards::dossierTrashable($locked->id)) !== null) {
                throw new UserFacingHttpException(409, $refusal);
            }
            $locked->delete();
        });
    }

    /**
     * The columns of the dossier and of its lines, from untrusted input.
     *
     * @param  DossierData  $data
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validated(array $data, ?Dossier $current): array
    {
        $data = self::normalise($data);
        $tenant = app(TenantContext::class)->id();
        $currentLines = $current === null ? collect() : $current->lines()->get()->keyBy('id');

        $validator = Validator::make($data, [
            'company_id' => ['bail', 'required', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenant)
                ->where(fn ($q) => $q->whereNull('deleted_at')->when($current?->company_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'account_organisation_id' => ['nullable', 'uuid'],
            'account_person_id' => ['nullable', 'uuid'],
            'contact_person_id' => ['nullable', 'uuid'],
            'responsible_user_id' => ['nullable', 'bail', 'uuid', Rule::exists('users', 'id')
                ->where('tenant_id', $tenant)
                ->where(fn ($q) => $q->whereNull('deactivated_at')->when($current?->responsible_user_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'das_id' => ['bail', 'required', 'uuid', Rule::exists('reference_items', 'id')
                ->where('tenant_id', $tenant)->where('list', ReferenceList::Das->value)->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('is_active', true)->when($current?->das_id, fn ($q2, $id) => $q2->orWhere('id', $id)))],
            'abandon_mode' => ['nullable', Rule::in(AbandonRule::MODES)],
            'abandon_dedit_percent' => ['nullable', 'required_if:abandon_mode,'.AbandonRule::PRORATA_DEDIT, 'integer', 'between:'.AbandonRule::MIN_PERCENT.','.AbandonRule::MAX_PERCENT],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.kind' => ['required', Rule::in(array_keys(DossierLine::KINDS))],
            'lines.*.training_id' => ['nullable', 'required_if:lines.*.kind,'.DossierLine::TRAINING, 'uuid'],
            'lines.*.label' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['bail', 'required', 'regex:/^\d{1,6}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'lines.*.unit' => ['required', Rule::in(array_keys(DossierLine::UNITS))],
            'lines.*.unit_price' => ['bail', 'required', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'lines.*.discount_percent' => ['bail', 'required', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'max:100'],
            'lines.*.pricing_mode' => ['nullable', Rule::in(array_keys(DossierLine::PRICING_MODES))],
        ], self::messages());

        /** @var array<array-key, TrainingData> $trainings */
        $trainings = [];
        $validator->after(function ($v) use ($data, $current, $currentLines, &$trainings): void {
            $this->checkAccount($v, $data, $current);
            if (is_string($data['contact_person_id']) && ! $v->errors()->has('contact_person_id')
                && $data['contact_person_id'] !== $current?->contact_person_id && $this->fiches->person($data['contact_person_id']) === null) {
                $v->errors()->add('contact_person_id', 'Contact introuvable.');
            }
            foreach (is_array($data['lines']) ? $data['lines'] : [] as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $id = $line['id'] ?? null;
                if (is_string($id) && ! $v->errors()->has("lines.{$i}.id") && ! $currentLines->has($id)) {
                    $v->errors()->add("lines.{$i}.id", 'Ligne inconnue.');
                }
                if (($line['kind'] ?? null) !== DossierLine::TRAINING || ! is_string($line['training_id'] ?? null) || $v->errors()->has("lines.{$i}.training_id")) {
                    continue;
                }
                $training = $this->trainings->find($line['training_id']);
                $kept = is_string($id) && $currentLines->get($id)?->training_id === $line['training_id'];
                if ($training === null) {
                    $v->errors()->add("lines.{$i}.training_id", 'Formation introuvable.');
                } elseif (! $training->is_active && ! $kept) {
                    $v->errors()->add("lines.{$i}.training_id", 'Cette formation est désactivée.');
                } else {
                    $trainings[$i] = $training;
                }
            }
        });
        $validator->validate();

        $lines = [];
        $total = 0;
        // Positions follow the order received; $key is the key of the input (errors, trainings found).
        foreach (array_keys($data['lines']) as $i => $key) {
            $line = $data['lines'][$key];
            $training = $line['kind'] === DossierLine::TRAINING;
            $lineTotal = Amounts::lineTotal($line['quantity'], $line['unit_price'], $line['discount_percent']);
            $total += $lineTotal;
            $lines[] = [
                'id' => $line['id'] ?? null,
                'position' => $i,
                'kind' => $line['kind'],
                'training_id' => $training ? $trainings[$key]->id : null,
                'das_id' => $training ? $trainings[$key]->das_id : $data['das_id'],
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'unit' => $line['unit'],
                'unit_price' => $line['unit_price'],
                'discount_percent' => $line['discount_percent'],
                'total_ht' => Amounts::decimal($lineTotal),
                'pricing_mode' => $training ? ($line['pricing_mode'] ?? DossierLine::proposedPricingMode($line['unit'])) : null,
            ];
        }
        if ($total > Amounts::MAX_CENTS) {
            throw ValidationException::withMessages(['lines' => 'Le prix du dossier est trop élevé.']);
        }

        $dedit = $data['abandon_mode'] === AbandonRule::PRORATA_DEDIT;

        return [[
            'company_id' => $data['company_id'],
            'account_organisation_id' => $data['account_organisation_id'],
            'account_person_id' => $data['account_person_id'],
            'contact_person_id' => $data['contact_person_id'],
            'responsible_user_id' => $data['responsible_user_id'] ?? ($current === null ? Auth::id() : null),
            'das_id' => $data['das_id'],
            'abandon_mode' => $data['abandon_mode'],
            'abandon_dedit_percent' => $dedit ? (int) $data['abandon_dedit_percent'] : null,
            'notes' => $data['notes'],
        ], $lines];
    }

    /**
     * The id of the default company of the organisme (proposed by the form).
     */
    public static function defaultCompanyId(): ?string
    {
        $id = Company::query()->where('is_default', true)->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Exactly one contractant: an entreprise, or a person flagged
     * « Particulier »; the current one is kept even once trashed.
     *
     * @param  \Illuminate\Validation\Validator  $v
     * @param  DossierData  $data
     */
    private function checkAccount($v, array $data, ?Dossier $current): void
    {
        $organisation = $data['account_organisation_id'];
        $person = $data['account_person_id'];
        if (($organisation === null) === ($person === null)) {
            $v->errors()->add('account', 'Choisissez l\'entreprise ou le particulier contractant.');

            return;
        }
        if (is_string($organisation) && ! $v->errors()->has('account_organisation_id')
            && $organisation !== $current?->account_organisation_id && $this->fiches->organisation($organisation) === null) {
            $v->errors()->add('account_organisation_id', 'Entreprise introuvable.');
        }
        if (is_string($person) && ! $v->errors()->has('account_person_id')
            && $person !== $current?->account_person_id && $this->fiches->individual($person) === null) {
            $v->errors()->add('account_person_id', 'Choisissez une personne « Particulier » du CRM.');
        }
    }

    /**
     * Under the lock of the dossier (Enrolments and FundingWriter take it
     * too): every line sent still exists (409 otherwise), a line targeted by
     * a funding, itself or through one of its enrolments, is not deleted (nor
     * its pricing mode changed when its enrolments are targeted), and
     * a line with trainees enrolled (cancelled enrolments aside) is neither
     * deleted nor given another training.
     *
     * @param  list<array<string, mixed>>  $lines
     *
     * @throws ValidationException
     */
    private function checkLines(Dossier $dossier, array $lines): void
    {
        $existing = $dossier->lines()->get()->keyBy('id');
        $sent = [];
        foreach ($lines as $line) {
            if (is_string($line['id'])) {
                if (! $existing->has($line['id'])) {
                    throw new UserFacingHttpException(409, self::LINE_GONE);
                }
                $sent[$line['id']] = $line;
            }
        }
        $enrolled = Enrolment::query()->where('dossier_id', $dossier->id)
            ->where('status', '<>', EnrolmentStatus::Cancelled->value)
            ->distinct()->pluck('dossier_line_id')->all();
        // A funding keeps its target: the line, or a (cancelled) enrolment of it.
        $funded = Funding::query()->where('dossier_id', $dossier->id)->whereNotNull('dossier_line_id')->pluck('dossier_line_id')->all();
        $fundedEnrolments = Enrolment::query()->where('dossier_id', $dossier->id)
            ->whereIn('id', Funding::query()->where('dossier_id', $dossier->id)->whereNotNull('enrolment_id')->select('enrolment_id'))
            ->distinct()->pluck('dossier_line_id')->all();
        foreach ($existing->except(array_keys($sent)) as $line) {
            if (in_array($line->id, $funded, true)) {
                throw ValidationException::withMessages(['lines' => "La ligne « {$line->label} » est visée par un financement : modifiez ou supprimez d'abord ce financement."]);
            }
            if (in_array($line->id, $fundedEnrolments, true)) {
                throw ValidationException::withMessages(['lines' => "La ligne « {$line->label} » a un stagiaire visé par un financement : modifiez ou supprimez d'abord ce financement."]);
            }
        }
        // Its trainees' fundings are balanced against their shares: the pricing mode stays.
        foreach ($existing->only($fundedEnrolments) as $line) {
            $kept = $sent[$line->id] ?? null;
            if ($kept !== null && $kept['pricing_mode'] !== $line->pricing_mode) {
                throw ValidationException::withMessages(['lines' => "La ligne « {$line->label} » a un stagiaire visé par un financement : son mode de prix ne change plus."]);
            }
        }
        // A line with any enrolment, cancelled ones included, is never deleted
        // (nor its enrolments: the history of the trainees is kept).
        $withEnrolments = Enrolment::query()->where('dossier_id', $dossier->id)->distinct()->pluck('dossier_line_id')->all();
        foreach ($existing->only($withEnrolments)->except(array_keys($sent)) as $line) {
            throw ValidationException::withMessages(['lines' => self::LINE_ENROLLED_PREFIX.$line->label.self::LINE_ENROLLED_SUFFIX]);
        }
        foreach ($existing->only($enrolled) as $line) {
            $kept = $sent[$line->id];
            if ($kept['kind'] !== $line->kind || $kept['training_id'] !== $line->training_id) {
                throw ValidationException::withMessages(['lines' => "La ligne « {$line->label} » a des stagiaires inscrits : sa formation ne change plus."]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(Dossier $dossier, array $lines): void
    {
        $existing = $dossier->lines()->get()->keyBy('id');
        $kept = [];
        foreach ($lines as $values) {
            $id = $values['id'];
            unset($values['id']);
            $line = is_string($id) ? $existing->get($id) : null;
            $line ??= new DossierLine(['dossier_id' => $dossier->id]);
            $line->fill($values)->save();
            $kept[] = $line->id;
            // Its total or its pricing mode may have changed.
            PriceShares::recompute($line);
        }
        // No enrolment is left on a deleted line (checkLines()).
        $existing->except($kept)->each(fn (DossierLine $l) => $l->delete());
    }

    /**
     * Trimmed strings, empty ones as null; French amounts (« 1 234,50 ») as
     * « 1234.50 »; an empty discount as 0.
     *
     * @param  DossierData  $data
     * @return DossierData
     */
    private static function normalise(array $data): array
    {
        foreach (['company_id', 'account_organisation_id', 'account_person_id', 'contact_person_id', 'responsible_user_id', 'das_id', 'abandon_mode', 'abandon_dedit_percent', 'notes'] as $key) {
            $data[$key] = self::blank($data[$key] ?? null);
        }
        if (! is_array($data['lines'] ?? null)) {
            return $data;
        }
        foreach ($data['lines'] as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            foreach (['id', 'kind', 'training_id', 'label', 'unit', 'pricing_mode'] as $key) {
                $line[$key] = self::blank($line[$key] ?? null);
            }
            foreach (['quantity', 'unit_price', 'discount_percent'] as $key) {
                $value = self::blank($line[$key] ?? null);
                $line[$key] = $value === null ? ($key === 'discount_percent' ? '0' : null) : (Amounts::normalise($value) ?? $value);
            }
            $data['lines'][$i] = $line;
        }

        return $data;
    }

    private static function blank(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'company_id.required' => 'Choisissez la société émettrice.',
            'company_id.*' => 'Société introuvable.',
            'account_organisation_id.uuid' => 'Entreprise introuvable.',
            'account_person_id.uuid' => 'Particulier introuvable.',
            'contact_person_id.uuid' => 'Contact introuvable.',
            'responsible_user_id.*' => 'Responsable inconnu.',
            'das_id.required' => 'Choisissez le DAS du dossier.',
            'das_id.*' => 'DAS inconnu.',
            'abandon_mode.*' => 'Choisissez « Règle de l\'organisme », « Prorata seulement » ou « Prorata et dédit ».',
            'abandon_dedit_percent.required_if' => 'Le pourcentage de dédit est obligatoire (de 1 à 100).',
            'abandon_dedit_percent.*' => 'Le pourcentage de dédit doit être un nombre entier de 1 à 100.',
            'notes.max' => 'Les notes ne doivent pas dépasser 5 000 caractères.',
            'lines.required' => 'Ajoutez au moins une ligne.',
            'lines.min' => 'Ajoutez au moins une ligne.',
            'lines.max' => 'Un dossier compte au plus '.self::MAX_LINES.' lignes.',
            'lines.*.id.*' => 'Ligne inconnue.',
            'lines.*.kind.required' => 'Choisissez le type de ligne.',
            'lines.*.kind.*' => 'Type de ligne inconnu.',
            'lines.*.training_id.required_if' => 'Choisissez la formation de la ligne.',
            'lines.*.training_id.*' => 'Formation introuvable.',
            'lines.*.label.required' => 'Indiquez le libellé de la ligne.',
            'lines.*.label.max' => 'Le libellé ne doit pas dépasser 255 caractères.',
            'lines.*.quantity.required' => 'Indiquez la quantité.',
            'lines.*.quantity.*' => 'Quantité invalide (supérieure à 0, deux décimales au plus).',
            'lines.*.unit.required' => 'Choisissez l\'unité.',
            'lines.*.unit.*' => 'Unité inconnue.',
            'lines.*.unit_price.required' => 'Indiquez le prix unitaire HT.',
            'lines.*.unit_price.*' => 'Prix unitaire invalide (ex. 1 250,50).',
            'lines.*.discount_percent.*' => 'Remise invalide (de 0 à 100 %).',
            'lines.*.pricing_mode.*' => 'Mode de prix inconnu.',
        ];
    }
}
