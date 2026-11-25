<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The monthly link of the apprentice or the tutor (only the SHA-256 of the
 * token is kept; the token itself travels in the encrypted payload of the
 * mail job), with its e-mailed code (HMAC). One open link per candidature,
 * month and signer. Not audited (it carries an e-mail address).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $candidature_id
 * @property CarbonImmutable $month
 * @property string $signer
 * @property string $person_id
 * @property string|null $email
 * @property string|null $signer_name
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $sent_at
 * @property string $email_status
 * @property CarbonImmutable|null $closed_at
 * @property string|null $code_hash
 * @property CarbonImmutable|null $code_expires_at
 * @property int $code_attempts
 * @property CarbonImmutable|null $code_sent_at
 * @property string|null $created_by
 */
class BookletLink extends Model
{
    use BelongsToTenant, HasUuids;

    public const APPRENTICE = 'apprentice';

    public const TUTOR = 'tutor';

    public const SIGNERS = [self::APPRENTICE, self::TUTOR];

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'code_expires_at' => 'immutable_datetime',
            'code_sent_at' => 'immutable_datetime',
            'code_attempts' => 'integer',
        ];
    }
}
