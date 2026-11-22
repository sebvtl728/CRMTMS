<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Catalogue\Brochures;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The « Envoyer » / « Relancer » window: the recipient (prefilled), up to
 * five copies, the subject (one line) and the message (plain text).
 */
class QuoteMessageRequest extends FormRequest
{
    public const MAX_CC = 5;

    public const MAX_BROCHURES = 20;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $to = $this->input('to');
        $cc = $this->input('cc');
        $subject = $this->input('subject');

        $this->merge([
            'to' => is_string($to) ? trim($to) : $to,
            'cc' => is_array($cc) ? array_values(array_filter(array_map(fn ($c) => is_string($c) ? trim($c) : $c, $cc), fn ($c) => $c !== '' && $c !== null)) : ($cc ?? []),
            // One line: no header injection.
            'subject' => is_string($subject) ? trim((string) preg_replace('/[\r\n]+/', ' ', $subject)) : $subject,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'email:rfc', 'max:254'],
            'cc' => ['array', 'max:'.self::MAX_CC],
            'cc.*' => ['string', 'email:rfc', 'max:254', 'distinct'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            // Brochure versions joined (sending only: a reminder re-joins the recorded ones).
            'brochure_version_ids' => ['nullable', 'array', 'max:'.self::MAX_BROCHURES],
            'sign_online' => ['sometimes', 'boolean'],
            'brochure_version_ids.*' => ['string', 'uuid'],
        ];
    }

    /**
     * Every brochure version chosen exists (Catalogue\Brochures) and the
     * brochures alone fit in one e-mail; the quote PDF is counted at sending.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $ids = $this->input('brochure_version_ids');
            if (! is_array($ids) || $validator->errors()->has('brochure_version_ids') || $validator->errors()->has('brochure_version_ids.*')) {
                return;
            }
            $ids = array_values(array_filter($ids, 'is_string'));
            $found = array_column(app(Brochures::class)->versions($ids), 'size', 'version_id');
            $total = 0;
            foreach ($ids as $i => $id) {
                if (! isset($found[$id])) {
                    $validator->errors()->add("brochure_version_ids.{$i}", QuoteTransitions::BROCHURE_GONE);
                } elseif ($i === array_search($id, $ids, true)) {
                    $total += $found[$id];
                }
            }
            if ($total > QuoteTransitions::MAX_ATTACHMENTS_BYTES) {
                $validator->errors()->add('brochure_version_ids', QuoteTransitions::ATTACHMENTS_TOO_BIG);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.required' => 'Le destinataire n\'a pas d\'adresse e-mail.',
            'to.email' => 'Cette adresse e-mail n\'est pas valide.',
            'to.max' => 'Cette adresse e-mail est trop longue.',
            'cc.array' => 'Les adresses en copie ne sont pas valides.',
            'cc.max' => 'Cinq adresses en copie au plus.',
            'cc.*.email' => 'Cette adresse en copie n\'est pas valide.',
            'cc.*.max' => 'Cette adresse en copie est trop longue.',
            'cc.*.distinct' => 'Cette adresse est déjà en copie.',
            'subject.required' => 'L\'objet est obligatoire.',
            'subject.max' => 'L\'objet est trop long (200 caractères au plus).',
            'body.required' => 'Le message est obligatoire.',
            'body.max' => 'Le message est trop long.',
            'brochure_version_ids.array' => 'Les plaquettes choisies ne sont pas valides.',
            'brochure_version_ids.max' => 'Vingt plaquettes au plus.',
            'brochure_version_ids.*.uuid' => QuoteTransitions::BROCHURE_GONE,
            'string' => 'Cette valeur n\'est pas valide.',
        ];
    }

    /**
     * @return array{to: string, cc: list<string>, subject: string, body: string}
     */
    public function message(): array
    {
        /** @var array{to: string, cc?: list<string>, subject: string, body: string} $v */
        $v = $this->validated();

        return ['to' => $v['to'], 'cc' => $v['cc'] ?? [], 'subject' => $v['subject'], 'body' => $v['body']];
    }

    /**
     * The brochure versions chosen, each once (empty when the box is unticked).
     *
     * @return list<string>
     */
    public function brochureVersionIds(): array
    {
        /** @var array{brochure_version_ids?: list<string>|null} $v */
        $v = $this->validated();

        return array_values(array_unique($v['brochure_version_ids'] ?? []));
    }

    /** « Joindre un lien de signature en ligne »: the quote and its « Bon pour accord » in one e-mail. */
    public function signOnline(): bool
    {
        return $this->boolean('sign_online');
    }
}
