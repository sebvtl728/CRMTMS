# Lot 1e : fidélisation, suivis et tableaux de bord commerciaux - conception

Statut : validée dans la conversation du 2026-11-20 (approches retenues : calcul en direct, passage quotidien).
Sources : `Cdc/cdc-activité-commercial.md` (« Fidélisation », « Tableaux de bord et indicateurs »),
`docs/superpowers/specs/2026-11-11-tms-design.md` § 1e (règles de calcul, qui font autorité ici sauf mention contraire).

## 1. Intention

CAP FORMATION pilote son activité commerciale à trois rythmes : la **réunion commerciale de la semaine**, le **point de
direction du mois**, et **chaque commercial** qui suit ses propres chiffres. Le lot 1e leur donne une page de tableaux
de bord fidèle au cahier des charges (12 indicateurs, formules affichées), et automatise ce qui fait revenir un client :
le **suivi pédagogique à 3 mois** par le formateur (pratique réelle de CAP FORMATION) et les **rappels de
réactivation commerciale** à 6 et 12 mois.

**Objectifs** : les 12 indicateurs du CDC, filtrables, comparés à la période précédente et aux objectifs, exportables ;
les rappels créés seuls, une fois, à la bonne personne ; la fiche client qui montre ce qu'il a suivi et ses besoins
futurs.

