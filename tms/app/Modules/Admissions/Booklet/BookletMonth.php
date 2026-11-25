<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Audit\Auditable;
use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One monthly page of the apprenticeship booklet: « company » (observations
 * of the tutor) or « centre » (observations of the trainer), with the weeks
 * typed by the apprentice, the online or paper signatures and the visa of
 * the trainer. Created at the first sending or typing.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property CarbonImmutable $month
 * @property string $place
 * @property array<string, string|null>|null $apprentice_entries
 * @property array<string, string|null>|null $observations
 * @property CarbonImmutable|null $apprentice_signed_at
 * @property string|null $apprentice_signature_path
 * @property string|null $apprentice_signer_name
 * @property bool $apprentice_paper
 * @property string|null $apprentice_ip
 * @property string|null $apprentice_agent
 * @property string|null $apprentice_link_id
 * @property CarbonImmutable|null $tutor_signed_at
 * @property string|null $tutor_signature_path
 * @property string|null $tutor_signer_name
 * @property bool $tutor_paper
 * @property string|null $tutor_ip
 * @property string|null $tutor_agent
 * @property string|null $tutor_link_id
 * @property string|null $visa_by
 * @property CarbonImmutable|null $visa_at
 */
class BookletMonth extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'booklet_month';

    public const COMPANY = 'company';

    public const CENTRE = 'centre';

    public const PLACES = [self::COMPANY, self::CENTRE];

    /** Keys and French labels of the weeks of a page. */
    public const WEEKS = ['w1' => 'Semaine 1', 'w2' => 'Semaine 2', 'w3' => 'Semaine 3', 'w45' => 'Semaine 4-5'];

    public const MAX_ENTRY = 2000;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @var list<string> */
    protected array $auditPersonal = ['apprentice_entries', 'observations', 'apprentice_signer_name', 'tutor_signer_name', 'apprentice_ip', 'apprentice_agent', 'tutor_ip', 'tutor_agent'];

    /** @var list<string> */
    protected array $auditIgnore = ['apprentice_signature_path', 'tutor_signature_path', 'apprentice_link_id', 'tutor_link_id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'apprentice_entries' => 'array',
            'observations' => 'array',
            'apprentice_signed_at' => 'immutable_datetime',
            'tutor_signed_at' => 'immutable_datetime',
            'visa_at' => 'immutable_datetime',
            'apprentice_paper' => 'boolean',
            'tutor_paper' => 'boolean',
        ];
    }

    public function signedBy(string $signer): bool
    {
        return $this->{$signer.'_signed_at'} !== null || $this->{$signer.'_paper'};
    }
}
