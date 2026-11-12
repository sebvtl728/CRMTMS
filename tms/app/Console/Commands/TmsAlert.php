<?php

namespace App\Console\Commands;

use App\Modules\Core\Platform\AlertMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends an operational alert to the instance administrator. Used by the ops
 * scripts (backup, update). No tenant context: instance-level mailer.
 */
class TmsAlert extends Command
{
    protected $signature = 'tms:alert
        {message : Détail de l\'alerte}
        {--subject= : Objet du message}
        {--to= : Destinataire (défaut : TMS_ALERT_EMAIL)}';

    protected $description = 'Envoie une alerte d\'exploitation par e-mail à l\'administrateur de l\'instance';

    public function handle(): int
    {
        $to = trim((string) ($this->option('to') ?: config('tms.alert_email')));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Aucun destinataire valide : renseignez TMS_ALERT_EMAIL ou --to.');

            return self::FAILURE;
        }

        $subject = (string) ($this->option('subject') ?: '['.config('app.name').'] Alerte d\'exploitation');

        try {
            Mail::to($to)->send(new AlertMail($subject, (string) $this->argument('message')));
        } catch (Throwable $e) {
            // Never relay the raw transport error (it can carry credentials or hosts).
            report($e);
            $this->error('Alerte non envoyée : échec de la messagerie.');

            return self::FAILURE;
        }

        $this->info("Alerte envoyée à {$to}.");

        return self::SUCCESS;
    }
}
