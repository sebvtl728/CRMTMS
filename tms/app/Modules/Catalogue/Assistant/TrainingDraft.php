<?php

namespace App\Modules\Catalogue\Assistant;

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

        return $proposal;
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
