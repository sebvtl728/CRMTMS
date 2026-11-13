<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * « Reporter » an action to do: only its due date changes. Same time rules
 * as everywhere (Paris time; a date alone means 9:00); never into the past.
 */
class PostponeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $activity = $this->route('activity');

        return $user instanceof User && $activity instanceof Activity && $user->can('update', $activity);
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('due_at');
        $this->merge(['due_at' => is_string($value) && trim($value) !== '' ? trim($value) : null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'due_at' => ['required', ActivityRequest::instantRule(true), function (string $attribute, mixed $value, Closure $fail): void {
                $at = ActivityRequest::instant($value, true);
                if ($at !== null && $at->toDateString() < CarbonImmutable::now((string) config('app.timezone'))->toDateString()) {
                    $fail('La nouvelle échéance ne peut pas être dans le passé.');
                }
            }],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $activity = $this->route('activity');
            if ($activity instanceof Activity && $activity->done_at !== null) {
                $validator->errors()->add('due_at', 'Cette action est déjà faite : elle n\'a plus d\'échéance à reporter.');
            }
        }];
    }

    public function messages(): array
    {
        return ['due_at.required' => 'Indiquez la nouvelle échéance.'];
    }

    public function dueAt(): CarbonImmutable
    {
        $at = ActivityRequest::instant($this->validated('due_at'), true);
        assert($at !== null);

        return $at;
    }
}