**Non-objectifs** : questionnaires de satisfaction et note de satisfaction (0.11 ; le 1e prévoit seulement l'endroit
où la note s'affichera) ; graphiques élaborés ou tableaux de bord personnalisables par l'utilisateur ; envoi
automatique des tableaux de bord par e-mail ; prévisions statistiques.

**Anti-objectifs** : un chiffre qui ne correspond pas aux listes (un clic sur un indicateur doit montrer exactement ses
lignes) ; un commercial qui voit des chiffres hors de son périmètre ; un rappel créé deux fois ou adressé à un client
déjà en train de racheter.

## 2. Approches

- **Tableaux de bord - retenue : calcul en direct** à l'ouverture, par les requêtes des listes existantes
  (`OpportunityListQuery`, actions, `QuoteListQuery`, `DasRevenue`), périmètre des droits appliqué, cache 60 s
  préfixé par le tenant. Écartées : photo nocturne (un jour de retard, une photo par périmètre d'utilisateur) ; outil
  d'analyse externe (droits non respectés, logiciel de plus).
- **Rappels - retenue : passage quotidien** (`ForEachTenant`) qui repère les formations terminées depuis le délai et
  crée les actions, une table empêchant les doublons. Écartées : action créée dès l'inscription avec échéance lointaine
  (listes encombrées, à corriger si les dates changent) ; déclenchement à la fin de session (aucun évènement quand une
  date passe).

## 3. Tableaux de bord

### 3.1 Page

Menu CRM › **Tableaux de bord** (`/crm/dashboards`), accessible avec l'un des droits « Consulter » du CRM
(`Permissions::anyView`, comme l'accueil commercial) ; chaque indicateur suit le périmètre du droit de son domaine
(opportunités : `crm.opportunities.view` ; actions : `crm.activities.view` ; devis : droit des devis ; CA :
`crm.revenue.view`, sinon la carte n'apparaît pas). Un commercial au périmètre « ses fiches » voit donc ses chiffres.

Barre de filtres commune : **période** (préréglages : cette semaine, semaine dernière, ce mois, mois dernier, ce
trimestre, cette année, ou dates libres ; jours de Paris), **DAS**, **cible** (entreprise / particulier),
**commercial** (responsable). Deux vues par onglet, mêmes filtres :

- **Semaine** (défaut « cette semaine », comparée à la semaine précédente) : appels et RDV, nouveaux leads, pipeline
  pondéré, opportunités à clôturer (date de clôture prévue dans la période), devis envoyés et acceptés.
- **Mois / direction** (défaut « ce mois », comparé au mois précédent et aux objectifs) : CA signé par DAS et
  objectifs, taux de conversion, CA prévisionnel, durée du cycle de vente, formations vendues, entreprises /
  particuliers, origine des leads, motifs de perte, rétention et réachat.

Chaque carte : valeur, valeur de la période précédente et écart, **formule en une ligne** sous la valeur, lien
« Voir le détail » vers la liste filtrée qui la compose (liste des opportunités, des actions ou des devis avec les
mêmes filtres ; pour la rétention, la liste des clients). L'écran **CA par DAS** existant reste et est lié depuis la
carte CA.

### 3.2 Les 12 indicateurs (formules)

Période = [début, fin] en jours de Paris ; « dans la période » = la date de référence y tombe. Fiches et opportunités à
la corbeille exclues partout. Montants en centimes entiers (`brick/math`), jamais de flottant.

| Indicateur | Formule (affichée) | Date de référence |
| --- | --- | --- |
| Nombre de leads | Fiches (entreprise ou particulier) dont une opportunité atteint pour la première fois l'étape seuil (« Contacté » par défaut, réglable) | date du passage (`opportunity_stage_changes`) |
| Taux de conversion | Opportunités gagnées / opportunités créées, parmi celles créées dans la période | création |
| CA prévisionnel | Somme des montants des opportunités ouvertes (ni gagnées ni perdues), date de clôture prévue dans la période | clôture prévue |
| Pipeline pondéré | Somme montant × probabilité des mêmes opportunités ouvertes | clôture prévue |
| Appels et RDV | Nombre d'actions faites de type « call », RDV fixés (types `meta.planning`) ; taux de réponse = appels faits dont le résultat porte `meta.reached` / appels faits | `done_at` (RDV fixés : `created_at`, comme `ActivityStats`) |
| Durée du cycle de vente | Moyenne et médiane en jours de (date de gain − date de création) des opportunités gagnées | gain |
| Formations vendues | Nombre de lignes de formation des opportunités gagnées (ligne de devis accepté, sinon formation de l'opportunité), réparti par formation | gain |
| Entreprises / particuliers | CA signé et nombre d'opportunités gagnées par cible | gain |
| Origine des leads | CA signé et nombre d'opportunités gagnées par origine de la fiche (référentiel `contact_origin`) ; « Non renseignée » à part | gain |
| Taux de rétention | Clients (comptes de dossiers) ayant au moins deux dossiers non annulés dont la formation a commencé dans la fenêtre glissante (24 mois par défaut, réglable) se terminant à la fin de la période / clients ayant au moins un dossier dans cette fenêtre ; « réachat » = même calcul limité aux clients dont le deuxième dossier commence dans la période | début de formation |
| Motifs de perte | Nombre et montant des opportunités perdues par motif (`loss_reason_id`) | perte |
| CA par DAS | Règles de `DasRevenue` (devis accepté par ligne, sinon opportunité gagnée sans devis accepté) ; objectifs de `RevenueTargets` | acceptation / gain |

« CA signé » partout = règle de `DasRevenue` (jamais deux fois une opportunité). Le flag `meta.reached` est ajouté au
référentiel des résultats d'action (« Réussi » coché par défaut dans le jeu CAP FORMATION, réglable) ; sans aucun
résultat coché, le taux de réponse affiche « - » avec une aide.

### 3.3 Architecture

- `Crm\Dashboard\Indicators` : registre d'indicateurs (clé, onglet, droit exigé, formule, calcul
  `fn (DashboardFilters, User): IndicatorValue`, lien de détail). Chaque indicateur est une petite classe qui réutilise
  la requête de sa liste (`OpportunityListQuery::build`, requête des actions, `QuoteListQuery`, `DasRevenue`) pour que
  le chiffre et le détail aient la même source.
- **Rétention** : le CRM ne lit pas les dossiers. Le module Sessions déclare l'indicateur dans le registre public
  `Crm\Public\DashboardIndicators::register(...)` depuis son `boot` (comme `Qualite\Public\Measures`), et calcule sur
  ses propres tables ; filtre « commercial » = responsable de la fiche client lu par `Crm\Public\Fiches`.
- `DashboardFilters` : période (préréglage ou dates), période précédente de même longueur, DAS, cible, responsable ;
  validés, jours de Paris.
- Cache 60 s par (tenant, utilisateur, filtres, indicateur) ; clé préfixée par le tenant.
- **Export** : Excel = CSV `;` (une ligne par indicateur : libellé, formule, période, valeur, période précédente,
  objectif), droit `crm.exports` existant ; PDF = la page rendue par `PdfRenderer` (gabarit Blade dédié, filtres
  rappelés en tête, `{{ }}` seulement). Exports journalisés dans l'audit.

## 4. Suivi formateur à 3 mois (Sessions)

- **Quoi** : une action « à faire » de type Appel par **inscription réellement suivie** (statut confirmée, non
  abandonnée, non annulée ; session non annulée), **3 mois après la fin de formation de l'apprenant** (`left_on`, sinon
  fin de la session). Délai réglable : `tenants.settings.sessions.trainer_follow_up_months` (défaut 3 ; 0 = désactivé),
  écran Administration › Sessions.
- **À qui** : le formateur de la session **ayant un compte TMS** (`trainers.user_id`) qui a assuré le plus d'heures de
  séances non annulées (égalité : le premier par ordre alphabétique) ; sinon (aucun formateur avec compte) le
  **responsable de la session**, sinon le responsable du dossier ; sinon aucune action et une ligne au journal.
- **Contenu** : fiche liée = la personne apprenante ; sujet « Suivi à 3 mois : <apprenant>, <formation> (S-…) » ;
  échéance = le jour du délai, 9 h Paris. Aucune note, aucun besoin d'adaptation ni prix dans l'action.
- **Visibilité** : l'action suit les règles des actions (responsable = formateur ou responsable) ; un formateur voit
  ses actions dans « À faire » même avec le rôle Formateur (droit `crm.activities.view` sur « ses fiches » : à vérifier
  et accorder par défaut au rôle Formateur pour ses propres actions si absent).

## 5. Réactivation commerciale à 6 et 12 mois (Sessions → CRM)

- **Quoi** : une action « à faire » de type Appel par **dossier** dont la dernière session non annulée est terminée
  depuis le délai : **6 et 12 mois** par défaut, réglables (`tenants.settings.crm.reactivation_months`, liste, vide =
  désactivé), écran Administration › CRM.
- **Client** : le compte du dossier (entreprise, sinon le particulier). Les stagiaires salariés n'ont pas de rappel.
- **À qui** : le **responsable de la fiche client** ; sinon le responsable du dossier ; sinon aucune action.
- **Pas de rappel** si, à la date du passage, le client a une **opportunité ouverte** ou un **dossier non annulé plus
  récent** (créé après la fin du dossier concerné) ; dossier annulé, fiche à la corbeille ou anonymisée : rien. Le
  rappel sauté est tout de même noté comme traité (jamais créé plus tard).
- **Contenu** : sujet « Réactivation : <formation(s)> terminée il y a <n> mois (D-…) ».

## 6. Passage quotidien et non-doublons

- Commande `tms:sessions:follow-ups` (6 h 30 Paris, `ForEachTenant`, une erreur sur un organisme n'arrête pas les
  autres), dans le module Sessions : calcule les échéances atteintes (date du jour ≥ fin + délai), y compris un retard
  (serveur arrêté quelques jours), et **jamais** une échéance antérieure de plus de 30 jours à la mise en service
  (pas de rafale de rappels sur l'historique à la première exécution).
- Table `follow_up_reminders` (tenant, `kind` : `trainer` | `reactivation`, `subject_id` : inscription ou dossier,
  `months`, `activity_id` nullable, `outcome` : `created` | `skipped`, `reason`, horodatage), unique
  (`kind`, `subject_id`, `months`) : une échéance n'est traitée qu'une fois.
- Création des actions par un **service public du CRM** `Crm\Public\Activities::planCall($fiche, $userId, $subject,
  $dueAt, $source)` (passe par `ActivityRecorder`, journal, évènement `ActivityChanged` donc Outlook) ; Sessions ne
  nomme aucune table du CRM.
- Données liées et RGPD : une action créée est une action ordinaire (anonymisation, historique) ; `follow_up_reminders`
  ne contient aucune donnée personnelle (ids seulement) et ses lignes d'une inscription supprimée restent (non-doublon).

## 7. Fiche client

- Bloc « Formations suivies » (déjà déclaré par Sessions dans `FicheBlocks`) : date de fin et emplacement de la note de
  satisfaction (« - » jusqu'à la 0.11).
- Champ **Besoins futurs** sur les fiches entreprise et personne (texte libre, 2000 caractères, avec date et auteur de
  la dernière mise à jour) ; personnel dans l'audit pour une personne (`$auditPersonal`), effacé par l'anonymisation,
  exporté par l'export RGPD.

## 8. Accueil

L'accueil commercial existant (`CommercialSummary`) garde ses blocs ; il gagne un lien « Tableaux de bord » et la
carte « Objectifs » (CA signé du mois / objectif, si `crm.revenue.view`). Pas d'autre changement.

## 9. Ce qui reste au constructeur

Mise en page exacte des cartes et des onglets ; nombre de lignes du détail ; noms de classes hors ceux cités ; forme
de l'écran de réglages (délais, étape seuil des leads, fenêtre de rétention, résultats « joint ») ; découpage en tâches
du plan. Relecture de cette conception faite par l'auteur seul (pas d'agent relecteur, à la demande de l'utilisateur).
