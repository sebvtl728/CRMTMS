<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Support\ReferenceRules;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Quick entry (create) and edit of an action. On creation the action hangs
 * on an entreprise, a person and/or an opportunity the user may work on
 * (visible, not trashed, not anonymised); the fiche of the opportunity is
 * filled in when none is given. « Fait » is the default: the action is
 * done now unless a due date is given; an optional follow-up (next type and
 * date) is then planned. These links are not edited afterwards.
 *
 * Dates are typed in Paris time (« 2026-11-14T14:30 »); a due date without
 * time means 9:00. A done action is never dated in the future: it would
 * move last_contact_at ahead of time.
 */
class ActivityRequest extends FormRequest
{
    public const MAX_REPORT = 10000;

    /** « Motif » and « Suite à donner ». */
    public const MAX_NOTE = 2000;

    public function authorize(): bool
    {
        $user = $this->user();
        $current = $this->current();

        return $user instanceof User && ($current === null ? $user->can('create', Activity::class) : $user->can('update', $current));
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['subject', 'purpose', 'report', 'next_steps', 'location', 'organisation_id', 'person_id', 'opportunity_id', 'due_at', 'done_at', 'next_due_at',
            'next_type_id', 'result_id', 'responsible_user_id', 'duration_minutes'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $value = is_string($value) ? trim($value) : $value;
                $clean[$key] = $value === '' ? null : $value;
            }
        }

