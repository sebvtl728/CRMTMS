<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One e-mailing of an invoice or a credit note (« Envoyer », « Renvoyer »):
 * the recipient, subject and message typed in the window (kept here because
 * a validated invoice never changes), and its outcome. Written by
 * InvoiceSender; SendInvoice sets pending → sent | failed. The address and
 * the texts are personal data: never journaled by value (audit entries carry
 * the sending id only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $email
 * @property string $subject
 * @property string $body
 * @property string $status pending|sent|failed
 * @property string|null $error_code
 * @property string|null $error fixed French reason, never the server's text
 * @property string|null $sent_by
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 */
class InvoiceSending extends Model
{
    use BelongsToTenant, HasUuids;

    public const MORPH_ALIAS = 'invoice_sending';

    /** @var list<string> */
    protected $fillable = ['invoice_id', 'email', 'subject', 'body', 'status', 'sent_by'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
