<?php

namespace App\Modules\Core\CustomFields;

/**
 * Registry of the fiches that carry custom fields. Each module declares its
 * own in its register() (Admissions « candidature », Catalogue « training »);
 * Core imports none of them.
 */
final class CustomFieldEntities
{
    /** @var array<string, CustomFieldEntity> */
    private static array $entities = [];

    public static function register(CustomFieldEntity $entity): void
    {
        self::$entities[$entity->key] = $entity;
    }

    public static function find(string $key): ?CustomFieldEntity
    {
        return self::$entities[$key] ?? null;
    }

    /**
     * In their order on the settings screen.
     *
     * @return list<CustomFieldEntity>
     */
    public static function all(): array
    {
        $all = array_values(self::$entities);
        usort($all, fn (CustomFieldEntity $a, CustomFieldEntity $b) => [$a->position, $a->label] <=> [$b->position, $b->label]);

        return $all;
    }
}
