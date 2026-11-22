<?php

namespace App\Modules\Core\Mail;

use App\Modules\Core\Automation\DailyAutomations;
use App\Modules\Core\Signatures\SignatureMailTemplates;
use App\Modules\Core\Signatures\SignatureReminders;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Core's e-mail templates: the invitation (editable) and the security and
 * operation e-mails (« modèles système », read only).
 */
class MailServiceProvider extends ServiceProvider
{
    public const INVITATION = 'users.invitation';

    public function register(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::INVITATION,
            label: "Invitation d'un utilisateur",
            description: "Envoyé à une personne invitée. Le bouton d'activation, la durée de validité du lien et la mention « Si vous n'attendiez pas cette invitation » sont toujours ajoutés après votre texte. La première ligne sert de formule d'appel.",
            subject: 'Activez votre compte {application}',
            body: "Bonjour {nom_utilisateur},\n\nVous êtes invité à rejoindre l'espace {organisme}.\n\nChoisissez votre mot de passe pour activer votre compte.",
            variables: [
                'prenom_utilisateur' => ['prénom de la personne invitée', 'Léa'],
                'nom_utilisateur' => ['prénom et nom de la personne invitée', 'Léa Martin'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
                'application' => ["nom de l'application", (string) config('app.name')],
            ],
        ));

        foreach ([
            ['auth.password_reset', 'Réinitialisation du mot de passe', 'Lien de réinitialisation du mot de passe, valable une heure.'],
            ['security.two_factor', 'Double authentification et alertes de sécurité', 'Codes de récupération, désactivation de la double authentification, alertes de connexion.'],
            ['ops.alert', "Alertes d'exploitation", "Alertes envoyées à l'exploitant (sauvegardes, files d'attente, espace disque)."],
        ] as [$key, $label, $description]) {
            MailTemplateRegistry::register(new MailTemplateDefinition(
                key: $key,
                label: $label,
                description: $description.' Modèle système : il ne se modifie pas, pour votre sécurité.',
                subject: $label,
                body: $description,
                editable: false,
            ));
        }
    }

    public function boot(): void
    {
        SignatureMailTemplates::register();
        DailyAutomations::register('signature_reminders', fn (CarbonImmutable $today): int => app(SignatureReminders::class)->run(CarbonImmutable::now()));
        // « Exécuter maintenant » (Administration › Automatismes): 6 a minute per administrator.
        RateLimiter::for('automation-run', fn (Request $request) => Limit::perMinute(6)->by('automation-run:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Saves, resets and previews: 60 a minute per administrator.
        RateLimiter::for('mail-templates-write', fn (Request $request) => Limit::perMinute(60)->by('mail-templates-write:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // « Envoyer un e-mail de test »: 10 a minute per administrator.
        RateLimiter::for('mail-template-test', fn (Request $request) => Limit::perMinute(10)->by('mail-template-test:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
