<?php

namespace App\Modules\Qualite\Registers;

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Writes the Qualiopi registers: complaints and improvement actions, each
 * checked against its own rules (a closed complaint has its answer, an
 * answer never precedes the reception, a done action has its date and
 * result). Lists of the organisme (channel, source) are reference items.
 */
final class Registers
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function saveComplaint(?Complaint $complaint, array $data, User $by): Complaint
    {
        $values = Validator::make($data, [
            'received_on' => ['required', 'date_format:Y-m-d'],
            'channel_id' => ['required', ...self::item(ReferenceList::ComplaintChannel, $complaint?->channel_id)],
            'complainant' => ['required', 'string', 'max:200'],
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(array_keys(Complaint::STATUSES))],
            'response' => ['nullable', 'string', 'max:5000', 'required_if:status,closed'],
            'responded_on' => ['nullable', 'date_format:Y-m-d', 'required_if:status,closed', 'after_or_equal:received_on'],
        ], [
            'received_on.required' => 'Indiquez la date de réception.',
            'channel_id.required' => 'Choisissez le canal de réception.',
            'complainant.required' => 'Indiquez qui a émis la réclamation.',
            'subject.required' => 'Indiquez l\'objet de la réclamation.',
            'response.required_if' => 'Indiquez la réponse apportée avant de clore la réclamation.',
            'responded_on.required_if' => 'Indiquez la date de la réponse.',
            'responded_on.after_or_equal' => 'La réponse ne peut pas précéder la réception.',
            'date_format' => 'Date invalide.',
            'uuid' => 'Valeur inconnue.',
            'exists' => 'Valeur inconnue.',
        ])->validate();

        $complaint ??= new Complaint(['created_by' => $by->getKey()]);
        $complaint->fill($values)->save();

        return $complaint;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveAction(?ImprovementAction $action, array $data, User $by): ImprovementAction
    {
        $tenant = app(TenantContext::class)->id();
        $values = Validator::make($data, [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_id' => ['required', ...self::item(ReferenceList::ImprovementSource, $action?->source_id)],
            'complaint_id' => ['nullable', 'uuid', Rule::exists('quality_complaints', 'id')->where('tenant_id', $tenant)],
            'responsible_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('tenant_id', $tenant)],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(array_keys(ImprovementAction::STATUSES))],
            'done_on' => ['nullable', 'date_format:Y-m-d', 'required_if:status,done'],
            'result' => ['nullable', 'string', 'max:5000', 'required_if:status,done'],
        ], [
            'title.required' => 'Indiquez l\'action.',
            'source_id.required' => 'Choisissez l\'origine de l\'action.',
            'done_on.required_if' => 'Indiquez la date de réalisation.',
            'result.required_if' => 'Décrivez le résultat de l\'action.',
            'date_format' => 'Date invalide.',
            'uuid' => 'Valeur inconnue.',
            'exists' => 'Valeur inconnue.',
        ])->validate();

        $action ??= new ImprovementAction(['created_by' => $by->getKey()]);
        $action->fill([...array_fill_keys(['description', 'complaint_id', 'responsible_user_id', 'due_on', 'done_on', 'result'], null), ...$values])->save();

        return $action;
    }

    /**
     * An active item of $list of the organisme, or the one already stored.
     *
     * @return list<mixed>
     */
    private static function item(ReferenceList $list, ?string $keep): array
    {
        return ['bail', 'uuid', Rule::exists('reference_items', 'id')
            ->where('tenant_id', app(TenantContext::class)->id())
            ->where('list', $list->value)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep !== null, fn ($q2) => $q2->orWhere('id', $keep)))];
    }
}
