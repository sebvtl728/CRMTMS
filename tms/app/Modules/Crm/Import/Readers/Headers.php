<?php

namespace App\Modules\Crm\Import\Readers;

/**
 * Header row handling shared by the readers: names trimmed, empty ones
 * named « Colonne N », a repeated name (ignoring case) kept once (first
 * column wins) with a warning.
 */
final class Headers
{
    /** @var list<string> */
    public array $names = [];

    /** @var array<int, string> cell index => column name (kept columns only) */
    public array $indexes = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  list<string>  $cells
     */
    public function __construct(array $cells)
    {
        $reported = [];

        foreach ($cells as $i => $cell) {
            $name = Cells::clean($cell);
            if ($name === '') {
                $name = 'Colonne '.($i + 1);
            }

            if (in_array(mb_strtolower($name), array_map(mb_strtolower(...), $this->names), true)) {
                if (! isset($reported[mb_strtolower($name)])) {
                    $this->warnings[] = "La colonne « {$name} » apparaît plusieurs fois : seule la première est utilisée.";
                    $reported[mb_strtolower($name)] = true;
                }

                continue;
            }

            $this->names[] = $name;
            $this->indexes[$i] = $name;
        }
    }

    /**
     * column => value for one row of cells; null when every cell is empty.
     *
     * @param  array<int, string>  $cells
     * @return array<string, string>|null
     */
    public function combine(array $cells): ?array
    {
        $row = [];
        $empty = true;

        foreach ($this->indexes as $i => $name) {
            $value = Cells::clean($cells[$i] ?? '');
            $row[$name] = $value;
            $empty = $empty && $value === '';
        }

        if ($empty) {
            // Also skip rows whose only content is in ignored columns.
            return null;
        }

        return $row;
    }
}
