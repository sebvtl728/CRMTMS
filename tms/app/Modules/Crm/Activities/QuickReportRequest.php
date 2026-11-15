<?php

namespace App\Modules\Crm\Activities;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Support\ReferenceRules;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * « Compte rendu de RDV »: a planned meeting (activity_id), or a fiche
 * (organisation_id / person_id, with the type of the action), then result,
 * report, the stage of an opportunity of that meeting or fiche (with the
 * loss reason for « Perdu ») and the next follow-up. Everything is checked
 * here, before anything is written.
 */
class QuickReportRequest extends FormRequest
{
    private ?Activity $meeting = null;

    private ?Opportunity $opportunity = null;

    public function authorize(): bool
    {
        $user = $this->user();

        // Completing a meeting or recording a new action: crm.activities.update (route).
        return $user instanceof User && ($this->input('activity_id') !== null || $user->can('create', Activity::class));
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['activity_id', 'organisation_id', 'person_id', 'type_id', 'result_id', 'report', 'duration_minutes', 'done_at',
            'opportunity_id', 'stage_id', 'loss_reason_id', 'loss_comment', 'next_type_id', 'next_due_at'] as $key) {
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
        return [
            'activity_id' => ['nullable', 'uuid'],
            'organisation_id' => ['nullable', 'uuid'],
            'person_id' => ['nullable', 'uuid'],
            'type_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ActionType)],
            'result_id' => ['nullable', ...ReferenceRules::item(ReferenceList::ActionResult)],
            'report' => ['nullable', 'string', 'max:'.ActivityRequest::MAX_REPORT],
            'duration_minutes' => ['nullable', 'integer', 'between:1,1440'],
            'done_at' => ['nullable', ActivityRequest::instantRule(false), ActivityRequest::notFuture()],
            'opportunity_id' => ['nullable', 'uuid'],
            'stage_id' => ['nullable', ...ReferenceRules::item(ReferenceList::PipelineStage)],
            'loss_reason_id' => ['nullable', ...ReferenceRules::item(ReferenceList::LossReason)],
            'loss_comment' => ['nullable', 'string', 'max:2000'],
            ...ActivityRequest::nextRules(),
        ];
    }

    /**
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $user = $this->user();
            assert($user instanceof User);
            $errors = $validator->errors();

            $activityId = $this->input('activity_id');
            if (is_string($activityId) && ! $errors->has('activity_id')) {
                $this->meeting = $this->findMeeting($activityId, $user);
                if ($this->meeting === null) {
                    $errors->add('activity_id', 'Ce rendez-vous n\'existe pas ou n\'est plus disponible.');
                } elseif ($this->meeting->done_at !== null) {
                    $errors->add('activity_id', 'Ce rendez-vous a déjà son compte rendu.');
                }
            } elseif ($activityId === null) {
                $this->checkFiche($validator, $user);
            }

            $this->checkStage($validator, $user);
        }];
    }

    /**
     * Without a planned meeting: one fiche the user may work on, and the type.
     */
    private function checkFiche(Validator $validator, User $user): void
    {
        $errors = $validator->errors();
        $organisation = $this->input('organisation_id');
        $person = $this->input('person_id');
        if ($organisation === null && $person === null) {
            $errors->add('fiche', 'Choisissez un rendez-vous ou une fiche.');

            return;
        }
        if (is_string($organisation) && ! $errors->has('organisation_id') && ActivityFiches::organisation($organisation, $user) === null) {
            $errors->add('organisation_id', 'Cette entreprise n\'existe pas ou n\'est plus disponible.');
        }
        if (is_string($person) && ! $errors->has('person_id') && ActivityFiches::person($person, $user) === null) {
            $errors->add('person_id', 'Cette personne n\'existe pas ou n\'est plus disponible.');
        }
        if ($this->input('type_id') === null && ! $errors->has('type_id')) {
            $errors->add('type_id', 'Choisissez le type d\'action.');
        }
    }

    /**
     * The opportunity is one of the meeting (or, when it has none, of its
     * fiche) the user may update; a stage needs it, « Perdu » its reason.
     */
    private function checkStage(Validator $validator, User $user): void
    {
        $errors = $validator->errors();
        $opportunityId = $this->input('opportunity_id');
        $stageId = $this->input('stage_id');
        if ($errors->hasAny(['opportunity_id', 'activity_id', 'organisation_id', 'person_id'])) {
            return;
        }
        if ($opportunityId === null) {
            if ($stageId !== null) {
                $errors->add('opportunity_id', 'Choisissez l\'opportunité dont l\'étape change.');
            }

            return;
        }

        $opportunity = is_string($opportunityId) ? ActivityFiches::opportunity($opportunityId, $user) : null;
        if ($opportunity === null || ! $user->can('update', $opportunity) || ! $this->linked($opportunity)) {
            $errors->add('opportunity_id', 'Choisissez une opportunité de ce rendez-vous ou de sa fiche.');

            return;
        }
        $this->opportunity = $opportunity;

        $stage = is_string($stageId) && ! $errors->has('stage_id') ? ReferenceItem::query()->find($stageId) : null;
        if ($stage !== null && OpportunityStage::outcome($stage) === 'lost' && $this->input('loss_reason_id') === null && ! $errors->has('loss_reason_id')) {
            $errors->add('loss_reason_id', OpportunityStage::LOSS_REASON_REQUIRED);
        }
    }

    private function linked(Opportunity $opportunity): bool
    {
        if ($this->meeting !== null && $this->meeting->opportunity_id !== null) {
            return $this->meeting->opportunity_id === $opportunity->id;
        }
        $organisation = $this->meeting !== null ? $this->meeting->organisation_id : $this->input('organisation_id');
        $person = $this->meeting !== null ? $this->meeting->person_id : $this->input('person_id');

        return ($opportunity->organisation_id !== null && $opportunity->organisation_id === $organisation)
            || ($opportunity->person_id !== null && $opportunity->person_id === $person);
    }

    private function findMeeting(string $id, User $user): ?Activity
    {
        // The user's own meetings (types flagged meeting), as listed by the form.
        $meeting = CrmVisibility::activities(Activity::query(), $user)
            ->where('responsible_user_id', $user->id)
            ->whereIn('type_id', WeekPlanning::meetingTypes())
            ->find($id);

        return $meeting instanceof Activity && $user->can('update', $meeting) ? $meeting : null;
    }

    public function meeting(): ?Activity
    {
        return $this->meeting;
    }

    public function opportunity(): ?Opportunity
    {
        return $this->opportunity;
    }

    public function stage(): ?ReferenceItem
    {
        $id = $this->validated('stage_id');

        return $this->opportunity === null || ! is_string($id) ? null : ReferenceItem::query()->where('list', ReferenceList::PipelineStage)->find($id);
    }

    /**
     * Values of the done action: completion of the meeting, or a new
     * action on the fiche (and on the opportunity chosen, if any).
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->safe()->only(['result_id', 'report', 'duration_minutes']);
        $data['done_at'] = ActivityRequest::instant($this->validated('done_at'), false) ?? CarbonImmutable::now((string) config('app.timezone'));

        if ($this->meeting === null) {
            $data += $this->safe()->only(['type_id', 'organisation_id', 'person_id']);
            $data['opportunity_id'] = $this->opportunity?->id;
        }

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
        return [
            ...ActivityRequest::validationMessages(),
            'loss_comment.max' => 'Le commentaire est trop long.',
        ];
    }
}
