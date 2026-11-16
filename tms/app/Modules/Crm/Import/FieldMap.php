<?php

namespace App\Modules\Crm\Import;

use InvalidArgumentException;

/**
 * Which file column feeds which target field: column => field. A field is
 * fed by one column at most; unmapped columns are ignored.
 */
final class FieldMap
{
    /**
     * @param  array<string, string>  $map
     */
    private function __construct(public readonly ImportTarget $target, private readonly array $map) {}

    /**
     * @param  array<string, mixed>  $map  column => field ('' or null: ignored)
     *
     * @throws InvalidArgumentException (French message) on an unknown or repeated field
     */
    public static function fromArray(ImportTarget $target, array $map): self
    {
        $fields = $target->fields();
        $clean = [];

        foreach ($map as $column => $field) {
            if ($field === null || $field === '') {
                continue;
            }
            if (! is_string($field) || ! isset($fields[$field])) {
                throw new InvalidArgumentException('Champ inconnu pour la colonne « '.$column.' ».');
            }
            if (in_array($field, $clean, true)) {
                throw new InvalidArgumentException('Le champ « '.$fields[$field].' » est associé à plusieurs colonnes.');
            }
            $clean[(string) $column] = $field;
        }

        return new self($target, $clean);
    }

    /**
     * Proposed mapping (see ColumnGuesser): the organisme's previous choices,
     * then a header equal to a field label or synonym (ignoring accents, case,
     * punctuation and spacing), then a close header, then the content of the
     * sample rows. Each field is proposed once, to its surest column.
     *
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sampleRows  column => value
     */
    public static function guess(ImportTarget $target, array $headers, array $sampleRows = []): self
    {
        return new self($target, array_map(fn (array $guess) => $guess['field'], ColumnGuesser::guess($target, $headers, $sampleRows)));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    public function columnFor(string $field): ?string
    {
        $column = array_search($field, $this->map, true);

        return $column === false ? null : (string) $column;
    }

    public function has(string $field): bool
    {
        return $this->columnFor($field) !== null;
    }

    /**
     * field => value ('' when empty) for one file row.
     *
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    public function values(array $row): array
    {
        $values = [];
        foreach ($this->map as $column => $field) {
            $values[$field] = $row[$column] ?? '';
        }

        return $values;
    }
}
