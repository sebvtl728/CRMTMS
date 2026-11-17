<?php

namespace App\Modules\Sessions\Trainers;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A DAS (reference item) a trainer is attached to, written by Trainers only.
 * Nothing personal: the ids of the trainer and of the DAS.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $trainer_id
 * @property string $das_id
 */
class TrainerDas extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'trainer_das';

    protected $table = 'trainer_das';

    /** @var list<string> */
    protected $fillable = ['trainer_id', 'das_id'];
}
