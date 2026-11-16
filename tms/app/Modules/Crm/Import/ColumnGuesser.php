<?php

namespace App\Modules\Crm\Import;

/**
 * Proposed mapping of the « Colonnes » step, with the reason of each
 * proposal. From the surest to the least sure:
 *
 * 1. memory: the organisme's previous choice for this header (HeaderChoices),
 *    « Ignorer » included;
 * 2. header: the header equals a field label, then a synonym (unchanged
 *    rule: first column, first field);
 * 3. similar: once abbreviations are written out and stop words left out,
 *    the header contains a synonym, or is part of one (« Tél portable
 *    contact » → mobile); the longest match wins;
 * 4. content: at least 3 filled cells, 80 % of them having the shape of a
 *    field (ValueShapes). The sample is the file's first 50 non-empty rows
 *    (not 50 filled cells per column), read by the caller.
 *
 * Each field goes to one column, the surest; a column whose best level names
 * two fields equally is left on « Ignorer ». Sample rows are read, never kept.
 */
final class ColumnGuesser
{
    public const MEMORY = 'memory';

    public const HEADER = 'header';

    public const SIMILAR = 'similar';

    public const CONTENT = 'content';

    /** Non-empty rows of the file the caller reads as sample (not per column). */
    public const SAMPLE = 50;

    /** Filled cells needed to judge a column from its content. */
    private const MIN_FILLED = 3;

    /** Share of the filled cells that must have the shape. */
    private const THRESHOLD = 0.8;

    private const LEVELS = [self::MEMORY => 4, self::HEADER => 3, self::SIMILAR => 2, self::CONTENT => 1];

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sampleRows  column => value
     * @return array<string, array{field: string, reason: 'memory'|'header'|'similar'|'content'}> in file order; columns left on « Ignorer » are absent
     */
    public static function guess(ImportTarget $target, array $headers, array $sampleRows = []): array
    {
        $memory = HeaderChoices::for($target, $headers);
        $fieldOrder = array_flip(array_keys($target->fields()));

        /** @var list<array{column: int, field: string, reason: string, score: float}> $candidates */
        $candidates = [];
        foreach ($headers as $index => $header) {
            $key = Text::key($header);
            if (array_key_exists($key, $memory)) {
                if ($memory[$key] !== null) {
                    $candidates[] = ['column' => $index, 'field' => $memory[$key], 'reason' => self::MEMORY, 'score' => 0.0];
                }

                continue; // a remembered « Ignorer » stays ignored
            }

            $exact = self::exact($target, $key);
            if ($exact !== []) {
                foreach ($exact as $field => $score) {
                    $candidates[] = ['column' => $index, 'field' => $field, 'reason' => self::HEADER, 'score' => $score];
                }

                continue;
            }

            $similar = self::similar($target, $key);
            if ($similar !== []) {
                $best = max($similar);
                $top = array_keys($similar, $best, true);
                if (count($top) === 1) {
                    $candidates[] = ['column' => $index, 'field' => $top[0], 'reason' => self::SIMILAR, 'score' => $best];
                }

                continue; // ambiguous header: not guessed from the content either
            }

            $content = self::content($target, $header, $sampleRows);
            if ($content !== null) {
                $candidates[] = ['column' => $index, 'field' => $content[0], 'reason' => self::CONTENT, 'score' => $content[1]];
            }
        }

        usort($candidates, fn (array $a, array $b) => [self::LEVELS[$b['reason']], $b['score'], $a['column'], $fieldOrder[$a['field']] ?? PHP_INT_MAX]
            <=> [self::LEVELS[$a['reason']], $a['score'], $b['column'], $fieldOrder[$b['field']] ?? PHP_INT_MAX]);

        $byColumn = [];
        $taken = [];
        foreach ($candidates as $candidate) {
            if (isset($byColumn[$candidate['column']]) || isset($taken[$candidate['field']])) {
                continue;
            }
            $byColumn[$candidate['column']] = ['field' => $candidate['field'], 'reason' => $candidate['reason']];
            $taken[$candidate['field']] = true;
        }

        $guess = [];
        foreach ($headers as $index => $header) {
            if (isset($byColumn[$index])) {
                $guess[$header] = $byColumn[$index];
            }
        }

        /** @var array<string, array{field: string, reason: 'memory'|'header'|'similar'|'content'}> $guess */
        return $guess;
    }

