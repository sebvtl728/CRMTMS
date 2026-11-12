<?php

namespace App\Modules\Connectors\Smtp;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Core\Companies\Company;
use Illuminate\Contracts\Mail\Mailer as MailerContract;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * The mailer of an organisme: built from its active SMTP configuration,
 * the company's own first, then the organisme-wide one, and finally the
 * instance default mailer (MAIL_* in .env).
 */
final class TenantMailer
{
    public static function for(?Company $company = null): MailerContract
    {
        $config = self::activeConfig($company);

        if ($config === null) {
            return Mail::mailer();
        }

        $transport = app(SmtpTransportFactory::class)->make($config->settings, $config->secrets ?? []);

        $mailer = new Mailer('tenant-smtp', app('view'), $transport, app('events'));
        $mailer->alwaysFrom((string) $config->settings['from_address'], ($config->settings['from_name'] ?? '') ?: null);

        return $mailer;
    }

    /**
     * True when nothing would actually be delivered: no active SMTP
     * configuration and the instance mailer only logs or keeps messages in
     * memory (MAIL_MAILER=log or array).
     */
    public static function deliversNowhere(?Company $company = null): bool
    {
        return self::activeConfig($company) === null
            && in_array(config('mail.default'), [null, '', 'log', 'array'], true);
    }

    private static function activeConfig(?Company $company): ?ConnectorConfig
    {
        $candidates = ConnectorConfig::query()
            ->where('connector_key', SmtpConnector::key())
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('company_id')->when($company, fn ($q) => $q->orWhere('company_id', $company->id)))
            ->get();

        return ($company === null ? null : $candidates->firstWhere('company_id', $company->id))
            ?? $candidates->firstWhere('company_id', null);
    }
}
