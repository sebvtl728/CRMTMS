<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Validation of a training (create and update). Authorisation is done by the
 * route middleware (catalogue.manage) and the policy. Amounts and durations
 * are typed the French way (« 1 250,50 », « 7,5 »).
 */
class TrainingRequest extends FormRequest
{
    public const SHEET_TEXTS = ['description', 'methods', 'evaluation', 'sanction', 'accessibility', 'access_terms', 'results'];

    public const MAX_OBJECTIVES = 20;

    public const MAX_MODULES = 30;

    public const DECIMALS = ['duration_hours', 'duration_days', 'centre_hours', 'company_hours', 'price_company', 'price_individual'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['code', 'title', 'audience', 'prerequisites', ...self::SHEET_TEXTS] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        foreach (['bpf_objective', 'nsf_code'] as $key) {
            if ($this->input($key) === '') {
                $clean[$key] = null;
            }
        }
        if ($this->has('rncp_code')) {
            $rncp = $this->input('rncp_code');
            $rncp = is_string($rncp) ? mb_strtoupper((string) preg_replace('/\s+/', '', $rncp)) : $rncp;
            $clean['rncp_code'] = $rncp === '' ? null : $rncp;
        }
        if (is_string($clean['code'] ?? null)) {
            $clean['code'] = mb_strtoupper($clean['code']);
        }
        foreach (self::DECIMALS as $key) {
            if ($this->has($key)) {
                $clean[$key] = self::decimal($this->input($key));
            }
        }

        if ($this->has('objectives')) {
            $objectives = $this->input('objectives');
            $clean['objectives'] = is_array($objectives)
                ? array_values(array_filter(array_map(fn ($o) => is_string($o) ? trim($o) : $o, $objectives), fn ($o) => $o !== '' && $o !== null))
                : $objectives;
            if ($clean['objectives'] === []) {
                $clean['objectives'] = null;
            }
        }
        if (is_array($this->input('modules'))) {
            $clean['modules'] = array_map(fn ($m) => is_array($m) ? self::cleanModule($m) : $m, array_values($this->input('modules')));
        }

        $this->merge($clean);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, mixed>
     */
    private static function cleanModule(array $module): array
    {
        foreach (['title', 'content', 'modality_id', 'id'] as $key) {
            if (array_key_exists($key, $module)) {
                $value = is_string($module[$key]) ? trim($module[$key]) : $module[$key];
                $module[$key] = $value === '' ? null : $value;
            }
        }
        if (array_key_exists('duration_hours', $module)) {
            $module['duration_hours'] = self::decimal($module['duration_hours']);
        }

        return $module;
    }

    /**
     * « 12 500,00 € » → « 12500.00 »; anything else unchanged for the
     * validation to refuse. Also used by the import (TrainingImport).
     */
    public static function decimal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $clean = preg_replace('/[\s\x{00A0}\x{202F}€]/u', '', $value) ?? $value;
        if ($clean === '') {
            return null;
        }

        return preg_match('/^-?\d+(,\d+)?$/', $clean) === 1 ? str_replace(',', '.', $clean) : $clean;
    }

