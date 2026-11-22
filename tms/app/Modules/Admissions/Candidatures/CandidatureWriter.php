<?php

namespace App\Modules\Admissions\Candidatures;

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\CustomFields\CustomFieldDefinition;
use App\Modules\Admissions\CustomFields\CustomFieldValues;
use App\Modules\Admissions\Permissions;
use App\Modules\Admissions\Support\Rules;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Crm\Public\Trainees;
use App\Modules\Sessions\Public\Admission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes a candidature (never its stage: CandidatureTransitions).
 * Only the keys present are changed. The person is set at creation and never
 * changes. The RNCP code follows the training unless typed; the DAS defaults
 * to the training's. Education level and situation live on the CRM person
 * (Trainees::updateProfile), never on the candidature. The sensitive data
 * are ignored for a user without admissions.sensitive.view.
 */
final class CandidatureWriter
{
    /** Keys a candidature stores, sensitive ones included. */
    private const FIELDS = [
        'training_id', 'rncp_code', 'target_session_id', 'das_id', 'responsible_user_id', 'origin_id',
        'prescriber_organisation_id', 'france_travail_id', 'last_diploma', 'legal_representative', 'rqth',
        'adaptation_needs', 'positioning_sent_on', 'positioning_received_on', 'positioning_result', 'interview_at',
        'interviewer_user_id', 'interview_report', 'pathway',
    ];

    /** Kept on the CRM person. */
    private const PROFILE = ['education_level_id', 'professional_situation_id'];

