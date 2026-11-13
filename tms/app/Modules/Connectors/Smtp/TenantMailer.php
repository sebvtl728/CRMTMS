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
     * True when a message could end up delivered nowhere: no active SMTP
     * configuration and the instance mailer only logs or keeps messages in
     * memory (MAIL_MAILER=log or array), or a failover chain ending in such a
     * mailer (a failed send is silently swallowed) or a round-robin including
     * one (some sends vanish).
     */
    public static function deliversNowhere(?Company $company = null): bool
    {
        return self::activeConfig($company) === null
            && self::mailerDeliversNowhere((string) config('mail.default'));
    }

    private static function mailerDeliversNowhere(string $name, int $depth = 0): bool
    {
        if (in_array($name, ['', 'log', 'array'], true)) {
            return true;
        }

        $config = config("mail.mailers.{$name}");
        $transport = is_array($config) ? ($config['transport'] ?? null) : null;

        if ($depth > 5 || ! in_array($transport, ['failover', 'roundrobin'], true)) {
            return in_array($transport, ['log', 'array'], true);
        }

        /** @var list<string> $members */
        $members = array_values(array_filter((array) ($config['mailers'] ?? []), 'is_string'));
        if ($members === []) {
            return true;
        }

        return $transport === 'failover'
            ? self::mailerDeliversNowhere(end($members), $depth + 1)
            : collect($members)->contains(fn (string $m) => self::mailerDeliversNowhere($m, $depth + 1));
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
