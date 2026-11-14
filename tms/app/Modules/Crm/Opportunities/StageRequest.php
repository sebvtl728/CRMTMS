<?php

namespace App\Modules\Crm\Opportunities;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Support\ReferenceRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A stage move. The closing rules (reason required for « Perdu ») are
 * enforced by OpportunityStage, whatever the caller.
 */
class StageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['loss_reason_id', 'loss_comment', 'won_on', 'quote_id'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }
        $this->merge($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->route('opportunity');

        return [
            'stage_id' => ['required', ...ReferenceRules::item(ReferenceList::PipelineStage, $current instanceof Opportunity ? $current->stage_id : null)],
            'loss_reason_id' => ['nullable', ...ReferenceRules::item(ReferenceList::LossReason)],
            'loss_comment' => ['nullable', 'string', 'max:2000'],
            'won_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
            // « Gagné » after an accepted quote: the amount becomes its total HT.
            'quote_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Choisissez une étape.',
            'uuid' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'quote_id.uuid' => OpportunityStage::QUOTE_NOT_ACCEPTED,
            'exists' => 'Cette valeur n\'existe pas ou n\'est plus disponible.',
            'loss_comment.max' => 'Le commentaire est trop long.',
            'won_on.date_format' => 'La date doit être au format AAAA-MM-JJ.',
            'won_on.before_or_equal' => 'La date de gain ne peut pas être dans le futur.',
            'won_on.after' => 'La date de gain n\'est pas plausible.',
            'string' => 'Cette valeur n\'est pas valide.',
        ];
    }
}
