<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\OrganisationStatus;

/**
 * Turns file values into stored values for the mappable fields, from maps
 * loaded once per run (no query per row): active reference items of the
 * organisme (label or code), active users (name or e-mail), statuses
 * (label or code), then the user's ValueMapper choices. A value asked to be
 * created is created once (never during a simulation).
 */
final class ValueResolver
{
    /** @var array<string, array<string, string>> list => key => item id */
    private array $items = [];

    /** @var array<string, true> list => item ids (accepted as explicit choices) */
    private array $ids = [];

    /** @var array<string, string> key => user id */
    private array $users = [];

    /** @var array<string, true> */
    private array $userIds = [];

    /** @var array<string, list<string>> field => distinct unknown values */
    private array $unknown = [];

    /** @var array{0: array<string, array<string, string>>, 1: array<string, true>}|null */
    private ?array $checkpoint = null;

    public function __construct(
        private readonly ImportTarget $target,
        private readonly ValueMapper $mapper,
        private readonly bool $dryRun,
    ) {
        $lists = array_unique(array_map(fn (ReferenceList $l) => $l->value, $target->referenceFields()));
        if ($lists !== []) {
            ReferenceItem::query()->whereIn('list', $lists)->where('is_active', true)
                ->orderBy('position')->get(['id', 'list', 'code', 'label'])
                ->each(function (ReferenceItem $item): void {
                    $list = $item->list->value;
                    $this->items[$list][Text::key($item->label)] ??= $item->id;
                    $this->items[$list][Text::key($item->code)] ??= $item->id;
                    $this->ids[$item->id] = true;
                });
        }

        if (in_array('responsible', $target->mappableFields(), true)) {
            User::query()->whereNull('deactivated_at')->get(['id', 'name', 'email'])->each(function (User $user): void {
                $this->users[Text::key((string) $user->email)] = $user->id;
                $this->users[Text::key((string) $user->name)] ??= $user->id;
                $this->userIds[$user->id] = true;
            });
        }
    }

    /**
     * @return array{value: string|null, error: string|null}
     */
    public function resolve(string $field, string $value): array
    {
        $label = $this->target->fields()[$field] ?? $field;
        $key = Text::key($value);
        $action = $this->mapper->actionFor($field, $value);

        if ($action === ValueMapper::EMPTY) {
            return ['value' => null, 'error' => null];
        }

        if (str_ends_with($field, 'status')) {
            foreach (OrganisationStatus::cases() as $status) {
                if ($key === Text::key($status->value) || $key === Text::key($status->label()) || $action === $status->value) {
                    return ['value' => $status->value, 'error' => null];
                }
            }

            return $this->unknown($field, $value, "Statut « {$value} » inconnu (Prospect, Client ou Inactif) : choisissez une correspondance.");
        }

        if ($field === 'responsible') {
            $id = $this->users[$key] ?? (is_string($action) && isset($this->userIds[$action]) ? $action : null);

            return $id !== null ? ['value' => $id, 'error' => null]
                : $this->unknown($field, $value, "Responsable « {$value} » inconnu ou désactivé : choisissez une correspondance.");
        }

        $list = $this->target->referenceFields()[$field] ?? null;
        if ($list === null) {
            return ['value' => $value, 'error' => null];
        }

        $id = $this->items[$list->value][$key] ?? null;
        if ($id !== null) {
            return ['value' => $id, 'error' => null];
        }
        if (is_string($action) && isset($this->ids[$action])) {
            return ['value' => $action, 'error' => null];
        }
        if ($action === ValueMapper::CREATE) {
            return ['value' => $this->create($list, $value), 'error' => null];
        }

        return $this->unknown($field, $value, "Valeur « {$value} » inconnue pour {$label} : choisissez une correspondance, créez-la ou laissez vide.");
    }

    /**
     * Remembers the known items before a chunk (see restore()).
     */
    public function checkpoint(): void
    {
        $this->checkpoint = [$this->items, $this->ids];
    }

    /**
     * Forgets the items created since checkpoint(): their chunk was rolled back.
     */
    public function restore(): void
    {
        if ($this->checkpoint !== null) {
            [$this->items, $this->ids] = $this->checkpoint;
        }
    }

    /**
     * Distinct values without correspondence, per field (for the wizard).
     *
     * @return array<string, list<string>>
     */
    public function unknownValues(): array
    {
        return $this->unknown;
    }

    /**
     * @return array{value: null, error: string}
     */
    private function unknown(string $field, string $value, string $message): array
    {
        if (! in_array($value, $this->unknown[$field] ?? [], true)) {
            $this->unknown[$field][] = $value;
        }

        return ['value' => null, 'error' => $message];
    }

    /**
     * Id of the new item (created once; a placeholder in a simulation).
     */
    private function create(ReferenceList $list, string $label): ?string
    {
        $key = Text::key($label);
        if ($this->dryRun) {
            return null;
        }

        $label = mb_substr($label, 0, 255);
        $item = ReferenceItem::query()->create([
            'list' => $list,
            'code' => ReferenceItem::uniqueCode($list, $label),
            'label' => $label,
            'position' => (int) ReferenceItem::query()->where('list', $list)->max('position') + 1,
        ]);
        $this->items[$list->value][$key] = $item->id;
        $this->ids[$item->id] = true;

        return $item->id;
    }
}
