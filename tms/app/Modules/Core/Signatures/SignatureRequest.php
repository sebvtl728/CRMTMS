<?php

namespace App\Modules\Core\Signatures;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An online signature of a PDF (Signatures).
 *
 * @property string $id
 * @property string $subject_type morph alias of what is signed (document, quote…)
 * @property string $subject_id
 * @property string $title « Convention de formation DOC-2026-00001 »
 * @property string $pdf_path the PDF to sign (never overwritten)
 * @property string $pdf_sha256
 * @property string|null $signer_name
 * @property string|null $signer_email
 * @property string|null $signer_person_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property string $status pending|signed|cancelled
 * @property string|null $code_hash
 * @property CarbonImmutable|null $code_expires_at
 * @property int $code_attempts
 * @property CarbonImmutable|null $code_sent_at
 * @property CarbonImmutable|null $signed_at
 * @property string|null $signed_ip
 * @property string|null $signed_user_agent
 * @property string|null $signed_pdf_path
 * @property string|null $signed_pdf_sha256
 * @property string|null $created_by
 */
class SignatureRequest extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'signature_request';

    /** @var list<string> */
    protected array $auditPersonal = ['signer_name', 'signer_email', 'signed_ip', 'signed_user_agent'];

    /** @var list<string> */
    protected array $auditIgnore = ['token_hash', 'code_hash', 'code_expires_at', 'code_attempts', 'code_sent_at', 'updated_at'];

    /** @var list<string> */
    protected $fillable = ['subject_type', 'subject_id', 'title', 'pdf_path', 'pdf_sha256', 'signer_name', 'signer_email', 'signer_person_id', 'token_hash', 'expires_at', 'status', 'created_by'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'code_expires_at' => 'immutable_datetime',
            'code_sent_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'code_attempts' => 'integer',
        ];
    }
}
