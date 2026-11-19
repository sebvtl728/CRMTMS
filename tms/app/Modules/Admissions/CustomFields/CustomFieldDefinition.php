<?php

namespace App\Modules\Admissions\CustomFields;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A custom field of the organisme (table custom_field_definitions). Only the
 * entity « candidature » exists today; the column is there for the other
 * fiches. A used field is hidden, never deleted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $entity
 * @property string $label
 * @property CustomFieldType $type
 * @property list<string>|null $options choices of a « choice » field
 * @property bool $required_for_admission
 * @property int $position
 * @property Carbon|null $hidden_at
 */
class CustomFieldDefinition extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'custom_field_definition';

    public const CANDIDATURE = 'candidature';

    /** @var list<string> */
    protected $fillable = ['entity', 'label', 'type', 'options', 'required_for_admission', 'position', 'hidden_at'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'entity' => self::CANDIDATURE,
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