    public function __construct(private readonly CandidatureNumbering $numbering) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): Candidature
    {
        $personId = is_string($data['person_id'] ?? null) ? $data['person_id'] : '';
        if (app(Fiches::class)->person($personId) === null) {
            throw ValidationException::withMessages(['person_id' => 'Choisissez une personne du CRM.']);
        }
        [$values, $profile] = $this->validated($data, null, $by);

        return DB::transaction(function () use ($personId, $values, $profile, $by): Candidature {
            if ($profile !== []) {
                app(Trainees::class)->updateProfile($personId, $profile);
            }
            $candidature = new Candidature([
                ...$values,
                'person_id' => $personId,
                'number' => $this->numbering->next(CarbonImmutable::now()),
            ]);
            $candidature->created_by = $by->id;
            $candidature->save();

            return $candidature->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Candidature $candidature, array $data, User $by): Candidature
    {
        unset($data['person_id']);
        [$values, $profile] = $this->validated($data, $candidature, $by);

        return DB::transaction(function () use ($candidature, $values, $profile): Candidature {
            if ($profile !== []) {
                app(Trainees::class)->updateProfile($candidature->person_id, $profile);
            }
            $candidature->update($values);

            return $candidature->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: array<string, string|null>}
     */
    private function validated(array $data, ?Candidature $current, User $by): array
    {
        if (! AdmissionsVisibility::sensitive($by)) {
            $data = array_diff_key($data, array_flip(Candidature::SENSITIVE));
        }
        $data = self::normalise($data);
        $present = array_intersect_key($data, array_flip([...self::FIELDS, ...self::PROFILE]));

        $rules = [
            'training_id' => ['nullable', 'uuid'],
            'rncp_code' => ['nullable', 'string', 'regex:/^RNCP\d{1,6}$/'],
            'target_session_id' => ['nullable', 'uuid'],
            'das_id' => ['nullable', ...Rules::item(ReferenceList::Das, $current?->das_id)],
            'responsible_user_id' => ['nullable', ...Rules::user($current?->responsible_user_id)],
            'origin_id' => ['nullable', ...Rules::item(ReferenceList::ContactOrigin, $current?->origin_id)],
            'prescriber_organisation_id' => ['nullable', 'uuid'],
            'france_travail_id' => ['nullable', 'string', 'max:30'],
            'last_diploma' => ['nullable', 'string', 'max:255'],
            'legal_representative' => ['nullable', 'array:last_name,first_name,relationship,phone,email'],
            'legal_representative.last_name' => ['nullable', 'string', 'max:100'],
            'legal_representative.first_name' => ['nullable', 'string', 'max:100'],
            'legal_representative.relationship' => ['nullable', 'string', 'max:50'],
            'legal_representative.phone' => ['nullable', 'string', 'max:30'],
            'legal_representative.email' => ['nullable', 'email', 'max:255'],
            'rqth' => ['boolean'],
            'adaptation_needs' => ['nullable', 'string', 'max:5000'],
            'positioning_sent_on' => ['nullable', 'date_format:Y-m-d'],
            'positioning_received_on' => ['nullable', 'date_format:Y-m-d'],
            'positioning_result' => ['nullable', 'string', 'max:100'],
            'interview_at' => ['nullable', 'date'],
            'interviewer_user_id' => ['nullable', ...Rules::user($current?->interviewer_user_id)],
            'interview_report' => ['nullable', 'string', 'max:10000'],
            'pathway' => ['nullable', Rule::enum(Pathway::class)],
            'education_level_id' => ['nullable', 'uuid'],
            'professional_situation_id' => ['nullable', 'uuid'],
        ];

        $applied = array_filter($rules, fn (string $key) => array_key_exists(explode('.', $key)[0], $present), ARRAY_FILTER_USE_KEY);
        $validator = Validator::make($present, $applied, self::messages());
        $training = null;
        $validator->after(function ($v) use ($present, $current, &$training): void {
            if (($present['training_id'] ?? null) !== null) {
                $training = app(Trainings::class)->find($present['training_id']);
                if ($training === null) {
                    $v->errors()->add('training_id', 'Cette formation n\'existe pas.');
                }
            }
            if (($present['target_session_id'] ?? null) !== null) {
                $session = Admission::session($present['target_session_id']);
                $trainingId = array_key_exists('training_id', $present) ? $present['training_id'] : $current?->training_id;
                if ($session === null || $session['trashed'] || $session['training_id'] !== $trainingId) {
                    $v->errors()->add('target_session_id', 'Choisissez une session de la formation visée.');
                } elseif ($session['das_id'] !== app(Trainings::class)->find($session['training_id'])?->das_id) {
                    // The session belongs to its training (checked above), whose DAS is the candidature's.
                    $v->errors()->add('target_session_id', 'Choisissez une session du DAS de la candidature.');
                }
            }
            if (($present['prescriber_organisation_id'] ?? null) !== null && app(Fiches::class)->organisation($present['prescriber_organisation_id']) === null) {
                $v->errors()->add('prescriber_organisation_id', 'Cette entreprise n\'existe pas.');
            }
            $dasId = array_key_exists('das_id', $present) ? $present['das_id'] : $current?->das_id;
            $trainingId = array_key_exists('training_id', $present) ? $present['training_id'] : $current?->training_id;
            // A candidature with an alternance contract stays on the alternance pathway.
            if (array_key_exists('pathway', $present) && $present['pathway'] !== Pathway::Alternance->value
                && $current !== null && AlternanceContract::query()->where('candidature_id', $current->id)->exists()) {
                $v->errors()->add('pathway', 'Retirez d\'abord le contrat d\'alternance.');
            }
            if ($dasId === null && $trainingId === null) {
                $v->errors()->add('das_id', 'Choisissez la formation visée ou un DAS.');
            }
        });
        $validated = $validator->validate();
        $custom = null;
        if (array_key_exists('custom_values', $data)) {
            if (! is_array($data['custom_values'])) {
                throw ValidationException::withMessages(['custom_values' => 'Valeur invalide.']);
            }
            $custom = CustomFieldValues::merge($current->custom_values ?? [], CustomFieldValues::validate(CustomFieldDefinition::CANDIDATURE, $data['custom_values'], AdmissionsVisibility::sensitive($by)));
        }

        $profile = array_intersect_key($validated, array_flip(self::PROFILE));
        $values = array_intersect_key($validated, array_flip(self::FIELDS));

        // The training brings its RNCP code (unless one is typed); with a training, the DAS is the training's.
        if (array_key_exists('training_id', $values) && $values['training_id'] !== ($current?->training_id)) {
            if ($training !== null) {
                if (! array_key_exists('rncp_code', $values)) {
                    $values['rncp_code'] = $training->rncp_code;
                }
            } elseif (! array_key_exists('rncp_code', $values)) {
                $values['rncp_code'] = null;
            }
        }
        $trainingId = array_key_exists('training_id', $values) ? $values['training_id'] : $current?->training_id;
        if ($trainingId !== null) {
            $values['das_id'] = ($training ?? app(Trainings::class)->find($trainingId))->das_id ?? $current?->das_id;
        } elseif (array_key_exists('das_id', $values) && $values['das_id'] === null) {
            if ($current === null) {
                unset($values['das_id']);
            } else {
                $values['das_id'] = $current->das_id;
            }
        }
        // Moving the candidature to another DAS needs that DAS in the scope of the update right
        // (a creation is checked by the controller).
        if ($current !== null && isset($values['das_id']) && $values['das_id'] !== $current->das_id
            && ! AdmissionsVisibility::allowsDas($by, Permissions::CANDIDATURES_UPDATE, $values['das_id'])) {
            throw ValidationException::withMessages([$trainingId !== null ? 'training_id' : 'das_id' => $trainingId !== null
                ? 'Cette formation relève d\'un DAS que vous ne suivez pas.'
                : 'Choisissez un DAS que vous suivez.']);
        }

        // Education level and situation live on the CRM person: only a real change is written there,
        // never for a fiche in the trash or anonymised (refused then).
        if ($profile !== []) {
            $person = app(Trainees::class)->profile($current->person_id ?? (string) ($data['person_id'] ?? ''));
            // An anonymised fiche keeps no profile: only a value counts as a change.
            $profile = array_filter($profile, fn ($value, string $key) => $value !== ($person[$key] ?? null), ARRAY_FILTER_USE_BOTH);
            if ($profile !== [] && $current !== null && ($person === null || $person['trashed'])) {
                throw ValidationException::withMessages([array_key_first($profile) => 'La fiche de cette personne est à la corbeille ou anonymisée : son niveau et sa situation ne changent plus.']);
            }
        }

        if (array_key_exists('rqth', $values)) {
            $values['rqth'] = (bool) $values['rqth'];
        }
        if (array_key_exists('legal_representative', $values) && is_array($values['legal_representative'])) {
            $rep = array_filter($values['legal_representative'], fn ($v) => $v !== null && $v !== '');
            $values['legal_representative'] = $rep === [] ? null : $rep;
        }

        if ($custom !== null) {
            $values['custom_values'] = $custom;
        }

        return [$values, $profile];
    }

    /**
     * Trims texts, empties to null, uppercases the RNCP code without spaces.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalise(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }
        if (is_string($data['rncp_code'] ?? null)) {
            $data['rncp_code'] = mb_strtoupper((string) preg_replace('/\s+/', '', $data['rncp_code']));
        }
        if (is_array($data['legal_representative'] ?? null)) {
            $data['legal_representative'] = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $data['legal_representative']);
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'required' => 'Ce champ est obligatoire.',
            'uuid' => 'Valeur inconnue.',
            'exists' => 'Valeur inconnue.',
            'max' => 'Ce champ est trop long.',
            'email' => 'Adresse e-mail invalide.',
            'date' => 'Date invalide.',
            'date_format' => 'Date invalide.',
            'boolean' => 'Valeur invalide.',
            'array' => 'Valeur invalide.',
            'pathway.enum' => 'Parcours inconnu.',
            'rncp_code.regex' => 'Le code RNCP doit s\'écrire RNCP suivi de chiffres (ex. RNCP36004).',
        ];
    }
}
