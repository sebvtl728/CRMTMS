<?php

namespace App\Modules\Connectors\Ai;

/**
 * The JSON schemas of the AI requests.
 *
 *  - sendable(): the schema without the constraints providers do not all
 *    accept in a structured output (lengths, ranges, item counts, pattern):
 *    they are checked here instead, on the answer;
 *  - conform(): checks a decoded answer against the full schema (types,
 *    required properties, enum, lengths, ranges, item counts) and drops the
 *    properties the schema does not declare. Supported keywords: type
 *    (string or list), properties, required, additionalProperties, items,
 *    enum, minLength, maxLength, minimum, maximum, exclusiveMinimum,
 *    exclusiveMaximum, minItems, maxItems.
 */
final class AiSchema
{
    private const CHECKED_HERE = ['minLength', 'maxLength', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minItems', 'maxItems', 'uniqueItems', 'pattern'];

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function sendable(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            if (in_array($key, self::CHECKED_HERE, true)) {
                continue;
            }
            $out[$key] = match (true) {
                $key === 'properties' && is_array($value) => array_map(fn ($s) => is_array($s) ? self::sendable($s) : $s, $value),
                $key === 'items' && is_array($value) => self::sendable($value),
                in_array($key, ['anyOf', 'allOf', 'oneOf'], true) && is_array($value) => array_map(fn ($s) => is_array($s) ? self::sendable($s) : $s, $value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws AiInvalidResponse
     */
    public static function conform(mixed $value, array $schema): mixed
    {
        $types = (array) ($schema['type'] ?? []);
        if ($types !== [] && ! self::anyType($value, $types)) {
            throw new AiInvalidResponse;
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new AiInvalidResponse;
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if ((isset($schema['minLength']) && $length < $schema['minLength']) || (isset($schema['maxLength']) && $length > $schema['maxLength'])) {
                throw new AiInvalidResponse;
            }
        } elseif (is_int($value) || is_float($value)) {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) || (isset($schema['maximum']) && $value > $schema['maximum'])
                || (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) || (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum'])) {
                throw new AiInvalidResponse;
            }
        } elseif (is_array($value) && array_is_list($value) && in_array('array', $types, true)) {
            $count = count($value);
            if ((isset($schema['minItems']) && $count < $schema['minItems']) || (isset($schema['maxItems']) && $count > $schema['maxItems'])) {
                throw new AiInvalidResponse;
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                $value = array_map(fn (mixed $item) => self::conform($item, $schema['items']), $value);
            }
        } elseif (is_array($value)) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $value)) {
                    throw new AiInvalidResponse;
                }
            }
            $kept = [];
            foreach ($value as $key => $item) {
                if (isset($properties[$key]) && is_array($properties[$key])) {
                    $kept[$key] = self::conform($item, $properties[$key]);
                } elseif (($schema['additionalProperties'] ?? true) !== false && $properties === []) {
                    $kept[$key] = $item;
                }
            }
            $value = $kept;
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $types
     */
    private static function anyType(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
