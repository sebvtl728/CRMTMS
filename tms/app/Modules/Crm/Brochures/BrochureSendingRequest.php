<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Catalogue\Brochures;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The « Envoyer une plaquette » window: the brochures (current versions
 * only), the recipient, the subject (one line) and the message (plain text).
 * The recipient is checked against the fiche by the controller.
 */
class BrochureSendingRequest extends FormRequest
{
    public const MAX_BROCHURES = 20;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $subject = $this->input('subject');
        // One line: no header injection.
        $this->merge(['subject' => is_string($subject) ? trim((string) preg_replace('/[\r\n]+/', ' ', $subject)) : $subject]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'versions' => ['required', 'array', 'min:1', 'max:'.self::MAX_BROCHURES],
            'versions.*' => ['string', 'uuid', 'distinct'],
            'recipient_person_id' => ['required', 'string', 'uuid'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:10000'],
        ];
    }

    /**
     * Every brochure chosen is still offered (current version, not in the
     * trash) and together they fit in one e-mail.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ids = $this->input('versions');
            if (! is_array($ids) || $validator->errors()->has('versions') || $validator->errors()->has('versions.*')) {
                return;
            }
            $ids = array_values(array_filter($ids, 'is_string'));
            $offered = array_column(app(Brochures::class)->offered($ids), 'size', 'version_id');
            $total = 0;
            foreach ($ids as $i => $id) {
                if (! isset($offered[$id])) {
                    $validator->errors()->add("versions.{$i}", BrochureAttachments::GONE);
                } else {
                    $total += $offered[$id];
                }
            }
            if (! $validator->errors()->has('versions.0') && $validator->errors()->count() === 0 && BrochureAttachments::tooBig($total)) {
                $validator->errors()->add('versions', BrochureAttachments::TOO_BIG);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'versions.required' => 'Choisissez au moins une plaquette.',
            'versions.min' => 'Choisissez au moins une plaquette.',
            'versions.array' => 'Les plaquettes choisies ne sont pas valides.',
            'versions.max' => 'Vingt plaquettes au plus.',
            'versions.*.uuid' => BrochureAttachments::GONE,
            'versions.*.distinct' => 'Une plaquette est choisie deux fois.',
            'recipient_person_id.required' => 'Choisissez le destinataire.',
            'recipient_person_id.uuid' => 'Ce destinataire n\'est pas valide.',
            'subject.required' => 'L\'objet est obligatoire.',
            'subject.max' => 'L\'objet est trop long (200 caractères au plus).',
            'message.required' => 'Le message est obligatoire.',
            'message.max' => 'Le message est trop long.',
            'string' => 'Cette valeur n\'est pas valide.',
        ];
    }

    /**
     * @return list<string> the brochure versions chosen, in order
     */
    public function versions(): array
    {
        /** @var array{versions: list<string>} $v */
        $v = $this->validated();

        return $v['versions'];
    }
}
