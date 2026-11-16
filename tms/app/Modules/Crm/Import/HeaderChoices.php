<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memory of the « Colonnes » step, per organisme and type of import: the
 * field (or « Ignorer ») last chosen for a header, proposed first next time.
 */
final class HeaderChoices
{
    private const MAX_KEY = 200;

    /**
     * The choices worth remembering, compared with what the server proposed
     * (ColumnGuesser, recomputed at save, never taken from the client):
     * a field the user chose or changed, or a proposal from the memory or the
     * header he kept; « Ignorer » only when it replaced a proposal. A content
     * proposal left as is, or a column left on « Ignorer » untouched, says
     * nothing about the header: not remembered.
     *
     * @param  array<string, array{field: string, reason: string}>  $proposals  ColumnGuesser::guess()
     * @param  array<string, string|null>  $given  column => field ('' or null: « Ignorer »)
     * @return array<string, string|null>
     */
    public static function deliberate(array $proposals, array $given): array
    {
        $choices = [];
        foreach ($given as $column => $field) {
            $field = $field === '' ? null : $field;
            $proposal = $proposals[$column] ?? null;
            if ($field === null) {
                if ($proposal !== null) {
                    $choices[$column] = null;
                }
            } elseif ($proposal === null || $proposal['field'] !== $field || $proposal['reason'] !== ColumnGuesser::CONTENT) {
                $choices[$column] = $field;
            }
        }

        return $choices;
    }

    /**
     * Records the validated choices: column => field, null for « Ignorer ».
     *
     * @param  array<string, string|null>  $columnToField
     */
    public static function remember(ImportTarget $target, array $columnToField): void
    {
        $tenantId = app(TenantContext::class)->id();
        $fields = $target->fields();
        $now = now();
        $rows = [];

        foreach ($columnToField as $column => $field) {
            $key = mb_substr(Text::key((string) $column), 0, self::MAX_KEY);
            $field = $field === '' ? null : $field;
            if ($key === '' || ($field !== null && ! isset($fields[$field]))) {
                continue;
            }
            // Two columns with the same key: the last one wins, as one row.
            $rows[$key] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'target' => $target->value,
                'header_key' => $key, 'field' => $field, 'uses' => 1, 'last_used_at' => $now];
        }
        if ($rows === []) {
            return;
        }

        HeaderChoice::query()->upsert(array_values($rows), ['tenant_id', 'target', 'header_key'],
            ['field', 'last_used_at', 'uses' => DB::raw('import_header_choices.uses + 1')]);
    }

    /**
     * Previous choices for these headers: header key => field (null: « Ignorer »).
     *
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public static function for(ImportTarget $target, array $headers): array
    {
        if (app(TenantContext::class)->current() === null) {
            return [];
        }
        $keys = array_values(array_unique(array_filter(array_map(fn (string $h) => mb_substr(Text::key($h), 0, self::MAX_KEY), $headers))));
        if ($keys === []) {
            return [];
        }

        $fields = $target->fields();
        $choices = [];
        foreach (HeaderChoice::query()->where('target', $target->value)->whereIn('header_key', $keys)->get(['header_key', 'field']) as $choice) {
            // A field the target no longer has is forgotten.
            if ($choice->field === null || isset($fields[$choice->field])) {
                $choices[$choice->header_key] = $choice->field;
            }
        }

        return $choices;
    }
}
