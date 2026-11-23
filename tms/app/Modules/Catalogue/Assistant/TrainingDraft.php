<?php

namespace App\Modules\Catalogue\Assistant;

use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingListQuery;
use App\Modules\Catalogue\TrainingRequest;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Support\Str;

/**
 * The training sheet the AI proposes.
 *
 *  - schema(): the JSON schema imposed on the AI. Ai::structured checks the
 *    answer against it (types, lengths, at most 20 objectives and 30
 *    modules, durations 0 < h ≤ 2 000): an answer that breaks it is refused
 *    whole (AiInvalidResponse), nothing is proposed;
 *  - proposal(): the checked answer as form values: texts trimmed (empty =
 *    null), durations typed the French way (« 7,5 »), the DAS and the
 *    modalities named by the AI brought back to the id of an active item of
 *    the organisme's lists, or left empty (never created).
 */
final class TrainingDraft
{
    public const MAX_HOURS = 2000;

    public const MAX_DAYS = 365;

    public const MAX_PRICE = 1000000;

    public const TEXTS = ['description', 'audience', 'prerequisites', ...TrainingRequest::SHEET_TEXTS];

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = fn (int $max): array => ['type' => ['string', 'null'], 'maxLength' => $max];
        $hours = ['type' => ['number', 'null'], 'exclusiveMinimum' => 0, 'maximum' => self::MAX_HOURS];
        $texts = array_fill_keys(array_values(array_unique(self::TEXTS)), $text(5000));

