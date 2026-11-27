<?php

namespace App\Modules\Core\CustomFields;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A custom field of the organisme (table custom_field_definitions), on the
 * fiches of one entity registered in CustomFieldEntities (« candidature »,
 * « training »…). A used field is hidden, never deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity
 * @property string $label
 * @property CustomFieldType $type
 * @property list<string>|null $options choices of a « choice » field
 * @property bool $required_for_admission « obligatoire »: for a candidature, before the admission; for a training, to save it
 * @property int $position
 * @property Carbon|null $hidden_at
 */
class CustomFieldDefinition extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'custom_field_definition';

    /** @var list<string> */
    protected $fillable = ['entity', 'label', 'type', 'options', 'required_for_admission', 'position', 'hidden_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'required_for_admission' => false,
        'position' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
            'required_for_admission' => 'boolean',
            'position' => 'integer',
            'hidden_at' => 'datetime',
        ];
    }
}
