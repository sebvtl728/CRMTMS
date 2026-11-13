<?php

namespace App\Modules\Core\Audit;

use Illuminate\Database\ConnectionInterface;

/**
 * Rewrites morph type columns holding a class name (data written before the
 * morph map was enforced) to the stable alias. Tables are under FORCE row
 * level security, so the rewrite runs once per tenant with app.tenant_id set
 * (and the previous setting restored afterwards).
 */
final class MorphDataMigration
{
    /** @var array<string, string> legacy class name => alias */
    public const LEGACY = [
        'App\Modules\Core\Tenancy\Tenant' => 'tenant',
        'App\Modules\Core\Users\User' => 'user',
        'App\Modules\Core\Users\Invitation' => 'invitation',
        'App\Modules\Core\Companies\Company' => 'company',
        'App\Modules\Core\References\ReferenceItem' => 'reference_item',
        'App\Modules\Connectors\Core\ConnectorConfig' => 'connector_config',
        'App\Modules\Crm\Organisations\Organisation' => 'organisation',
        'App\Modules\Crm\People\Person' => 'person',
        'App\Modules\Crm\Affiliations\Affiliation' => 'affiliation',
        'App\Modules\Crm\Timeline\Note' => 'note',
        'App\Modules\Crm\Timeline\Attachment' => 'attachment',
    ];

    /** @var array<string, string> table => type column */
    public const COLUMNS = [
        'model_has_roles' => 'model_type',
        'model_has_permissions' => 'model_type',
        'audit_logs' => 'auditable_type',
        'notes' => 'notable_type',
        'attachments' => 'notable_type',
    ];

    /**
     * @param  list<string>  $skip  tables to leave alone (the caller handles them)
     */
    public static function run(ConnectionInterface $db, array $skip = []): void
    {
        $previous = $db->selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach ($db->table('tenants')->pluck('id') as $tenantId) {
            $db->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

            foreach (self::COLUMNS as $table => $column) {
                if (in_array($table, $skip, true)) {
                    continue;
                }
                foreach (self::LEGACY as $class => $alias) {
                    $db->table($table)->where($column, $class)->update([$column => $alias]);
                }
            }
        }

        $db->statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }
}
