<?php

namespace App\Modules\Crm\Import;

use App\Modules\Catalogue\TrainingData;
use App\Modules\Catalogue\Trainings;
use Illuminate\Validation\ValidationException;

/**
 * Rows of a catalogue import (target Trainings), written through the public
 * service of the Catalogue module (Trainings), never through its model.
 *
 * The training of a row: the one holding its source identifier (always
 * updated, the file is the reference); else the one holding its code, then
 * handled by the batch strategy: Skip ignores the row, Merge updates the
 * training with the non-empty values of the file, Create refuses the row
 * (a code is unique in the catalogue); else a new training. An empty cell
 * never erases a value. Values are checked with the rules of the catalogue
 * screen (Trainings::importErrors), amounts and durations typed the French
 * way (« 1 200,00 », « 1 200 € », « 7,5 »).
 */
final class TrainingRows
{
    /** Import fields whose key differs in the catalogue (Trainings::IMPORT_KEYS). */
    private const KEYS = ['das' => 'das_id', 'funding_modes' => 'funding_mode_ids'];

    public const DUPLICATE_CODE = 'Code : Ce code est déjà utilisé par une autre formation.';

    /** @var array<string, array{line: int, values: array<string, mixed>}> dry run: code => earlier row creating it */
    private array $seenCodes = [];

    /** @var array<string, array{line: int, values: array<string, mixed>}> dry run: source id => earlier row creating it */
    private array $seenSources = [];

    private readonly ?string $sourceSystem;

    public function __construct(
        private readonly ImportBatch $batch,
        private readonly ValueResolver $values,
        private readonly bool $dryRun,
        private readonly ImportStamps $stamps,
        private readonly Trainings $trainings,
    ) {
        $this->sourceSystem = $batch->sourceSystem();
    }

    /**
     * Typed values (field => value), errors and warnings of one row.
     *
     * @param  array<string, string>  $cells  field => cell
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>}
     */
    public function read(array $cells): array
    {
        $labels = ImportTarget::Trainings->fields();
        $values = [];
        $errors = [];

        foreach ($cells as $field => $raw) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $label = $labels[$field] ?? $field;

            if ($field === 'das') {
                $parts = self::split('/\s*[;,|\n]\s*/u', $raw);
                if (count($parts) > 1) {
                    $errors[] = "{$label} : une seule valeur par formation.";

                    continue;
                }
                $resolved = $this->values->resolve($field, $parts[0] ?? $raw);
                if ($resolved['error'] !== null) {
                    $errors[] = $resolved['error'];
                } elseif ($resolved['value'] !== null) {
                    $values[$field] = $resolved['value'];
                }
            } elseif ($field === 'funding_modes') {
                $ids = [];
                foreach (self::split('/\s*[;,]\s*/u', $raw) as $one) {
                    $resolved = $this->values->resolve($field, $one);
                    if ($resolved['error'] !== null) {
                        $errors[] = $resolved['error'];
                    } elseif ($resolved['value'] !== null) {
                        $ids[] = $resolved['value'];
                    }
                }
                if ($ids !== []) {
                    $values[$field] = array_values(array_unique($ids));
                }
            } elseif ($field === 'is_active') {
                $bool = Values::bool($raw);
                $bool === null ? $errors[] = "{$label} : répondez par Oui ou Non." : $values[$field] = $bool;
            } elseif ($field === 'source_id') {
                $values[$field] = mb_substr($raw, 0, 255);
            } else {
                $values[$field] = $raw;
            }
        }

