<?php

namespace App\Modules\Crm\Quotes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The « Envoyer » / « Relancer » window: the recipient (prefilled), up to
 * five copies, the subject (one line) and the message (plain text).
 */
class QuoteMessageRequest extends FormRequest
{
    public const MAX_CC = 5;

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
        ];
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
}
