<?php

namespace App\Modules\Catalogue;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Writing side of a catalogue import (called through Trainings by the CRM
 * import, which never touches the model or its tables). Values use the
 * keys of IMPORT_KEYS, amounts and durations typed the French way; the
 * rules and messages are those of the screen (TrainingRequest).
 *
 * The training of a row is the one holding its source identifier, else the
 * one holding its code (trainings not deleted). An update takes the given
 * values (the file is the reference: an absent key never erases a value);
 * the funding modes given replace the stored ones.
 */
final class TrainingImport
{
    public const KEYS = [
        'code', 'title', 'das_id', 'duration_hours', 'duration_days', 'audience', 'prerequisites',
        'price_company', 'price_individual', 'funding_mode_ids', 'is_active',
    ];

    public static function bySource(string $sourceSystem, string $sourceId): ?Training
    {
        return Training::query()->where('source_system', $sourceSystem)->where('source_id', $sourceId)->first();
    }

    public static function byCode(string $code): ?Training
    {
        $code = mb_strtoupper(trim($code));

        return $code === '' ? null : Training::query()->where('code', $code)->first();
    }

    /**
     * @param  array<string, mixed>  $values  normalised
     */
    public static function match(array $values, ?string $sourceSystem, ?string $sourceId): ?Training
    {
        $found = $sourceSystem !== null && $sourceId !== null ? self::bySource($sourceSystem, $sourceId) : null;
        $code = $values['code'] ?? null;

        return $found ?? (is_string($code) ? self::byCode($code) : null);
    }

    /**
     * The known keys, trimmed (an empty value is dropped), the code
     * upper-cased, amounts and durations as « 1200.50 ».
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normalise(array $values): array
    {
        $clean = [];
        foreach (array_intersect_key($values, array_flip(self::KEYS)) as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            if ($value === null) {
                continue;
            }
            if ($key === 'code' && is_string($value)) {
                $value = mb_strtoupper($value);
            }
            if (in_array($key, TrainingRequest::DECIMALS, true)) {
                $value = TrainingRequest::decimal($value);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * French messages per key: the rules of the screen, on the keys given
     * (title and DAS required on a creation, $current null).
     *
     * @param  array<string, mixed>  $values  normalised
     * @return array<string, list<string>>
     */
    public static function errors(array $values, ?Training $current): array
    {
        $rules = TrainingRequest::fieldRules($current);
        $keep = $values;
        if (isset($values['funding_mode_ids'])) {
            $keep['funding_mode_ids.*'] = true;
        }
        $rules = $current === null
            ? array_intersect_key($rules, $keep + ['title' => true, 'das_id' => true])
            : array_intersect_key($rules, $keep);
        $validator = Validator::make($values, $rules, TrainingRequest::validationMessages());

        $errors = [];
        foreach ($validator->errors()->messages() as $attribute => $messages) {
            $key = str_starts_with($attribute, 'funding_mode_ids') ? 'funding_mode_ids' : $attribute;
            $errors[$key] = array_values(array_unique([...$errors[$key] ?? [], ...$messages]));
        }

        return $errors;
    }

    /**
     * Creates or updates the training of these values.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException (French messages) on an invalid value
     */
    public static function upsert(array $values, ?string $sourceSystem, ?string $sourceId): Training
    {
        $values = self::normalise($values);
        $sourceId = $sourceSystem === null || $sourceId === null || trim($sourceId) === '' ? null : trim($sourceId);
        $current = self::match($values, $sourceSystem, $sourceId);

        $errors = self::errors($values, $current);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($values, $current, $sourceSystem, $sourceId): Training {
            $training = $current ?? new Training;
            $training->fill(array_diff_key($values, ['funding_mode_ids' => true]));
            // A training found by its code takes the identifiers, if it has none.
            if ($sourceId !== null && $training->getAttribute('source_id') === null) {
                $training->forceFill(['source_system' => $sourceSystem, 'source_id' => $sourceId]);
            }
            $training->save();

            if (isset($values['funding_mode_ids']) && is_array($values['funding_mode_ids'])) {
                $before = $training->fundingModes()->pluck('reference_items.id')->sort()->values()->all();
                $after = collect($values['funding_mode_ids'])->unique()->sort()->values()->all();
                if ($before !== $after) {
                    $training->syncFundingModes($after);
                    if (! $training->wasRecentlyCreated) {
                        $training->touch();
                    }
                }
            }

            return $training;
        });
    }

    /**
     * Deletes (trash) a training an import created, unless it is used.
     */
    public static function delete(string $id): bool
    {
        $training = Training::query()->find($id);
        if ($training === null || TrainingUsage::isUsed($training->id)) {
            return false;
        }

        return (bool) $training->delete();
    }
}
