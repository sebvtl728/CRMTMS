<?php

namespace App\Modules\Crm\Interests;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A « formation d'intérêt » of an entreprise or a person (exactly one of
 * organisation_id / person_id), written by FicheTrainings. The training is
 * read through Catalogue\Trainings only: its id is all this table holds.
 * No personal data (ids and the author).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $organisation_id
 * @property string|null $person_id
 * @property string $training_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class FicheTraining extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'fiche_trainings';

    /** @var list<string> */
    protected $fillable = ['organisation_id', 'person_id', 'training_id', 'created_by'];

    /**
     * Rows are journaled through their fiche (one crm.*_trainings_changed
     * event per change, FicheTrainings), not one by one.
     *
     * @var list<string>
     */
    protected array $auditIgnore = ['id', 'tenant_id', 'organisation_id', 'person_id', 'training_id', 'created_by'];
}
