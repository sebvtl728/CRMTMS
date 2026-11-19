<?php

namespace App\Modules\Finance;

use App\Modules\Core\Access\Role;

/**
 * Permissions of the Finance module (0.9) and their default matrix,
 * registered into the Core catalogue by FinanceServiceProvider. The three
 * invoice rights carry a scope (own / das / all) read on the dossier of the invoice,
 * as sessions.dossiers.* do (« Ses fiches »: responsible for the dossier or
 * created it; « Ses DAS »: the DAS of the dossier).
 *
 * Defaults: reading and preparing for the roles that manage the dossiers
 * (sessions.dossiers.update by default), validating for the administrator
 * and the direction; all on the whole organisme. Payments (0.9.1): the
 * administrator and the direction, without scope.
 */
final class Permissions
{
    /** Read the invoices, credit notes and billing settings of the dossiers. */
    public const VIEW = 'finance.invoices.view';

    /** Billing settings of a dossier, drafts, credit notes in draft, sending. */
    public const MANAGE = 'finance.invoices.manage';

    /** Validate an invoice or a credit note (definitive number, frozen). */
    public const VALIDATE = 'finance.invoices.validate';

    /**
     * Record, change and delete payments and their allocations (0.9.1):
     * unscoped, a payment settles invoices of several dossiers.
     */
    public const PAYMENTS = 'finance.payments.manage';

    /** Theme on the « Rôles et droits » screen. */
    public const GROUP = 'Factures';

    /**
     * @return array<string, array{0: string, 1: string}> permission => [French label, explanation]
     */
    public static function labels(): array
    {
        return [
            self::VIEW => ['Consulter les factures', 'Voir les factures et avoirs des dossiers, leur rythme de facturation et ce qui reste à facturer.'],
            self::MANAGE => ['Préparer les factures', 'Régler le rythme de facturation d\'un dossier, préparer et modifier les brouillons de factures et d\'avoirs, envoyer les factures.'],
            self::VALIDATE => ['Valider les factures', 'Valider une facture ou un avoir : numéro définitif, contenu figé et archivé. Une facture validée ne se modifie plus.'],
            self::PAYMENTS => ['Enregistrer les règlements', 'Saisir, modifier et supprimer les règlements reçus et leur répartition sur les factures, sur tout l\'organisme.'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::VIEW, self::MANAGE, self::VALIDATE, self::PAYMENTS];
    }

    /**
     * The rights read on the dossier of the invoice (own / das / all).
     *
     * @return list<string>
     */
    public static function scoped(): array
    {
        return [self::VIEW, self::MANAGE, self::VALIDATE];
    }

    /**
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::AdminOrganisme, Role::Direction => self::all(),
            Role::Commercial, Role::ChargeRelations, Role::Assistant => [self::VIEW, self::MANAGE],
            Role::ResponsableDas, Role::Formateur => [],
        };
    }
}
