<?php

namespace App\Modules\Qualite\Qualiopi;

use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Validator;

/**
 * The organisme's Qualiopi file for the current referential: each
 * indicator with its state (default: applicable, to do), the progress
 * (ready among the applicable ones), and the update of one indicator.
 */
final class QualiopiFile
{
    /**
     * @return array<int, QualityIndicator> number => state (only the touched ones)
     */
    public static function states(): array
    {
        return QualityIndicator::query()->where('referential_version', QualiopiReferential::VERSION)->get()->keyBy('number')->all();
    }

    /**
     * @return array{ready: int, applicable: int}
     */
    public static function progress(): array
    {
        $states = self::states();
        $applicable = 0;
        $ready = 0;
        foreach (array_keys(QualiopiReferential::indicators()) as $number) {
            $state = $states[$number] ?? null;
            if ($state !== null && ! $state->applicable) {
                continue;
            }
            $applicable++;
            if ($state?->status === 'ready') {
                $ready++;
            }
        }

        return ['ready' => $ready, 'applicable' => $applicable];
    }

    /**
     * @param  array<string, mixed>  $data  keys present only
     */
    public function update(int $number, array $data, User $by): QualityIndicator
    {
        $state = QualityIndicator::query()->firstOrNew(['referential_version' => QualiopiReferential::VERSION, 'number' => $number]);
        $values = Validator::make($data, [
            'applicable' => ['sometimes', 'boolean'],
            'not_applicable_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_keys(QualityIndicator::STATUSES))],
            'responsible_user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ], [
            'status.in' => 'Statut inconnu.',
            'date_format' => 'Date invalide.',
            'exists' => 'Utilisateur inconnu.',
            'max' => 'Ce texte est trop long.',
        ])->after(function ($v) use ($data, $state): void {
            $applicable = array_key_exists('applicable', $data) ? filter_var($data['applicable'], FILTER_VALIDATE_BOOLEAN) : $state->applicable;
            $reason = array_key_exists('not_applicable_reason', $data) ? trim((string) $data['not_applicable_reason']) : (string) $state->not_applicable_reason;
            if (! $applicable && $reason === '') {
                $v->errors()->add('not_applicable_reason', 'Justifiez pourquoi l’indicateur ne s’applique pas.');
            }
        })->validate();

        if (array_key_exists('applicable', $values) && (bool) $values['applicable']) {
            $values['not_applicable_reason'] = null;
        }
        $state->fill($values)->save();

        return $state;
    }
}