    private function current(): ?Training
    {
        $current = $this->route('training');

        return $current instanceof Training ? $current : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();

        return [
            ...self::fieldRules($current),
            'modules' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_MODULES],
            'modules.*.id' => $current === null
                ? ['prohibited']
                : ['bail', 'nullable', 'uuid', 'distinct', Rule::exists('training_modules', 'id')
                    ->where('tenant_id', app(TenantContext::class)->id())->where('training_id', $current->id)],
            'modules.*.title' => ['required', 'string', 'max:200'],
            'modules.*.duration_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'modules.*.modality_id' => ['nullable', ...self::reference(ReferenceList::TrainingModality, $this->currentModalities($current))],
            'modules.*.content' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return list<string>
     */
    private function currentModalities(?Training $current): array
    {
        return $current === null ? [] : $current->modules()->whereNotNull('modality_id')->pluck('modality_id')->all();
    }

    /**
     * The rules of a training, $current being the training edited (null on
     * creation). Shared with the import (TrainingImport).
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(?Training $current): array
    {
        $tenantId = app(TenantContext::class)->id();
        $currentFunding = $current?->fundingModes()->pluck('reference_items.id')->all() ?? [];

        return [
            'code' => ['nullable', 'string', 'max:20', Rule::unique('trainings', 'code')
                ->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($current?->id)],
            'title' => ['required', 'string', 'max:200'],
            'rncp_code' => ['nullable', 'string', 'regex:/^RNCP\d{1,6}$/'],
            'competences_text' => ['nullable', 'string', 'max:300000'],
            'custom_values' => ['sometimes', 'nullable', 'array'],
            'bpf_objective' => ['nullable', Rule::enum(BpfObjective::class)],
            'nsf_code' => ['nullable', 'string', Rule::in(array_keys(Nsf::CODES))],
            'das_id' => ['required', ...self::reference(ReferenceList::Das, array_filter([$current?->das_id]))],
            'duration_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'duration_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'centre_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'company_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'audience' => ['nullable', 'string', 'max:5000'],
            'prerequisites' => ['nullable', 'string', 'max:5000'],
            'price_company' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'price_individual' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'description' => ['nullable', 'string', 'max:5000'],
            'objectives' => ['nullable', 'array', 'max:'.self::MAX_OBJECTIVES],
            'objectives.*' => ['string', 'max:500'],
            ...array_fill_keys(array_diff(self::SHEET_TEXTS, ['description']), ['nullable', 'string', 'max:5000']),
            'funding_mode_ids' => ['sometimes', 'nullable', 'array'],
            'funding_mode_ids.*' => ['distinct', ...self::reference(ReferenceList::FundingMode, $currentFunding)],
        ];
    }

    /**
     * An active item of $list in the current organisme, or one already
     * stored on the training (it stays valid when deactivated since).
     *
     * @param  list<string>  $keep
     * @return list<mixed>
     */
    public static function reference(ReferenceList $list, array $keep): array
    {
        return ['bail', 'uuid', Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep !== [], fn ($q2) => $q2->orWhereIn('id', $keep)))];
    }

    /**
     * @return array<string, string>
     */
    /**
     * The competence grid must parse (line by line message).
     *
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $text = $this->input('competences_text');
            if (! is_string($text) || $validator->errors()->has('competences_text')) {
                return;
            }
            try {
                Competences::parse($text);
            } catch (ValidationException $e) {
                $messages = $e->errors()['competences_text'] ?? [];
                $validator->errors()->add('competences_text', (string) ($messages[0] ?? 'Grille de compétences invalide.'));
            }
        }];
    }

    public function messages(): array
    {
        return self::validationMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'bpf_objective.enum' => 'Objectif BPF inconnu.',
            'nsf_code.in' => 'Code NSF inconnu : choisissez une spécialité de la liste.',
            'rncp_code.regex' => 'Le code RNCP doit s\'écrire RNCP suivi de chiffres (ex. RNCP36004).',
            'max' => 'Ce champ est trop long.',
            'string' => 'Cette valeur n\'est pas valide.',
            'array' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
            'prohibited' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'uuid' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'code.unique' => 'Ce code est déjà utilisé par une autre formation.',
            'code.max' => 'Le code ne doit pas dépasser 20 caractères.',
            'objectives.max' => 'Vingt objectifs au plus.',
            'objectives.*.max' => 'Un objectif ne doit pas dépasser 500 caractères.',
            'modules.max' => 'Trente modules au plus.',
            'modules.*.title.required' => 'L\'intitulé du module est obligatoire.',
            'modules.*.title.max' => 'L\'intitulé ne doit pas dépasser 200 caractères.',
            'modules.*.content.max' => 'Ce champ est trop long.',
            'title.max' => 'L\'intitulé ne doit pas dépasser 200 caractères.',
            'numeric' => 'Indiquez un nombre, par exemple 1 250,50.',
            'decimal' => 'Indiquez au plus deux décimales.',
            'min' => 'La valeur ne peut pas être négative.',
            'modules.*.duration_hours.max' => 'La durée est trop longue.',
            'duration_hours.max' => 'La durée est trop longue.',
            'duration_days.max' => 'La durée est trop longue.',
            'price_company.max' => 'Le tarif est trop élevé.',
            'price_individual.max' => 'Le tarif est trop élevé.',
        ];
    }
}
