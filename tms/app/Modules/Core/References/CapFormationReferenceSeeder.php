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

    /** Abandon reasons that are a force majeure (never a dédit), by code. */
    private const FORCE_MAJEURE = ['maladie', 'accident'];

    /** Funding types funding a new dossier by default (meta.default_for), by code. */
    private const DEFAULT_FOR = ['entreprise' => 'organisation', 'personnel' => 'person'];

    /** Funding types billed outside the TMS (meta.external_billing, 0.9: the CPF, through EDOF), by code. */
    private const EXTERNAL_BILLING = ['cpf'];

    /** The stage a sent quote brings its opportunity to (meta.quote_stage). */
    private const QUOTE_STAGE = 'Proposition';

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
        'funding_type' => ['OPCO', 'Entreprise', 'CPF', 'France Travail', 'Région', 'Personnel'],
        'abandon_reason' => ['Démission', 'Maladie', 'Accident', 'Licenciement', 'Convenance personnelle', 'Autre'],
        'cancel_reason' => ['Effectif insuffisant', 'Demande du client', 'Report', 'Formateur indisponible', 'Financement refusé', 'Autre'],
        'bpf_category' => ["Salariés d'employeurs privés", "Demandeurs d'emploi", 'Particuliers', 'Autres'],
        'candidature_refusal_reason' => ['Niveau insuffisant', 'Projet non adapté', 'Pas d\'entreprise d\'accueil', 'Places complètes', 'Autre'],
        'education_level' => ['Sans diplôme', 'Niveau 3 (CAP, BEP)', 'Niveau 4 (baccalauréat)', 'Niveau 5 (bac +2)', 'Niveau 6 (bac +3, bac +4)', 'Niveau 7 (bac +5)', 'Niveau 8 (doctorat)'],
        'training_modality' => ['Présentiel', 'Distanciel (classe virtuelle)', 'FOAD / e-learning', 'Mixte'],
        'sector' => ['Hôtellerie-restauration', 'Commerce', 'Industrie', 'Services', 'Santé-social', 'Collectivité', 'Autre'],
    ];

    public static function run(Tenant $tenant): void
    {
        app(TenantContext::class)->run($tenant, function (): void {
            foreach (self::VALUES as $list => $labels) {
                foreach ($labels as $i => $label) {
                    $code = Str::slug($label);
                    $planning = $list === ReferenceList::ActionType->value ? (self::PLANNING[$code] ?? null) : null;
                    $meta = $planning === null ? null : ['planning' => $planning];
                    if ($list === ReferenceList::AbandonReason->value && in_array($code, self::FORCE_MAJEURE, true)) {
                        $meta = ['force_majeure' => true];
                    }
                    if ($list === ReferenceList::FundingType->value && isset(self::DEFAULT_FOR[$code])) {
                        $meta = ['default_for' => self::DEFAULT_FOR[$code]];
                    }
                    if ($list === ReferenceList::FundingType->value && in_array($code, self::EXTERNAL_BILLING, true)) {
                        $meta = [...$meta ?? [], 'external_billing' => true];
                    }
                    self::ensure(ReferenceList::from($list), $code, $label, $i + 1, $meta);
                }
            }

            $position = 1;
            foreach (self::STAGES as $label => [$probability, $outcome]) {
                self::ensure(
                    ReferenceList::PipelineStage,
                    Str::slug($label),
                    $label,
                    $position++,
                    ['probability' => $probability, 'outcome' => $outcome, ...($label === self::QUOTE_STAGE ? ['quote_stage' => true] : [])],
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
