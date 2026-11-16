<?php

namespace App\Modules\Sessions\Enrolments;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A trainee (person of the CRM, read through Crm\Public only) on a training
 * session, for a training line of a dossier. Written by Enrolments (states,
 * waiting list) and PriceShares (price_share) only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $person_id
 * @property string $training_session_id
 * @property string $dossier_id
 * @property string $dossier_line_id
 * @property EnrolmentStatus $status
 * @property int|null $waitlist_position order of arrival on the waiting list
 * @property CarbonImmutable|null $entered_on
 * @property CarbonImmutable|null $left_on
 * @property string|null $abandon_reason_id
 * @property string|null $planned_hours numeric(6,2), the session's
 * @property string|null $price_share numeric(12,2), null: priced for the group, waitlisted or cancelled
 * @property string|null $bpf_category_id
 * @property string|null $adaptation_needs personal data
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class Enrolment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'enrolment';

    /**
     * Situation de handicap / besoins d'adaptation and free notes: journaled
     * without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['adaptation_needs', 'notes'];

    /** @var list<string> */
    protected $fillable = [
        'person_id', 'training_session_id', 'dossier_id', 'dossier_line_id', 'status', 'waitlist_position',
        'entered_on', 'left_on', 'abandon_reason_id', 'planned_hours', 'price_share', 'bpf_category_id',
        'adaptation_needs', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'enrolled',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnrolmentStatus::class,
            'waitlist_position' => 'integer',
            'entered_on' => 'immutable_date',
            'left_on' => 'immutable_date',
            'planned_hours' => 'decimal:2',
            'price_share' => 'decimal:2',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === EnrolmentStatus::Cancelled;
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Dossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<DossierLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(DossierLine::class, 'dossier_line_id');
    }
}
