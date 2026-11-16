<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\ReferenceRules;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Confier à… »: any active user of the organisme, whatever his role, and
 * an optional message (500 characters at most). Allowed with the right to
 * edit the action (crm.activities.update and its scope): outside it, 404.
 */
class AssignRequest extends FormRequest
{
    public const MAX_MESSAGE = 500;

    public function authorize(): bool
    {
        $user = $this->user();
        $activity = $this->activity();

        return $user instanceof User && $activity !== null && $user->can('update', $activity);
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    protected function prepareForValidation(): void
    {
        foreach (['responsible_user_id', 'message'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $this->merge([$key => $value === '' ? null : $value]);
            }
        }
    }

    public function activity(): ?Activity
    {
        $activity = $this->route('activity');

        return $activity instanceof Activity ? $activity : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'responsible_user_id' => ['required', ...ReferenceRules::responsible()],
            'message' => ['nullable', 'string', 'max:'.self::MAX_MESSAGE],
        ];
    }

    public function responsibleId(): string
    {
        return (string) $this->validated('responsible_user_id');
    }

    public function message(): ?string
    {
        $message = $this->validated('message');

        return is_string($message) ? $message : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsible_user_id.required' => 'Choisissez à qui confier l\'action.',
            'responsible_user_id.uuid' => 'Choisissez un utilisateur actif de l\'organisme.',
            'responsible_user_id.exists' => 'Choisissez un utilisateur actif de l\'organisme.',
            'message.string' => 'Cette valeur n\'est pas valide.',
            'message.max' => 'Le message ne peut pas dépasser '.self::MAX_MESSAGE.' caractères.',
        ];
    }
}
