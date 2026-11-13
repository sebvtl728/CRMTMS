<?php

namespace App\Modules\Crm\Import;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Saved import settings, reusable for the next file of the same origin
 * (e.g. the future « Formevol » profile).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property ImportTarget $target
 * @property array<string, string> $field_map
 * @property array<string, array<string, string>> $value_map
 * @property array<string, mixed> $options strategy, source_system, delimiter, encoding
 * @property string|null $created_by
 */
class ImportProfile extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'target', 'field_map', 'value_map', 'options'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    protected static function booted(): void
    {
        static::creating(function (ImportProfile $profile): void {
            $profile->created_by ??= Auth::id();
        });
    }

    protected function casts(): array
    {
        return [
            'target' => ImportTarget::class,
            'field_map' => 'array',
            'value_map' => 'array',
            'options' => 'array',
        ];
    }

    public function fieldMap(): FieldMap
    {
        return FieldMap::fromArray($this->target, $this->field_map);
    }

    public function valueMapper(): ValueMapper
    {
        return ValueMapper::fromArray($this->value_map);
    }
}
