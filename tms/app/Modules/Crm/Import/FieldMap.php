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
     * Proposed mapping: a header equal to a field label or to one of its
     * synonyms, ignoring accents, case, punctuation and spacing. Exact labels
     * win over synonyms; each field is proposed once, to its first column.
     *
     * @param  list<string>  $headers
     */
    public static function guess(ImportTarget $target, array $headers): self
    {
        $fields = array_keys($target->fields());
        $map = [];

        foreach ([true, false] as $labelsOnly) {
            foreach ($headers as $header) {
                if (isset($map[$header])) {
                    continue;
                }
                $key = Text::key($header);
                foreach ($fields as $field) {
                    if (in_array($field, $map, true)) {
                        continue;
                    }
                    $candidates = $labelsOnly ? [Text::key($target->fields()[$field])] : $target->synonyms($field);
                    if ($key !== '' && in_array($key, $candidates, true)) {
                        $map[$header] = $field;
                        break;
                    }
                }
            }
        }

        // File order.
        $ordered = [];
        foreach ($headers as $header) {
            if (isset($map[$header])) {
                $ordered[$header] = $map[$header];
            }
        }

        return new self($target, $ordered);
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
