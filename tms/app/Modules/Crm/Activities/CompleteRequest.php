<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\ReferenceRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * « Fait » on an action to do: result, report, when (now by default, never
 * in the future), duration, and the optional next follow-up.
 */
class CompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('update', $this->activity());
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['report', 'done_at', 'next_due_at', 'next_type_id', 'result_id', 'duration_minutes'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        $this->merge($clean);
    }

    public function activity(): Activity
    {
        $activity = $this->route('activity');
        assert($activity instanceof Activity);

        return $activity;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'result_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ActionResult)],
            'report' => ['nullable', 'string', 'max:'.ActivityRequest::MAX_REPORT],
            'done_at' => ['nullable', ActivityRequest::instantRule(false), ActivityRequest::notFuture()],
            'duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            ...ActivityRequest::nextRules(),
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->activity()->done_at !== null) {
                $validator->errors()->add('done_at', 'Cette action est déjà faite.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->safe()->only(['result_id', 'report', 'duration_minutes']);
        $data['done_at'] = ActivityRequest::instant($this->validated('done_at'), false) ?? CarbonImmutable::now((string) config('app.timezone'));

        return $data;
    }

    /**
     * @return array{type_id: string, due_at: CarbonImmutable}|null
     */
    public function next(): ?array
    {
        return ActivityRequest::nextFrom($this);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ActivityRequest::validationMessages();
    }
}