        return [$values, $errors, []];
    }

    /**
     * @param  array<string, mixed>  $values  from read()
     */
    public function row(int $line, array $values): RowOutcome
    {
        $attributes = self::attributes($values);
        $sourceId = $this->sourceSystem !== null && is_string($values['source_id'] ?? null) ? $values['source_id'] : null;
        $code = is_string($attributes['code'] ?? null) ? mb_strtoupper($attributes['code']) : null;

        // 1. Same source identifier: updated whatever the strategy.
        $existing = $sourceId === null ? null : $this->trainings->findBySource((string) $this->sourceSystem, $sourceId);
        if ($existing === null && $sourceId !== null && isset($this->seenSources[$sourceId])) {
            return $this->earlier($this->seenSources[$sourceId], $attributes, 'Même identifiant source que la ligne');
        }

        // 2. Same code: the batch strategy.
        if ($existing === null && $code !== null) {
            $byCode = $this->trainings->findByCode($code);
            $earlier = $byCode === null ? ($this->seenCodes[$code] ?? null) : null;
            if ($byCode !== null || $earlier !== null) {
                $strategy = $this->batch->strategy();
                if ($strategy === DuplicateStrategy::Skip) {
                    $outcome = (new RowOutcome(ImportRow::SKIPPED))->add('info', $byCode !== null
                        ? "Une formation a déjà ce code (« {$code} ») : ligne ignorée."
                        : "Même code que la ligne {$earlier['line']} : ligne ignorée.");

                    return $byCode === null ? $outcome : $outcome->subjectRef(Trainings::MORPH_ALIAS, $byCode->id);
                }
                if ($strategy === DuplicateStrategy::Create) {
                    return RowOutcome::error(self::DUPLICATE_CODE);
                }
                if ($byCode === null) {
                    return $this->earlier($earlier, $attributes, 'Même code que la ligne');
                }
                $existing = $byCode;
            }
        }

        $errors = $this->errors($attributes, $existing?->id);
        if ($errors !== []) {
            return RowOutcome::error(...$errors);
        }

        return $existing !== null ? $this->update($existing, $attributes, $sourceId) : $this->create($line, $attributes, $sourceId, $code);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function update(TrainingData $existing, array $attributes, ?string $sourceId): RowOutcome
    {
        $outcome = (new RowOutcome(ImportRow::UPDATED))->subjectRef(Trainings::MORPH_ALIAS, $existing->id);
        if ($this->dryRun) {
            $outcome->modifiedSinceImport = $this->stamps->modifiedSinceImportOf(Trainings::MORPH_ALIAS, $existing->id, $existing->updated_at);

            return $outcome;
        }

        $saved = $this->write($attributes, $sourceId);
        if ($saved === null) {
            return $this->refused($attributes, $existing->id);
        }
        if ($saved->updated_at !== $existing->updated_at) {
            $outcome->touchedRef(Trainings::MORPH_ALIAS, $saved->id, $saved->updated_at);
        } else {
            $outcome->add('info', 'Aucune modification.');
        }

        return $outcome;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(int $line, array $attributes, ?string $sourceId, ?string $code): RowOutcome
    {
        $outcome = new RowOutcome(ImportRow::CREATED);
        if ($this->dryRun) {
            if ($code !== null) {
                $this->seenCodes[$code] ??= ['line' => $line, 'values' => $attributes];
            }
            if ($sourceId !== null) {
                $this->seenSources[$sourceId] ??= ['line' => $line, 'values' => $attributes];
            }

            return $outcome;
        }

        $saved = $this->write($attributes, $sourceId);
        if ($saved === null) {
            return $this->refused($attributes, null);
        }
        $outcome->createdRef(Trainings::MORPH_ALIAS, $saved->id, $saved->updated_at);

        return $outcome->subjectRef(Trainings::MORPH_ALIAS, $saved->id, true);
    }

    /**
     * Simulation: the training an earlier row of the file creates gets
     * updated by this one (its values checked over the earlier ones; the
     * code is not checked again).
     *
     * @param  array{line: int, values: array<string, mixed>}  $earlier
     * @param  array<string, mixed>  $attributes
     */
    private function earlier(array $earlier, array $attributes, string $reason): RowOutcome
    {
        $errors = $this->errors(array_diff_key([...$earlier['values'], ...$attributes], ['code' => true]), null);
        if ($errors !== []) {
            return RowOutcome::error(...$errors);
        }

        return (new RowOutcome(ImportRow::UPDATED))->add('info', "{$reason} {$earlier['line']} : la formation sera mise à jour.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes, ?string $sourceId): ?TrainingData
    {
        try {
            return $this->trainings->upsertFromImport($attributes, $sourceId === null ? null : $this->sourceSystem, $sourceId);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * A write refused by the catalogue (a training changed meanwhile): its messages.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function refused(array $attributes, ?string $existingId): RowOutcome
    {
        $errors = $this->errors($attributes, $existingId);

        return RowOutcome::error(...($errors !== [] ? $errors : ['Ligne non importée : le catalogue l\'a refusée.']));
    }

    /**
     * Messages of the catalogue rules, prefixed by the label of the import field.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function errors(array $attributes, ?string $existingId): array
    {
        $byKey = $this->trainings->importErrors($attributes, $existingId);

        $labels = ImportTarget::Trainings->fields();
        $errors = [];
        foreach ($byKey as $key => $messages) {
            $field = array_search($key, self::KEYS, true);
            $label = $labels[$field === false ? $key : $field] ?? $key;
            foreach ($messages as $message) {
                $errors[] = "{$label} : {$message}";
            }
        }

        return $errors;
    }

    /**
     * Import values under the catalogue's keys.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function attributes(array $values): array
    {
        $attributes = [];
        foreach ($values as $field => $value) {
            $key = self::KEYS[$field] ?? $field;
            if (in_array($key, Trainings::IMPORT_KEYS, true)) {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private static function split(string $pattern, string $raw): array
    {
        return preg_split($pattern, $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