        $this->merge($clean);
    }

    public function current(): ?Activity
    {
        $current = $this->route('activity');

        return $current instanceof Activity ? $current : null;
    }

    /**
     * Done unless said otherwise, or unless only a due date is given.
     */
    public function isDone(): bool
    {
        if ($this->has('done') && $this->input('done') !== null) {
            return $this->boolean('done');
        }

        return ! ($this->input('due_at') !== null && $this->input('done_at') === null);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $current = $this->current();
        $user = $this->user();
        assert($user instanceof User);

        $rules = [
            'type_id' => ['required', ...ReferenceRules::item(ReferenceList::ActionType, $current?->type_id)],
            'subject' => [$current === null ? 'nullable' : 'required', 'string', 'max:200'],
            ...self::noteRules(),
            'report' => ['nullable', 'string', 'max:'.self::MAX_REPORT],
            'location' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', self::instantRule(true)],
            'done_at' => ['nullable', self::instantRule(false), self::notFuture()],
            'duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'result_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ActionResult, $current?->result_id)],
            'responsible_user_id' => ['nullable', ...ReferenceRules::responsible($current?->responsible_user_id)],
            'collaborator_ids' => ['sometimes', 'nullable', 'array', 'max:'.ActivityCollaborators::MAX, function (string $attribute, mixed $value, Closure $fail) use ($current): void {
                $responsible = $this->input('responsible_user_id', ($current !== null ? $current->responsible_user_id : $this->user()?->getAuthIdentifier()));
                $kept = $current === null ? [] : $current->collaborators()->pluck('users.id')->map(fn ($id) => (string) $id)->all();
                $eligible = ActivityCollaborators::eligibleIds();
                foreach ((array) $value as $id) {
                    if (! is_string($id) || $id === $responsible || (! in_array($id, $eligible, true) && ! in_array($id, $kept, true))) {
                        $fail(ActivityCollaborators::INVALID);

                        return;
                    }
                }
            }],
            'collaborator_ids.*' => ['distinct'],
        ];

        if ($current === null) {
            $rules += [
                'done' => ['sometimes', 'nullable', 'boolean'],
                'organisation_id' => ['nullable', 'bail', 'uuid', self::usable(fn (string $id) => ActivityFiches::organisation($id, $user) !== null, 'Cette entreprise n\'existe pas ou n\'est plus disponible.')],
                'person_id' => ['nullable', 'bail', 'uuid', self::usable(fn (string $id) => ActivityFiches::person($id, $user) !== null, 'Cette personne n\'existe pas ou n\'est plus disponible.')],
                'opportunity_id' => ['nullable', 'bail', 'uuid', self::usable(fn (string $id) => ActivityFiches::opportunity($id, $user) !== null, 'Cette opportunité n\'existe pas ou n\'est plus disponible.')],
                ...self::nextRules(),
            ];
        }

        return $rules;
    }

    /**
     * « Motif » and « Suite à donner »: optional texts.
     *
     * @return array<string, list<string>>
     */
    public static function noteRules(): array
    {
        return [
            'purpose' => ['nullable', 'string', 'max:'.self::MAX_NOTE],
            'next_steps' => ['nullable', 'string', 'max:'.self::MAX_NOTE],
        ];
    }

    /**
     * Follow-up planned with a done action: type and date together, from today on.
     *
     * @return array<string, list<mixed>>
     */
    public static function nextRules(): array
    {
        return [
            'next_type_id' => ['nullable', 'required_with:next_due_at', ...ReferenceRules::item(ReferenceList::ActionType)],
            'next_due_at' => ['nullable', 'required_with:next_type_id', self::instantRule(true), function (string $attribute, mixed $value, Closure $fail): void {
                $at = self::instant($value, true);
                if ($at !== null && $at->toDateString() < CarbonImmutable::now(self::tz())->toDateString()) {
                    $fail('La prochaine relance ne peut pas être dans le passé.');
                }
            }],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $current = $this->current();
            if ($current !== null) {
                // A done action keeps a date done: « Modifier » never reopens it.
                if ($current->done_at !== null && $this->has('done_at') && $this->input('done_at') === null && ! $validator->errors()->has('done_at')) {
                    $validator->errors()->add('done_at', 'Indiquez la date à laquelle l\'action a été faite.');

                    return;
                }
                $done = $this->has('done_at') ? $this->input('done_at') : $current->done_at;
                $due = $this->has('due_at') ? $this->input('due_at') : $current->due_at;
                if ($done === null && $due === null && ! $validator->errors()->hasAny(['done_at', 'due_at'])) {
                    $validator->errors()->add('due_at', 'Indiquez l\'échéance de l\'action à faire.');
                }

                return;
            }

            $organisation = $this->input('organisation_id');
            $person = $this->input('person_id');
            $opportunity = $this->input('opportunity_id');
            if ($organisation === null && $person === null && $opportunity === null) {
                $validator->errors()->add('fiche', 'Choisissez une entreprise, une personne ou une opportunité.');
            }

            if (! $this->isDone()) {
                if ($this->input('due_at') === null && ! $validator->errors()->has('due_at')) {
                    $validator->errors()->add('due_at', 'Indiquez l\'échéance de l\'action à faire.');
                }
                if ($this->input('next_type_id') !== null || $this->input('next_due_at') !== null) {
                    $validator->errors()->add('next_due_at', 'La prochaine relance se prévoit avec une action faite.');
                }
            }

            // The fiche of the opportunity, when given, is that opportunity's.
            $user = $this->user();
            if (is_string($opportunity) && Str::isUuid($opportunity) && $user instanceof User && ! $validator->errors()->has('opportunity_id')) {
                $account = ActivityFiches::opportunity($opportunity, $user);
                $clash = $account !== null && ($account->organisation_id !== null
                    ? $organisation !== null && $organisation !== $account->organisation_id
                    : $person !== null && $person !== $account->person_id);
                if ($clash) {
                    $validator->errors()->add('opportunity_id', 'L\'action doit concerner la fiche de cette opportunité.');
                }
            }
        }];
    }

    /**
     * Validated values ready for the model: instants parsed, done_at set
     * (now by default) or cleared, next_* left out.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->safe()->except(['done', 'next_type_id', 'next_due_at', 'collaborator_ids']);

        if (array_key_exists('due_at', $data)) {
            $data['due_at'] = self::instant($data['due_at'], true);
        }
        if (array_key_exists('done_at', $data)) {
            $data['done_at'] = self::instant($data['done_at'], false);
        }
        if ($this->current() === null) {
            $data['done_at'] = $this->isDone() ? ($data['done_at'] ?? CarbonImmutable::now(self::tz())) : null;
        }

        return $data;
    }

    /**
     * The collaborators asked (null: the field was not sent, unchanged).
     *
     * @return list<string>|null
     */
    public function collaborators(): ?array
    {
        if (! $this->has('collaborator_ids')) {
            return null;
        }

        return array_values(array_map('strval', (array) ($this->validated('collaborator_ids') ?? [])));
    }

    /**
     * @return array{type_id: string, due_at: CarbonImmutable}|null
     */
    public function next(): ?array
    {
        return self::nextFrom($this);
    }

    /**
     * @return array{type_id: string, due_at: CarbonImmutable}|null
     */
    public static function nextFrom(FormRequest $request): ?array
    {
        $type = $request->validated('next_type_id');
        $due = self::instant($request->validated('next_due_at'), true);

        return is_string($type) && $due !== null ? ['type_id' => $type, 'due_at' => $due] : null;
    }

    /**
     * An instant typed in Paris time: « 2026-11-14T14:30 » (seconds and a
     * space allowed, or an ISO 8601 offset); a date alone (« 2026-11-14 »)
     * is 9:00 when $dateOnly, invalid otherwise.
     */
    public static function instant(mixed $value, bool $dateOnly): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        if ($dateOnly && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return self::exact('!Y-m-d', $value)?->setTime(9, 0);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/', $value, $m) === 1) {
            return self::exact('!Y-m-d H:i:s', $m[1].' '.$m[2].($m[3] ?? ':00'));
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1) {
            try {
                return CarbonImmutable::parse($value)->setTimezone(self::tz());
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Parsed in Paris, only when the date really exists (no 31/02 rollover).
     */
    private static function exact(string $format, string $value): ?CarbonImmutable
    {
        try {
            $at = CarbonImmutable::createFromFormat($format, $value, self::tz());
        } catch (\Throwable) {
            return null;
        }

        return $at !== null && $at->format(ltrim($format, '!')) === $value ? $at : null;
    }

    private static function tz(): string
    {
        return (string) config('app.timezone');
    }

    public static function instantRule(bool $dateOnly): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($dateOnly): void {
            if (self::instant($value, $dateOnly) === null) {
                $fail('Indiquez une date valide.');
            }
        };
    }

    public static function notFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $at = self::instant($value, false);
            if ($at !== null && $at->greaterThan(CarbonImmutable::now())) {
                $fail('Une action faite ne peut pas être datée dans le futur.');
            }
        };
    }

    /**
     * @param  Closure(string): bool  $exists
     */
    private static function usable(Closure $exists, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($exists, $message): void {
            if (! is_string($value) || ! $exists($value)) {
                $fail($message);
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::validationMessages();
    }

    /**
     * @return array<string, string>
     */
    public static function validationMessages(): array
    {
        return [
            'type_id.required' => 'Choisissez le type d\'action.',
            'subject.required' => 'Indiquez l\'objet de l\'action.',
            'subject.max' => 'L\'objet ne doit pas dépasser 200 caractères.',
            'report.max' => 'Le compte rendu ne peut pas dépasser '.number_format(self::MAX_REPORT, 0, ',', ' ').' caractères.',
            'purpose.max' => 'Le motif ne peut pas dépasser '.number_format(self::MAX_NOTE, 0, ',', ' ').' caractères.',
            'next_steps.max' => 'La suite à donner ne peut pas dépasser '.number_format(self::MAX_NOTE, 0, ',', ' ').' caractères.',
            'location.max' => 'Le lieu ne doit pas dépasser 255 caractères.',
            'duration_minutes.integer' => 'Indiquez une durée en minutes.',
            'duration_minutes.between' => 'Indiquez une durée entre 1 et 1 440 minutes.',
            'next_type_id.required_with' => 'Choisissez le type de la prochaine relance.',
            'next_due_at.required_with' => 'Indiquez la date de la prochaine relance.',
            'boolean' => 'Cette valeur n\'est pas valide.',
            'uuid' => 'Cette valeur n\'est pas valide.',
            'exists' => 'Cette valeur n\'est pas disponible.',
            'string' => 'Cette valeur n\'est pas valide.',
        ];
    }
}
