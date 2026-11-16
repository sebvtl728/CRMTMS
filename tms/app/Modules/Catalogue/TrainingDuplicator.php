<?php

namespace App\Modules\Catalogue;

use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Copies a training into a new, independent, inactive one: every sheet
 * field, the modules (new ids), the DAS, the prices and the funding modes.
 * The code stays empty (unique per organisme) and the brochures are not
 * linked. One transaction; the journal records the creation with the id of
 * the training it was copied from.
 */
final class TrainingDuplicator
{
    public const TITLE_PREFIX = 'Copie de ';

    public const TITLE_MAX = 200;

    /** Left out of the journal entry of the copy, as for any automatic creation entry (Auditable). */
    public const AUDIT_IGNORED = ['created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'];

    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    public function duplicate(Training $source): Training
    {
        return DB::transaction(function () use ($source): Training {
            $copy = new Training($source->only((new Training)->getFillable()));
            $copy->title = mb_substr(self::TITLE_PREFIX.$source->title, 0, self::TITLE_MAX);
            $copy->code = null;
            $copy->is_active = false;
            $copy->tenant_id = $this->tenants->id();
            $copy->created_by = Auth::id();
            $copy->updated_by = Auth::id();

            // The automatic « created » entry cannot carry the origin: the
            // creation is journalled by hand, once, with it.
            Training::withoutEvents(fn () => $copy->save());
            $this->audit->record('created', $copy, [], [
                ...array_diff_key($copy->getAttributes(), array_flip(self::AUDIT_IGNORED)),
                'duplicated_from' => $source->id,
            ]);

            $copy->syncFundingModes($source->fundingModes()->pluck('reference_items.id')->all());
            foreach ($source->modules()->get() as $module) {
                $copy->modules()->create($module->only(['position', 'title', 'duration_hours', 'modality_id', 'content']));
            }

            return $copy;
        });
    }
}
