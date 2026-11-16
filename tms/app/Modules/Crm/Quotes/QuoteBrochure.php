<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A brochure version joined to the e-mail of a quote at sending (the file
 * itself is read through Catalogue\Brochures only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $quote_id
 * @property string $brochure_version_id
 * @property int $position from 0
 */
class QuoteBrochure extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'quote_brochures';

    /** @var list<string> */
    protected $fillable = ['quote_id', 'brochure_version_id', 'position'];
}
