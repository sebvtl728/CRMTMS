<?php

namespace App\Modules\Crm\Timeline;

use Illuminate\Foundation\Http\FormRequest;

class NoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $this->merge(['body' => trim($body)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.Note::MAX_LENGTH]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Écrivez le texte de la note.',
            'body.max' => 'La note ne peut pas dépasser '.number_format(Note::MAX_LENGTH, 0, ',', ' ').' caractères.',
        ];
    }
}
