<?php

namespace App\Modules\Core\References;

use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Default values of CAP FORMATION (first customer), offered when provisioning
 * an organisme (--seed-cap) and editable afterwards in the administration.
 *
 * Idempotent: an item is matched on (list, code); existing items (edited,
 * deactivated or deleted by the organisme) are never touched or recreated.
 */
final class CapFormationReferenceSeeder
{
    /**
     * Pipeline stages: label => [probability, outcome]. Won and lost are
     * system stages (closing outcomes the pipeline relies on).
     */
    private const STAGES = [
        'Nouveau' => [10, null],
        'Contacté' => [20, null],
        'Étude' => [40, null],
        'Proposition' => [60, null],
        'Négociation' => [80, null],
        'Gagné' => [100, 'won'],
        'Perdu' => [0, 'lost'],
    ];

    /**
     * Action types shown in the CRM planning (meta.planning), by code.
     *
     * @var array<string, string>
     */
    private const PLANNING = ['prise-de-rendez-vous' => 'meeting', 'rendez-vous-physique' => 'meeting', 'appel' => 'call'];

    /** @var array<string, list<string>> */
    private const VALUES = [
        'das' => ['Métiers supports', 'Compétences transversales', 'Hôtellerie-restauration', 'Mobilités professionnelles'],
        'action_type' => ['Prise de rendez-vous', 'Rappel', 'Envoi de nouveauté', 'Suivi', 'Rendez-vous physique', 'Envoi de devis', 'Appel', 'E-mail', 'Note'],
        'action_result' => ['Réussi', 'Échec', 'En attente', 'À replanifier'],
        'contact_origin' => ['Salon', 'Recommandation', 'Site web', 'Appel à froid', 'Réseau', 'Réseaux sociaux', 'Appel entrant'],
        'priority' => ['Urgent', 'Élevé', 'Moyen', 'Bas'],
        'establishment_type' => ['Restaurant traditionnel', 'Restauration rapide', 'Restauration collective', 'Hôtel-restaurant', 'Hôtel', 'Autre'],
        'company_size' => ['0 salarié', '1-9', '10-49', '50-249', '250 et plus'],
        'funding_mode' => ['CPF', 'OPCO', 'France Travail', 'Entreprise', 'Personnel', 'Région', 'Autre'],
        'professional_situation' => ['Salarié', "Demandeur d'emploi", 'Indépendant', 'En reconversion', 'Étudiant', 'Autre'],
        'decision_role' => ['Décideur', 'Prescripteur', 'Utilisateur', 'Financeur', 'Autre'],
        'loss_reason' => ['Prix', 'Financement refusé', 'Concurrent', 'Pas de besoin', 'Calendrier', 'Sans réponse', 'Autre'],
        'sector' => ['Hôtellerie-restauration', 'Commerce', 'Industrie', 'Services', 'Santé-social', 'Collectivité', 'Autre'],
    ];

    public static function run(Tenant $tenant): void
    {
        app(TenantContext::class)->run($tenant, function (): void {
            foreach (self::VALUES as $list => $labels) {
                foreach ($labels as $i => $label) {
                    $code = Str::slug($label);
                    $planning = $list === ReferenceList::ActionType->value ? (self::PLANNING[$code] ?? null) : null;
                    self::ensure(ReferenceList::from($list), $code, $label, $i + 1, $planning === null ? null : ['planning' => $planning]);
                }
            }

            $position = 1;
            foreach (self::STAGES as $label => [$probability, $outcome]) {
                self::ensure(
                    ReferenceList::PipelineStage,
                    Str::slug($label),
                    $label,
                    $position++,
                    ['probability' => $probability, 'outcome' => $outcome],
                    $outcome !== null,
                );
            }

            // Organisation legal forms mirror the LegalForm enum labels.
            foreach (LegalForm::cases() as $i => $form) {
                self::ensure(ReferenceList::LegalFormOrg, Str::slug($form->label()), $form->label(), $i + 1);
            }
        });
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function ensure(ReferenceList $list, string $code, string $label, int $position, ?array $meta = null, bool $system = false): void
    {
        // Matched on the ORIGINAL code, deleted rows included: a value the
        // organisme deleted is not resurrected by a re-run.
        $exists = ReferenceItem::withTrashed()->where('list', $list)->where('code', $code)->exists();
        if ($exists) {
            return;
        }

        ReferenceItem::create([
            'list' => $list,
            'code' => $code,
            'label' => $label,
            'position' => $position,
            'is_system' => $system,
            'meta' => $meta,
        ]);
    }
}
