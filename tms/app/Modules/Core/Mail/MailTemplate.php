<?php

namespace App\Modules\Core\Mail;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * An e-mail template changed by the organisme (no row: the default of the
 * code, MailTemplateRegistry). Plain text with {variables}, never code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $subject
 * @property string $body
 * @property string|null $updated_by
 */
class MailTemplate extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $table = 'mail_templates';

    /** @var list<string> */
    protected $fillable = ['key', 'subject', 'body'];

    /** @var list<string> */
    protected array $auditIgnore = ['updated_by'];

    protected static function booted(): void
    {
        static::saving(function (MailTemplate $template): void {
            $template->updated_by = Auth::id() ?? $template->updated_by;
        });
    }
}
