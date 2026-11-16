<?php

namespace App\Modules\Crm\Brochures;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Core\Users\User;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One e-mail « Envoyer une plaquette » sent from a fiche (or one of its
 * opportunities): who it went to, the address, subject and message as sent
 * (personal data: journaled without value, cleared by the anonymisation),
 * the brochure versions joined (brochure_sending_versions) and the delivery
 * status, which never holds the mail server's own text.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $organisation_id
 * @property string|null $person_id
 * @property string|null $opportunity_id
 * @property string $recipient_person_id
 * @property string|null $recipient_email
 * @property string|null $subject
 * @property string|null $message
 * @property string $email_status pending|sent|failed
 * @property string|null $email_error
 * @property Carbon|null $sent_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class BrochureSending extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const STATUS_LABELS = [self::PENDING => 'En attente', self::SENT => 'Envoyée', self::FAILED => 'Échec'];

    /** @var list<string> */
    protected array $auditPersonal = ['recipient_email', 'subject', 'message'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by'];

    /** @var list<string> */
    protected $fillable = [
        'organisation_id', 'person_id', 'opportunity_id', 'recipient_person_id', 'recipient_email', 'subject', 'message',
        'email_status', 'email_error', 'sent_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'recipient_person_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The version ids joined, in the order they were chosen.
     *
     * @return list<string>
     */
    public function versionIds(): array
    {
        return DB::table('brochure_sending_versions')->where('brochure_sending_id', $this->id)->orderBy('position')->pluck('brochure_version_id')->all();
    }
}
