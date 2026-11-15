<?php

namespace App\Modules\Crm\Quotes;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\BelongsToTenant;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A quote (devis) of an opportunity, written by QuoteWriter (number, lines
 * and totals together). The account is the opportunity's, copied at
 * creation; visibility follows the opportunity (CrmVisibility::quotes).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number <préfixe>-<AAAA>-<NNNN>, unique in the organisme
 * @property string $company_id
 * @property string $opportunity_id
 * @property string|null $organisation_id
 * @property string|null $person_id
 * @property string|null $contact_person_id recipient: an interlocuteur of the entreprise, or the particulier
 * @property QuoteStatus $status
 * @property Carbon $issued_on
 * @property Carbon $valid_until
 * @property Carbon|null $sent_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $accepted_on
 * @property Carbon|null $refused_on
 * @property string|null $refusal_reason
 * @property string|null $replaced_by_id
 * @property bool $vat_exempt copied from the company (frozen once sent)
 * @property string $total_ht numeric(12,2) as a string
 * @property string $total_vat
 * @property string $total_ttc
 * @property array<string, mixed>|null $recipient_snapshot name, address, e-mail frozen when sent
 * @property string|null $pdf_path
 * @property string|null $signed_path signed copy sent back by the customer (QuoteSignedCopy)
 * @property string|null $signed_name its original file name (personal)
 * @property Carbon|null $signed_at when it was attached
 * @property string|null $notes mentions particulières
 * @property array{kind?: string, to?: string, cc?: list<string>, subject?: string, body?: string}|null $email_message last e-mail (sending or reminder), kept to send it again
 * @property string|null $email_status pending|sent|failed (SendQuote)
 * @property Carbon|null $email_sent_at
 * @property string|null $email_error fixed French message, never the server's text
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
class Quote extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /**
     * Free text and copies that may describe a person: journaled without value.
     *
     * @var list<string>
     */
    protected array $auditPersonal = ['notes', 'refusal_reason', 'recipient_snapshot', 'signed_name'];

    /** @var list<string> */
    protected array $auditIgnore = ['created_by', 'updated_by', 'email_message', 'email_status', 'email_sent_at', 'email_error', 'signed_path'];

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'opportunity_id', 'organisation_id', 'person_id', 'contact_person_id',
        'issued_on', 'valid_until', 'notes',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'vat_exempt' => false,
        'total_ht' => '0.00',
        'total_vat' => '0.00',
        'total_ttc' => '0.00',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quote $quote): void {
            $quote->created_by ??= Auth::id();
            $quote->updated_by ??= Auth::id();
        });
        static::updating(function (Quote $quote): void {
            if (Auth::id() !== null) {
                $quote->updated_by = Auth::id();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'issued_on' => 'date',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
            'accepted_on' => 'date',
            'refused_on' => 'date',
            'vat_exempt' => 'boolean',
            'total_ht' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'recipient_snapshot' => 'array',
            'email_message' => 'array',
            'email_sent_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === QuoteStatus::Draft;
    }

    /** Sent or reminded: awaiting the customer's answer. */
    public function isAwaitingAnswer(): bool
    {
        return in_array($this->status, [QuoteStatus::Sent, QuoteStatus::Reminded], true);
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'contact_person_id')->withTrashed();
    }
}
