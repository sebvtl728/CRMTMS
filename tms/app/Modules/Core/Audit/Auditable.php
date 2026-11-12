<?php

namespace App\Modules\Core\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Journals created / updated (changed fields only) / deleted / restored.
 *
 * A model may declare `protected array $auditExclude` (secrets) and
 * `protected array $auditIgnore` (noise) properties. Secrets in $auditExclude are recorded as "[masqué]" (the fact that they
 * changed is kept, never the value). Noise in $auditIgnore (e.g. last_login_at,
 * covered by auth.login) is dropped; an update that only touches ignored
 * fields writes nothing.
 *
 * @mixin Model
 */
trait Auditable
{
    public const AUDIT_MASK = '[masqué]';

    public static function bootAuditable(): void
    {
        static::created(function (Model $m): void {
            /** @var static $m */
            $m->writeAudit('created', [], $m->getAttributes());
        });

        static::updated(function (Model $m): void {
            /** @var static $m */
            $changes = $m->getChanges();
            $old = [];
            foreach (array_keys($changes) as $key) {
                $old[$key] = $m->getRawOriginal($key);
            }
            $m->writeAudit('updated', $old, $changes);
        });

        static::deleted(function (Model $m): void {
            /** @var static $m */
            $m->writeAudit('deleted', $m->getAttributes(), []);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $m): void {
                /** @var static $m */
                $m->writeAudit('restored', [], $m->getAttributes());
            });
        }
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function writeAudit(string $event, array $old, array $new): void
    {
        // @phpstan-ignore function.alreadyNarrowedType
        $declared = property_exists($this, 'auditIgnore') ? $this->auditIgnore : [];
        $ignore = ['updated_at', 'created_at', 'deleted_at', ...$declared];
        $old = $this->auditFilter($old, $ignore);
        $new = $this->auditFilter($new, $ignore);

        if ($old === [] && $new === []) {
            return;
        }

        app(AuditRecorder::class)->record($event, $this, $old, $new);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $ignore
     * @return array<string, mixed>
     */
    private function auditFilter(array $values, array $ignore): array
    {
        $values = array_diff_key($values, array_flip($ignore));

        // @phpstan-ignore function.alreadyNarrowedType
        $secrets = property_exists($this, 'auditExclude') ? $this->auditExclude : [];

        foreach ($secrets as $secret) {
            if (array_key_exists($secret, $values)) {
                $values[$secret] = self::AUDIT_MASK;
            }
        }

        return $values;
    }
}
