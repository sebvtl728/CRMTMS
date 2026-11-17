<?php

namespace App\Modules\Finance\Invoices;

use RuntimeException;

/**
 * An invoice e-mail that did not leave, as reported by SendInvoice: a fixed
 * French reason and the SMTP reply code at most. Thrown instead of the
 * transport's exception, whose text may quote the recipient's address or
 * the SMTP account (the worker logs it and keeps it in failed_jobs).
 */
final class InvoiceMailFailed extends RuntimeException {}
