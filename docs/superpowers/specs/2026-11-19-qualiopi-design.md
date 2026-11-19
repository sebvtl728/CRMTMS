# Module Qualiopi — première version — conception

Date : 2026-11-19. Demande (Cartographie § 5, « Qualiopi natif ») : un module qui regroupe les 33 indicateurs du référentiel 2026, chacun avec son texte, son applicabilité, son statut, son responsable, ses preuves et ses mesures automatiques, et un dossier d'audit exportable. Périmètre de cette version validé par l'utilisateur le 2026-11-19 ; registres (veille, réclamations, handicap, risques) et accès auditeur dans une version suivante.

## Source du référentiel

Annexe du décret n° 2026-728 du 1er août 2026 (JO du 4 août 2026, NOR TRSD2619632D), entrée en vigueur le 1er novembre 2026, sans disposition transitoire : 7 critères, 33 indicateurs ; nouveau : 33 ; modifiés : 1, 2, 3, 7, 9, 12, 14, 15, 19, 20, 27, 30, 31, 32 ; propres aux CFA : 14, 15, 20, 29, 33. Transcription faite depuis un document d'information (Walt, octobre 2026) — **à vérifier sur Légifrance** ; l'écran l'indique. Les seuils des indicateurs 19 et 20 attendent un arrêté.

## Décisions

- **Module `Qualite`** (`app/Modules/Qualite/`), menu **Qualité › Qualiopi**, droits `qualite.view` (consulter) et `qualite.manage` (tenir le dossier) ; par défaut : administrateur et direction ont les deux, les autres rôles rien (ajustable dans Rôles et droits). Un organisme qui n'utilise pas le module n'accorde pas ces droits : c'est l'« option ».
- **Référentiel dans le code** (texte national, identique pour tous) : `QualiopiReferential` (version `2026`, critères, indicateurs : numéro, critère, titre, texte, statut de la modification, champ d'application : `all`, `cfa`, `certifying`, `work_based`, `subcontracting`, `alternance`) ; versionné pour accueillir une version suivante sans perte d'historique.
- **Travail de l'organisme** (tables, RLS) : `quality_indicators` (version, numéro, `applicable` bool, `not_applicable_reason`, `status` : `todo` / `in_progress` / `ready` / `to_review`, responsable, date de revue, notes) — une ligne créée à la première modification ; sans ligne : applicable, à faire. Un indicateur non applicable exige sa justification (jamais masqué).
- **Preuves** (`quality_proofs`) : fichier (PDF, images, documents bureautiques, 20 Mo, disque privé `TenantStorage` zone `quality`), lien (URL http(s)) ou note ; titre ; auteur ; date ; suppression par qui gère.
- **Mesures automatiques** : registre `Qualite\Public\Measures::register(int $indicator, string $key, callable)` ; chaque module déclare les siennes (Catalogue, Sessions, Admissions) ; le module Qualité ne lit aucune table des autres. Première série : 1 et 5 (fiches formation complètes), 8 (tests de positionnement reçus), 12 (taux d'abandon sur 12 mois), 13 (contrats d'alternance avec tuteur).
- **Tableau de bord** : avancement (indicateurs prêts / applicables), par critère, filtre par statut.
- **Dossier d'audit** : **Exporter** produit une archive ZIP : `sommaire.pdf` (les 33 indicateurs : texte, applicabilité, statut, responsable, mesures, liste des preuves) et un dossier par indicateur avec ses fichiers ; journalisé.

## Laissé au constructeur
Mise en page, libellés, ordre des colonnes ; seuil « complète » d'une fiche formation (objectifs, public, prérequis, méthodes, évaluation, accessibilité renseignés).
