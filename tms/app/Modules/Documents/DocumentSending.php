<?php

namespace App\Modules\Documents;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One e-mail of a document: what was typed in the window (personal: never
 * journalled, erased by the anonymisation) and how it went.
 *
 * @property string $id
 * @property string $document_id
 * @property string|null $email
 * @property string|null $subject
 * @property string|null $body
 * @property string $status pending|sent|failed
 * @property string|null $error
 * @property string|null $error_code
 * @property CarbonImmutable|null $sent_at
 * @property string|null $sent_by
 * @property CarbonImmutable|null $created_at
 */
class DocumentSending extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['pending' => 'En cours', 'sent' => 'Envoyé', 'failed' => 'Échec'];

    /** @var list<string> */
    protected $fillable = ['document_id', 'email', 'subject', 'body', 'status', 'sent_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
