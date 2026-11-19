<?php

namespace App\Modules\Documents\Texts;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One block of a training document as the organisme (company_id null) or a
 * company wrote it; without a row the default text applies.
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $type
 * @property string $block
 * @property string $body
 * @property string|null $updated_by
 */
class DocumentText extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'document_text';

    /** @var list<string> */
    protected $fillable = ['company_id', 'type', 'block', 'body', 'updated_by'];
}
