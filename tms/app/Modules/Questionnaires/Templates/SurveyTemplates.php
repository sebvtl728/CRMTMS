<?php

namespace App\Modules\Questionnaires\Templates;

use App\Modules\Core\Users\User;
use App\Modules\Questionnaires\Surveys\SurveyContent;
use App\Modules\Questionnaires\Surveys\SurveyTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Writes the questionnaire templates: name, type, public, active and
 * questions (SurveyContent). The default of a type and public is set under a
 * lock (one at a time); an inactive template is never the default one and a
 * default template set inactive stops being the default.
 */
final class SurveyTemplates
{
    public const KINDS = ['hot', 'cold'];

    public const AUDIENCES = ['trainee', 'client'];

    /** The active default template of a type and public. */
    public static function defaultFor(string $kind, string $audience): ?SurveyTemplate
    {
        return SurveyTemplate::query()->where('kind', $kind)->where('audience', $audience)
            ->where('is_default', true)->where('active', true)->first();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $by): SurveyTemplate
    {
        return SurveyTemplate::query()->create([...$this->validated($data), 'created_by' => $by->id, 'updated_by' => $by->id]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(SurveyTemplate $template, array $data, User $by): SurveyTemplate
    {
        $values = $this->validated($data);
        if (! $values['active']) {
            $values['is_default'] = false;
        }
        $template->fill([...$values, 'updated_by' => $by->id])->save();

        return $template;
    }

    public function delete(SurveyTemplate $template): void
    {
        $template->forceFill(['is_default' => false])->save();
        $template->delete();
    }

    public function setDefault(SurveyTemplate $template, User $by): void
    {
        if (! $template->active) {
            throw ValidationException::withMessages(['template' => 'Activez ce modèle avant d\'en faire le modèle par défaut.']);
        }
        DB::transaction(function () use ($template, $by): void {
            $others = SurveyTemplate::query()->where('kind', $template->kind)->where('audience', $template->audience)
                ->lockForUpdate()->get();
            foreach ($others as $other) {
                if ($other->id !== $template->id && $other->is_default) {
                    $other->forceFill(['is_default' => false, 'updated_by' => $by->id])->save();
                }
            }
            $template->forceFill(['is_default' => true, 'updated_by' => $by->id])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, kind: string, audience: string, active: bool, questions: list<array<string, mixed>>}
     */
    private function validated(array $data): array
    {
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::in(self::KINDS)],
            'audience' => ['required', Rule::in(self::AUDIENCES)],
            'active' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'Donnez un nom au modèle.',
            'name.max' => 'Nom trop long (150 caractères au plus).',
            'kind.*' => 'Choisissez « À chaud » ou « À froid ».',
            'audience.*' => 'Choisissez « Stagiaires » ou « Entreprise ».',
            'active.boolean' => 'Valeur invalide.',
        ])->validate();

        return [
            'name' => trim($values['name']),
            'kind' => $values['kind'],
            'audience' => $values['audience'],
            'active' => (bool) ($values['active'] ?? true),
            'questions' => SurveyContent::validate($data['questions'] ?? null),
        ];
    }
}
