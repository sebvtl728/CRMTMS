<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation of a training (create and update). Authorisation is done by the
 * route middleware (catalogue.manage) and the policy. Amounts and durations
 * are typed the French way (« 1 250,50 », « 7,5 »).
 */
class TrainingRequest extends FormRequest
{
    private const DECIMALS = ['duration_hours', 'duration_days', 'price_company', 'price_individual'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['code', 'title', 'audience', 'prerequisites'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        if (is_string($clean['code'] ?? null)) {
            $clean['code'] = mb_strtoupper($clean['code']);
        }
        foreach (self::DECIMALS as $key) {
            if ($this->has($key)) {
                $clean[$key] = self::decimal($this->input($key));
            }
        }

        $this->merge($clean);
    }

    /**
     * « 12 500,00 € » → « 12500.00 »; anything else unchanged for the
     * validation to refuse.
     */
    private static function decimal(mixed $value): mixed
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
        $tenantId = app(TenantContext::class)->id();
        $currentFunding = $current?->fundingModes()->pluck('reference_items.id')->all() ?? [];

        return [
            'code' => ['nullable', 'string', 'max:20', Rule::unique('trainings', 'code')
                ->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($current?->id)],
            'title' => ['required', 'string', 'max:200'],
            'das_id' => ['required', ...self::reference(ReferenceList::Das, array_filter([$current?->das_id]))],
            'duration_hours' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999.99'],
            'duration_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'audience' => ['nullable', 'string', 'max:5000'],
            'prerequisites' => ['nullable', 'string', 'max:5000'],
            'price_company' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'price_individual' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
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
    private static function reference(ReferenceList $list, array $keep): array
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
    public function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'max' => 'Ce champ est trop long.',
            'string' => 'Cette valeur n\'est pas valide.',
            'array' => 'Cette valeur n\'est pas valide.',
            'distinct' => 'Cette valeur est en double.',
            'uuid' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'code.unique' => 'Ce code est déjà utilisé par une autre formation.',
            'code.max' => 'Le code ne doit pas dépasser 20 caractères.',
            'title.max' => 'L\'intitulé ne doit pas dépasser 200 caractères.',
            'numeric' => 'Indiquez un nombre, par exemple 1 250,50.',
            'decimal' => 'Indiquez au plus deux décimales.',
            'min' => 'La valeur ne peut pas être négative.',
            'duration_hours.max' => 'La durée est trop longue.',
            'duration_days.max' => 'La durée est trop longue.',
            'price_company.max' => 'Le tarif est trop élevé.',
            'price_individual.max' => 'Le tarif est trop élevé.',
        ];
    }
}
