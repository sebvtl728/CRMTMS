<?php

namespace App\Modules\Crm\Import;

/**
 * Correspondences chosen by the user for values of the file that are not
 * found as they are: field => [file value => action]. The action is the id
 * of a reference item (or of a user for « responsible », a status code for
 * « status »), CREATE (« créer la valeur dans le référentiel ») or EMPTY
 * (« laisser vide »). File values are compared on Text::key (accents, case
 * and spacing ignored).
 */
final class ValueMapper
{
    public const CREATE = '__create__';

    public const EMPTY = '__empty__';

    /** @var array<string, array<string, string>> field => key => action */
    private array $map = [];

    /** @var array<string, array<string, string>> as given (for storage) */
    private array $raw = [];

    /**
     * @param  array<mixed>  $map
     */
    public function __construct(array $map = [])
    {
        foreach ($map as $field => $values) {
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $value => $action) {
                if (! is_string($action) || $action === '') {
                    continue;
                }
                $this->raw[(string) $field][(string) $value] = $action;
                $this->map[(string) $field][Text::key((string) $value)] = $action;
            }
        }
    }

    /**
     * @param  array<mixed>|null  $map
     */
    public static function fromArray(?array $map): self
    {
        return new self($map ?? []);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    public function actionFor(string $field, string $value): ?string
    {
        return $this->map[$field][Text::key($value)] ?? null;
    }
}
