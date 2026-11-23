<?php

namespace App\Modules\Catalogue;

use Illuminate\Validation\ValidationException;

/**
 * Competence grid of a training (apprenticeship booklet, Qualiopi indicator
 * 7): activity types → competences → skills, stored as jsonb on the
 * training and typed as text, one line each: « AT … », « CP … », « - … »
 * (or « • », « * »). Blank lines are ignored.
 *
 * @phpstan-type Grid list<array{title: string, competences: list<array{title: string, skills: list<string>}>}>
 */
final class Competences
{
    public const MAX_LINES = 3000;

    public const MAX_LINE = 500;

    /**
     * The grid of $text, null when empty; ValidationException (key
     * competences_text, French message with the line number) when malformed.
     *
     * @return Grid|null
     */
    public static function parse(string $text): ?array
    {
        $grid = [];
        $lines = preg_split('/\R/u', $text) ?: [];
        if (count($lines) > self::MAX_LINES) {
            self::fail('La grille dépasse '.self::MAX_LINES.' lignes.');
        }

        foreach ($lines as $i => $raw) {
            $line = trim((string) preg_replace('/[\x{00A0}\t]+/u', ' ', $raw));
            if ($line === '') {
                continue;
            }
            $n = $i + 1;
            if (mb_strlen($line) > self::MAX_LINE) {
                self::fail("Ligne {$n} : la ligne dépasse ".self::MAX_LINE.' caractères.');
            }

            if (preg_match('/^AT\b\s*[:.\-–]?\s*(.+)$/u', $line, $m) === 1) {
                $grid[] = ['title' => trim($m[1]), 'competences' => []];
            } elseif (preg_match('/^CP\b\s*[:.\-–]?\s*(.+)$/u', $line, $m) === 1) {
                if ($grid === []) {
                    self::fail("Ligne {$n} : une compétence (CP) doit suivre une activité type (AT).");
                }
                $grid[array_key_last($grid)]['competences'][] = ['title' => trim($m[1]), 'skills' => []];
            } elseif (preg_match('/^[-•*–]\s*(.+)$/u', $line, $m) === 1) {
                $at = $grid === [] ? null : array_key_last($grid);
                if ($at === null || $grid[$at]['competences'] === []) {
                    self::fail("Ligne {$n} : un savoir-faire (-) doit suivre une compétence (CP).");
                }
                $cp = array_key_last($grid[$at]['competences']);
                $grid[$at]['competences'][$cp]['skills'][] = trim($m[1]);
            } else {
                self::fail("Ligne {$n} : commencez la ligne par AT, CP ou un tiret.");
            }
        }

        return $grid === [] ? null : $grid;
    }

    /**
     * @param  Grid|null  $grid
     */
    public static function toText(?array $grid): string
    {
        $lines = [];
        foreach ($grid ?? [] as $at) {
            $lines[] = 'AT '.$at['title'];
            foreach ($at['competences'] as $cp) {
                $lines[] = 'CP '.$cp['title'];
                foreach ($cp['skills'] as $skill) {
                    $lines[] = '- '.$skill;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Number of competences of the grid.
     *
     * @param  Grid|null  $grid
     */
    public static function count(?array $grid): int
    {
        return array_sum(array_map(fn (array $at) => count($at['competences']), $grid ?? []));
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['competences_text' => $message]);
    }
}