        $properties = [
            'title' => $text(200),
            'das' => $text(200),
            'duration_hours' => $hours,
            'duration_days' => ['type' => ['number', 'null'], 'exclusiveMinimum' => 0, 'maximum' => self::MAX_DAYS],
            'centre_hours' => $hours,
            'company_hours' => $hours,
            'price_company' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => self::MAX_PRICE],
            'price_individual' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => self::MAX_PRICE],
            ...$texts,
            'objectives' => ['type' => 'array', 'maxItems' => TrainingRequest::MAX_OBJECTIVES, 'items' => ['type' => 'string', 'maxLength' => 500]],
            'modules' => ['type' => 'array', 'maxItems' => TrainingRequest::MAX_MODULES, 'items' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                    'duration_hours' => $hours,
                    'modality' => $text(200),
                    'content' => $text(5000),
                ],
                'required' => ['title', 'duration_hours', 'modality', 'content'],
                'additionalProperties' => false,
            ]],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * The labels the AI may choose from: the organisme's active items.
     *
     * @return array{das: list<string>, modality: list<string>}
     */
    public static function choices(): array
    {
        $labels = fn (ReferenceList $list) => array_map(fn (array $o) => $o['label'], TrainingListQuery::reference($list));

        return ['das' => $labels(ReferenceList::Das), 'modality' => $labels(ReferenceList::TrainingModality)];
    }

    /**
     * @param  array<string, mixed>  $answer  checked against schema()
     * @return array<string, mixed>
     */
    public static function proposal(array $answer): array
    {
        $das = TrainingListQuery::reference(ReferenceList::Das);
        $modalities = TrainingListQuery::reference(ReferenceList::TrainingModality);

        $proposal = [
            'title' => self::text($answer['title'] ?? null),
            'das_id' => self::match($answer['das'] ?? null, $das),
            'duration_hours' => self::decimal($answer['duration_hours'] ?? null),
            'duration_days' => self::decimal($answer['duration_days'] ?? null),
            'centre_hours' => self::decimal($answer['centre_hours'] ?? null),
            'company_hours' => self::decimal($answer['company_hours'] ?? null),
            'price_company' => self::decimal($answer['price_company'] ?? null),
            'price_individual' => self::decimal($answer['price_individual'] ?? null),
        ];
        foreach (self::TEXTS as $key) {
            $proposal[$key] = self::text($answer[$key] ?? null);
        }

        /** @var list<mixed> $objectives */
        $objectives = is_array($answer['objectives'] ?? null) ? $answer['objectives'] : [];
        $proposal['objectives'] = array_values(array_filter(array_map(self::text(...), $objectives), fn ($o) => $o !== null));

        $proposal['modules'] = [];
        foreach (is_array($answer['modules'] ?? null) ? $answer['modules'] : [] as $module) {
            $title = is_array($module) ? self::text($module['title'] ?? null) : null;
            if ($title === null) {
                continue;
            }
            $proposal['modules'][] = [
                'title' => $title,
                'duration_hours' => self::decimal($module['duration_hours'] ?? null),
                'modality_id' => self::match($module['modality'] ?? null, $modalities),
                'content' => self::text($module['content'] ?? null),
            ];
        }

        // Only one duration given: the other from it (7 h a day, days to the half day).
        $hours = self::hundredths($answer['duration_hours'] ?? null);
        $days = self::hundredths($answer['duration_days'] ?? null);
        if ($hours !== null && $days === null) {
            $proposal['duration_days'] = self::decimal(round($hours / 100 / Training::HOURS_PER_DAY * 2) / 2);
        } elseif ($days !== null && $hours === null && $days / 100 * Training::HOURS_PER_DAY <= self::MAX_HOURS) {
            $proposal['duration_hours'] = self::decimal($days / 100 * Training::HOURS_PER_DAY);
        }

        $proposal['modules'] = self::spread($proposal['modules'], self::hundredths($answer['centre_hours'] ?? null)
            ?? (self::hundredths($answer['duration_hours'] ?? null) === null ? null
                : self::hundredths($answer['duration_hours']) - (self::hundredths($answer['company_hours'] ?? null) ?? 0)),
            self::hundredths($answer['duration_hours'] ?? null));

        return $proposal;
    }

    /**
     * The hours of the centre spread over the modules the AI left without
     * hours, in half hours (the last module takes the rest): a module
     * holding the whole duration while the others have none is not a module
     * duration and is spread too. Modules with their own hours keep them;
     * nothing changes when every module has hours or nothing is left.
     *
     * @param  list<array{title: string, duration_hours: ?string, modality_id: ?string, content: ?string}>  $modules
     * @return list<array{title: string, duration_hours: ?string, modality_id: ?string, content: ?string}>
     */
    private static function spread(array $modules, ?int $target, ?int $total): array
    {
        if ($target === null || $target <= 0 || count($modules) === 0) {
            return $modules;
        }
        $hours = array_map(fn (array $m) => $m['duration_hours'] === null ? null : (int) round((float) str_replace(',', '.', $m['duration_hours']) * 100), $modules);
        $filled = array_keys(array_filter($hours, fn ($h) => $h !== null));
        if (count($modules) > 1 && count($filled) === 1 && in_array($hours[$filled[0]], array_filter([$target, $total]), true)) {
            $hours[$filled[0]] = null;
        }
        $empty = array_keys(array_filter($hours, fn ($h) => $h === null));
        $left = $target - array_sum(array_filter($hours, fn ($h) => $h !== null));
        if ($empty === [] || $left <= 0) {
            return $modules;
        }
        $each = intdiv(intdiv($left, count($empty)), 50) * 50;
        if ($each === 0) {
            return $modules;
        }
        foreach ($empty as $n => $index) {
            $value = $n === count($empty) - 1 ? $left - $each * (count($empty) - 1) : $each;
            $modules[$index]['duration_hours'] = self::decimal($value / 100);
        }

        return $modules;
    }

    /** Hours as integer hundredths, null when not a positive number. */
    private static function hundredths(mixed $value): ?int
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? (int) round($value * 100) : null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** 14 → « 14 », 7.5 → « 7,5 » (two decimals at most); below 0,005 → null. */
    private static function decimal(mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');

        // A positive duration that rounds to 0 would be refused by the form: left empty.
        return $text === '0' || $text === '' ? null : $text;
    }

    /**
     * The id of the one active item named $label: same label, case and
     * accents aside, or the same label before a parenthesis (« Distanciel »
     * for « Distanciel (classe virtuelle) »). Null otherwise.
     *
     * @param  list<array{value: string, label: string}>  $options
     */
    private static function match(mixed $label, array $options): ?string
    {
        $wanted = is_string($label) ? self::key($label) : '';
        if ($wanted === '') {
            return null;
        }

        foreach ([fn (string $l) => self::key($l), fn (string $l) => self::key((string) preg_replace('/\s*\(.*\)\s*$/u', '', $l))] as $shape) {
            $found = array_values(array_filter($options, fn (array $o) => $shape($o['label']) === $wanted));
            if (count($found) === 1) {
                return $found[0]['value'];
            }
            if (count($found) > 1) {
                return null;
            }
        }

        return null;
    }

    private static function key(string $label): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($label))));
    }
}
