<?php

namespace App\Modules\Questionnaires\Surveys;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A questionnaire template of the organisme: a type (hot / cold), a public
 * (trainee / client) and its questions (SurveyContent). At most one default
 * per type and public; a campaign copies the questions, so a template stays
 * editable after use.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $kind hot | cold
 * @property string $audience trainee | client
 * @property list<array<string, mixed>> $questions
 * @property bool $active
 * @property bool $is_default
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class SurveyTemplate extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const MORPH_ALIAS = 'survey_template';

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by'];

    /** @var list<string> */
    protected $fillable = ['name', 'kind', 'audience', 'questions', 'active', 'is_default', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['questions' => 'array', 'active' => 'boolean', 'is_default' => 'boolean'];
    }
}
