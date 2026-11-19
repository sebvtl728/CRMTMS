<?php

namespace App\Modules\Core\Companies;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A legal entity (société) of the organisme: issuer of quotes and invoices.
 * Exactly one is the default of a tenant (partial unique index).
 *
 * Identity fields (siret, rcs_city...) are nullable at database level because
 * tms:install / tms:tenant:create create a placeholder default company; the
 * administration form requires the SIRET.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property LegalForm $legal_form
 * @property string|null $scop_underlying_form sarl|sa|sas, only for SCOP / SCIC
 * @property string|null $capital_amount decimal(14,2)
 * @property bool $capital_variable
 * @property string|null $siret 14 digits
 * @property string|null $rcs_city
 * @property string|null $vat_number
 * @property VatRegime $vat_regime
 * @property string|null $nda_number numéro de déclaration d'activité
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $address_postal_code
 * @property string|null $address_city
 * @property string $address_country
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_default
 * @property string|null $logo_path private disk, set by CompanyBranding only
 * @property string|null $signature_path signature and stamp, private disk, set by CompanyBranding only
 * @property string $quote_prefix ^[A-Z0-9]{1,10}$
 * @property int $quote_validity_days 1 to 365
 * @property string|null $quote_terms
 * @property string|null $quote_footer
 * @property string $invoice_prefix ^[A-Z0-9]{1,10}$, numbering of the invoices (0.9)
 * @property string $credit_note_prefix ^[A-Z0-9]{1,10}$, other than invoice_prefix
 * @property int $payment_terms_days 0 to 60, due date of an invoice
 * @property string|null $invoice_footer mentions of the invoices (penalties, 40 € indemnity, bank details)
 * @property Carbon|null $deleted_at
 */
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'legal_form', 'scop_underlying_form', 'capital_amount', 'capital_variable',
        'siret', 'rcs_city', 'vat_number', 'vat_regime', 'nda_number',
        'address_line1', 'address_line2', 'address_postal_code', 'address_city', 'address_country',
        'email', 'phone', 'is_default',
        'quote_prefix', 'quote_validity_days', 'quote_terms', 'quote_footer',
        'invoice_prefix', 'credit_note_prefix', 'payment_terms_days', 'invoice_footer',
    ];

    /**
     * The logo has its own journal events (CompanyBranding: company.logo_changed /
     * company.logo_deleted), which also cover a replacement under the same name.
     *
     * @var list<string>
     */
    protected array $auditIgnore = ['logo_path', 'signature_path'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'legal_form' => 'autre',
        'vat_regime' => 'subject',
        'capital_variable' => false,
        'address_country' => 'FR',
        'is_default' => false,
        'quote_prefix' => 'DEV',
        'quote_validity_days' => 30,
        'invoice_prefix' => 'F',
        'credit_note_prefix' => 'AV',
        'payment_terms_days' => 30,
    ];

    protected static function booted(): void
    {
        // A prefix belongs to one company: a new company left with the default
        // prefix (placeholder, factory, form without it) takes the first free
        // suffix (« DEV2 »…). A prefix typed in the form is validated instead.
        static::creating(function (Company $company): void {
            if ($company->isDirty('quote_prefix')) {
                return;
            }
            $company->quote_prefix = static::freePrefix($company->quote_prefix);
        });

        // The brand shown in the application (Brand) follows the default company.
        static::saved(fn (Company $company) => Brand::forget($company->tenant_id));
        static::deleted(fn (Company $company) => Brand::forget($company->tenant_id));
        static::restored(fn (Company $company) => Brand::forget($company->tenant_id));

        // Becoming the default unsets the previous one. Runs inside save()'s
        // transaction, before the write, so the partial unique index holds.
        static::saving(function (Company $company): void {
            if (! $company->is_default || ! $company->isDirty('is_default')) {
                return;
            }

            static::query()
                ->where('is_default', true)
                ->when($company->exists, fn ($q) => $q->whereKeyNot($company->getKey()))
                ->get()
                ->each(fn (Company $other) => $other->update(['is_default' => false]));
        });
    }

    /**
     * $base when no company of the organisme uses it, else the first free
     * suffix (« DEV2 », « DEV3 »…; 10 characters at most). A deleted
     * company frees its prefix.
     */
    public static function freePrefix(string $base = 'DEV'): string
    {
        $taken = static::query()->pluck('quote_prefix')->all();
        $prefix = $base;
        for ($n = 2; in_array($prefix, $taken, true); $n++) {
            $prefix = substr($base, 0, 10 - strlen((string) $n)).$n;
        }

        return $prefix;
    }

    /**
     * Atomic: the default switch and the write succeed or fail together.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        return DB::transaction(fn () => parent::save($options));
    }

    protected function casts(): array
    {
        return [
            'legal_form' => LegalForm::class,
            'vat_regime' => VatRegime::class,
            'capital_variable' => 'boolean',
            'is_default' => 'boolean',
            'quote_validity_days' => 'integer',
            'payment_terms_days' => 'integer',
        ];
    }
}
