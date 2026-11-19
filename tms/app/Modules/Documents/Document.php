<?php

namespace App\Modules\Documents;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A training document issued from a dossier: frozen once issued (archived
 * PDF + SHA-256, PostgreSQL trigger documents_immutable); only its life
 * changes (status, sending, cancellation). A mistake is corrected by
 * cancelling it and issuing a new one.
 *
 * @property string $id
 * @property string $type DocumentType value
 * @property string $dossier_id
 * @property string|null $enrolment_id
 * @property string $company_id
 * @property string $number DOC-<année>-<NNNNN>
 * @property array<string, mixed> $recipient_snapshot name, address lines, e-mail, attention
 * @property string $pdf_path
 * @property string $pdf_sha256
 * @property DocumentStatus $status
 * @property CarbonImmutable $issued_at
 * @property string|null $issued_by
 * @property CarbonImmutable|null $sent_at
 * @property string|null $email_status
 * @property string|null $email_error_code
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property string|null $cancel_reason
 */
class Document extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'document';

    /** @var list<string> */
    protected array $auditPersonal = ['recipient_snapshot'];

    /** @var list<string> */
    protected array $auditIgnore = ['email_status', 'email_error_code', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'type', 'dossier_id', 'enrolment_id', 'company_id', 'number', 'recipient_snapshot', 'pdf_path', 'pdf_sha256', 'status',
        'issued_at', 'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'recipient_snapshot' => 'array',
            'status' => DocumentStatus::class,
            'issued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === DocumentStatus::Cancelled;
    }
}
