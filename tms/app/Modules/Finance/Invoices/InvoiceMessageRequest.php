<?php

namespace App\Modules\Finance\Invoices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The « Envoyer » window of an invoice: the recipient (prefilled from the
 * snapshot, editable), the subject (one line) and the message (plain text).
 */
class InvoiceMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $to = $this->input('to');
        $subject = $this->input('subject');

        $this->merge([
            'to' => is_string($to) ? trim($to) : $to,
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
            'subject.required' => 'L\'objet est obligatoire.',
            'subject.max' => 'L\'objet est trop long (200 caractères au plus).',
            'body.required' => 'Le message est obligatoire.',
            'body.max' => 'Le message est trop long.',
            'string' => 'Cette valeur n\'est pas valide.',
        ];
    }

    /**
     * @return array{to: string, subject: string, body: string}
     */
    public function message(): array
    {
        /** @var array{to: string, subject: string, body: string} $v */
        $v = $this->validated();

        return ['to' => $v['to'], 'subject' => $v['subject'], 'body' => $v['body']];
    }
}
