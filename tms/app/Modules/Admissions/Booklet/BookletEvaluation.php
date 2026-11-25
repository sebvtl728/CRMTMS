<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An A / ECA / NA mark of a skill of the competence grid, on the centre side
 * (a user) or the company side (the tutor, by his link). The skill is keyed
 * by BookletGrid::skillKey (the grid of the training has no ids).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property string $skill_key
 * @property string $side
 * @property string $level
 * @property string|null $updated_by
 */
class BookletEvaluation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'booklet_evaluation';

    public const LEVELS = ['A', 'ECA', 'NA'];

    public const SIDES = ['centre', 'company'];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];
}
