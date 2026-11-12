<?php

namespace App\Console\Commands;

use App\Modules\Core\Access\Role;
use App\Modules\Core\Platform\TenantProvisioner;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Users\ActivationLink;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\InvitationNotification;
use App\Modules\Core\Users\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/**
 * First installation: tenant, roles, administrator and activation link.
 * Creates the placeholder default company too (see TenantProvisioner).
 */
class TmsInstall extends Command
{
    protected $signature = 'tms:install
        {--tenant-name= : Nom de l\'organisme}
        {--admin-email= : E-mail de l\'administrateur}
        {--admin-name= : Nom de l\'administrateur}
        {--slug= : Identifiant de l\'organisme (TMS_DEFAULT_TENANT en mode single ; défaut : dérivé du nom)}
        {--domain= : Nom de domaine de l\'organisme (mode multi)}
        {--vat-exempt : Société par défaut exonérée de TVA}
        {--seed-cap : Charge les référentiels par défaut de CAP FORMATION}';

    protected $description = 'Initialise l\'application : organisme, administrateur et lien d\'activation';

    public function handle(TenantProvisioner $provisioner): int
    {
        if (Tenant::query()->exists()) {
            $this->error('Installation déjà initialisée.');

            return self::FAILURE;
        }

        $name = (string) $this->option('tenant-name');
        $email = mb_strtolower(trim((string) $this->option('admin-email')));
        $adminName = (string) $this->option('admin-name');

        if ($name === '' || $adminName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Options requises : --tenant-name, --admin-name et --admin-email (adresse valide).');

            return self::FAILURE;
        }

        $slug = (string) $this->option('slug');
        if ($slug !== '' && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->error('Identifiant (slug) invalide : lettres minuscules, chiffres et tirets uniquement.');

            return self::FAILURE;
        }

        $domain = (string) $this->option('domain');

        // Tenant, domain, roles, admin and invitation: all or nothing.
        try {
            [$tenant, $invitation] = $provisioner->create(
                $name,
                $slug !== '' ? $slug : null,
                $domain !== '' ? [$domain] : [],
                function (Tenant $tenant) use ($email, $adminName): Invitation {
                    $this->createAdmin($email, $adminName);
                    if ($this->option('seed-cap')) {
                        CapFormationReferenceSeeder::run($tenant);
                    }

                    return Invitation::issue($email, $adminName, Role::AdminOrganisme);
                },
                (bool) $this->option('vat-exempt'),
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $url = ActivationLink::url((string) $invitation->plainToken, $tenant, $domain);

        $this->info("Organisme « {$tenant->name} » installé.");
        $this->sendMail($url, $email, $adminName, $tenant->name);
        $this->line('Lien d\'activation de l\'administrateur (valable '.Invitation::VALIDITY_HOURS.' h, usage unique) :');
        $this->line($url);

        return self::SUCCESS;
    }

    /**
     * Administrator without password: it is chosen through the activation link.
     * Must run inside the tenant context.
     */
    private function createAdmin(string $email, string $name): User
    {
        $user = new User(['name' => $name, 'email' => $email]);
        $user->save();
        $user->assignPrimaryRole(Role::AdminOrganisme);

        return $user;
    }

    private function sendMail(string $url, string $email, string $name, string $organisation): void
    {
        if (in_array(config('mail.default'), [null, 'log'], true)) {
            $this->warn('Messagerie non configurée : e-mail non envoyé.');

            return;
        }

        try {
            Notification::route('mail', $email)->notify(new InvitationNotification($url, $name, $organisation));
            $this->info("E-mail d'activation envoyé à {$email}.");
        } catch (Throwable $e) {
            $this->warn('E-mail non envoyé : '.$e->getMessage());
        }
    }
}