    /**
     * Fields whose label (score 2) or a synonym (score 1) equals the header key.
     * Two fields sharing a synonym (« référence » for code and source_id) are
     * no ambiguity here: the legacy rule keeps the first field of the target.
     *
     * @return array<string, float>
     */
    private static function exact(ImportTarget $target, string $key): array
    {
        if ($key === '') {
            return [];
        }
        $matches = [];
        foreach ($target->fields() as $field => $label) {
            if (Text::key($label) === $key) {
                $matches[$field] = 2.0;
            } elseif (in_array($key, $target->synonyms($field), true)) {
                $matches[$field] = 1.0;
            }
        }

        return $matches;
    }

    /**
     * Fields close to the header, with the size of the match: the number of
     * words of the synonym the header contains, or (a little less) of the
     * header contained in a synonym it covers more than half (« Date » is
     * not « Date de naissance »).
     *
     * @return array<string, float>
     */
    private static function similar(ImportTarget $target, string $key): array
    {
        $words = self::words($key);
        if ($words === []) {
            return [];
        }
        $scores = [];
        foreach (array_keys($target->fields()) as $field) {
            foreach ($target->synonyms($field) as $synonym) {
                $synonymWords = self::words($synonym, true);
                if ($synonymWords === []) {
                    continue;
                }
                $score = 0.0;
                if (array_diff($synonymWords, $words) === []) {
                    $score = count($synonymWords);
                } elseif (array_diff($words, $synonymWords) === [] && count($words) * 2 > count($synonymWords)) {
                    $score = count($words) - 0.5;
                }
                if ($score > ($scores[$field] ?? 0.0)) {
                    $scores[$field] = $score;
                }
            }
        }

        return $scores;
    }

    /**
     * Distinct meaningful words of a comparison key: abbreviations written
     * out, stop words and single letters left out. A synonym reduced by this
     * to a single word (« nom client » → « nom ») is too vague: none.
     *
     * @return list<string>
     */
    private static function words(string $key, bool $synonym = false): array
    {
        $key = preg_replace('/\be mails?\b/', 'email', $key) ?? $key;
        $words = [];
        $dropped = false;
        foreach (explode(' ', $key) as $word) {
            foreach (explode(' ', ImportTarget::ABBREVIATIONS[$word] ?? $word) as $expanded) {
                if ($expanded === '' || in_array($expanded, ImportTarget::STOP_WORDS, true) || preg_match('/^[a-z]$/', $expanded) === 1) {
                    $dropped = true;

                    continue;
                }
                $words[$expanded] = true;
            }
        }

        return $synonym && $dropped && count($words) < 2 ? [] : array_keys($words);
    }

    /**
     * The field and the share of the column's first filled cells having its
     * shape, when at least THRESHOLD; mobiles and landlines mixed count as
     * phone numbers.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{0: string, 1: float}|null
     */
    private static function content(ImportTarget $target, string $header, array $rows): ?array
    {
        $shapes = [];
        $filled = 0;
        foreach ($rows as $row) {
            $value = trim($row[$header] ?? '');
            if ($value === '') {
                continue;
            }
            $shape = ValueShapes::of($value);
            if ($shape !== null) {
                $shapes[$shape] = ($shapes[$shape] ?? 0) + 1;
            }
            if (++$filled === self::SAMPLE) {
                break;
            }
        }
        if ($filled < self::MIN_FILLED) {
            return null;
        }
        arsort($shapes);
        $shape = array_key_first($shapes);
        $count = $shape === null ? 0 : $shapes[$shape];
        $phones = ($shapes[ValueShapes::MOBILE] ?? 0) + ($shapes[ValueShapes::PHONE] ?? 0);
        if ($count / $filled < self::THRESHOLD && $phones / $filled >= self::THRESHOLD) {
            [$shape, $count] = [ValueShapes::PHONE, $phones];
        }
        if ($shape === null || $count / $filled < self::THRESHOLD) {
            return null;
        }
        $field = $target->shapeField($shape);

        return $field === null ? null : [$field, $count / $filled];
    }
}
