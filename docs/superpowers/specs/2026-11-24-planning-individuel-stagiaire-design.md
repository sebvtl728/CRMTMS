# Planning individuel d'un stagiaire — conception

Date : 2026-11-24. Module : Sessions (`tms/app/Modules/Sessions/`). Statut : à valider.

## 1. Intention

Aujourd'hui, chaque inscrit suit toutes les séances de sa session, entre son jour d'entrée et son jour de sortie
(`Attendances::expected`). CAP FORMATION doit pouvoir adapter le parcours d'**un** stagiaire (une inscription) :

1. **Dispense** de séances ou d'un module entier : elles sortent de son planning, de ses heures prévues et de son émargement ;
2. **Séances ajoutées** d'une autre session : rattrapage, parcours personnalisé ;
3. **Horaires individuels** sur une séance : arrivée tardive ou départ anticipé prévus.

Le planning se modifie depuis le bloc « Suivi de formation » de la fiche du candidat admis (`training-follow-up.tsx`,
`Sessions\Public\Admission::followUp`) et depuis l'inscription côté session (liste des inscrits de la session).

## 2. Décisions de l'utilisateur

- **Option A (heures et argent)** : le planning individuel **recalcule les heures prévues** de l'inscription ; les heures
  faites sur une séance ajoutée comptent dans **son** dossier d'origine (heures, facturation, documents), jamais dans la
  session qui l'accueille ; une dispense **ne baisse pas le prix** (le prix se change à la main dans le dossier).
  Conséquence voulue : facturé au réalisé, un stagiaire dispensé qui suit tout le reste est facturé 100 % de sa part.
- **Stockage** : une table d'écarts par inscription (approche 1 ci-dessous).

## 3. Approches considérées

1. **Écarts par inscription (retenue)** : une ligne par séance concernée (dispensée, ajoutée, horaires propres) et une
   ligne par module dispensé. Le planning se lit comme aujourd'hui puis les écarts s'appliquent (une seule requête
   partagée). Peu de données ; une séance déplacée ou annulée garde ses règles actuelles ; un module dispensé couvre aussi
   les séances créées plus tard.
2. **Une ligne par stagiaire × séance pour tous** : lectures simples, mais chaque création / déplacement / annulation de
   séance doit synchroniser N lignes (dérive, reprise lourde des sessions existantes). Écartée.
3. **Session individuelle copiée** : séances en double, formateurs et salles comptés deux fois, émargement de groupe
   cassé. Écartée.

## 4. Objectifs, non-objectifs, anti-objectifs

- **Objectifs** : les trois écarts ; tout ce qui lit « qui est attendu à quelle séance, combien de temps » lit le
  planning individuel : présences, émargement (demi-journées, validation, rattrapages), rappel de la veille,
  convocation, attestation / certificat (heures), heures prévues et réalisées (`RealisedHours`, `Billing::hours`),
  suivi de formation, planning de la fiche (`TraineePlanning`).
- **Non-objectifs** : pas de baisse automatique du prix ; pas de séance créée pour un seul stagiaire (on ajoute une
  séance existante d'une autre session) ; pas de changement d'Outlook (l'évènement reste celui de la séance, sans
  stagiaire) ; pas de dispense « en masse » de plusieurs stagiaires à la fois ; pas de rapport BPF (il n'existe pas encore :
  quand il viendra, il lira les heures réalisées de l'inscription, donc du dossier d'origine).
- **Anti-objectifs** : une présence saisie à la main jamais effacée en silence ; un mois clôturé jamais modifié ; le
  stagiaire accueilli n'apparaît jamais dans les inscrits, la capacité, les heures, la facturation, la rétention ou les
  questionnaires de la session qui l'accueille ; jamais deux fois les mêmes minutes.

## 5. Modèle de données

Table `enrolment_adjustments` (RLS, `tenant_id`, `BelongsToTenant`, `Auditable`, UUID v7) :

| colonne | rôle |
|---|---|
| `enrolment_id` | l'inscription concernée (FK, cascade) |
| `kind` | `exempt_seance` · `exempt_module` · `added_seance` · `own_hours` (CHECK) |
| `seance_id` | séance visée (nul pour `exempt_module`) |
| `session_module_id` | module dispensé (seulement `exempt_module`) |
| `starts_at`, `ends_at`, `break_minutes` | horaires propres (`own_hours`, et facultatifs pour `added_seance`) |
| `duration_minutes` | générée (fin − début − pause), comme `seances` |
| `reason` | motif facultatif ≤ 300 (dans `$auditPersonal`) |
| `created_by`, timestamps | |

Contraintes : unique (`enrolment_id`, `seance_id`) quand `seance_id` non nul ; unique (`enrolment_id`, `session_module_id`)
pour `exempt_module` ; CHECK cohérence des colonnes par `kind` ; horaires propres dans les bornes de la séance
(début ≥ début séance, fin ≤ fin séance, durée > 0 : on arrive plus tard / part plus tôt, jamais au-delà).
`added_seance` : la séance appartient à une **autre** session (vérifié en PHP) ; `exempt_seance` / `own_hours` : une
séance de **sa** session, en mode séances. Une séance ajoutée peut porter ses propres horaires (colonnes horaires
remplies sur la ligne `added_seance`, pas de seconde ligne).

## 6. Service central : `Sessions\Planning\IndividualPlanning`

Seule source du « planning d'un stagiaire » ; tous les lecteurs passent par lui (aucune requête d'écarts ailleurs).

- `expectedAt(Seance $s): Builder<Enrolment>` remplace le corps d'`Attendances::expected` : inscrits de la session de la
  séance (règles actuelles : états, entrée, sortie) **sans** ceux dispensés de la séance ou de son module, **plus** les
  inscriptions d'autres sessions qui l'ont en `added_seance` (mêmes règles d'état, d'entrée et de sortie, lues sur leur
  propre inscription). `Attendances::expected` délègue (signature inchangée).
- `minutesAt(Enrolment, Seance): int` : durée de la séance pour lui (horaires propres sinon `duration_minutes`).
  `hoursAt` : début / fin pour lui.
- `seancesOf(Collection<Enrolment>, ?from, ?to)` : séances de chacune, en requêtes groupées (une par type de données) :
  séances de sa session non dispensées + séances ajoutées, avec ses horaires. Lu par `TraineePlanning`,
  `TraineeFollowUp`, `Paperwork::enrolment` (`schedule`), le rappel de la veille.
- `plannedMinutes(Enrolment): int` (décision utilisateur, 2026-11-24) :
  - **base** = les heures prévues **de l'inscription** au moment du premier écart (valeur saisie à la main ou, à défaut,
    celle de la session), gardée dans `enrolments.planned_hours_base` (numeric(6,2), nul = aucun écart) ;
  - **par module** du stagiaire : module dispensé → retire ses `planned_minutes` ; sinon retire Σ durées des séances
    dispensées (non annulées) + Σ (durée séance − durée propre) des horaires propres, **plafonné** aux `planned_minutes`
    du module (un module ne descend jamais sous 0 ; module sans heures prévues : plafond 0, rien retiré) ;
  - **ajouts** : + Σ durées (propres sinon séance) des séances ajoutées non annulées ;
  - total jamais < 0 ; minutes entières.
  Exemple : module prévu 35 h, 30 h de séances, toutes dispensées une à une → − 30 h (5 h restent dues : c'est le signe
  qu'il faut dispenser le module, − 35 h). L'écran le dit sous les heures calculées.

**Heures prévues stockées** : `enrolments.planned_hours` reste la colonne lue par `RealisedHours`, `Billing::hours`,
`Paperwork` (aucun lecteur ne change). Au premier écart, `planned_hours_base` ← `planned_hours ?? session.planned_hours` ;
ensuite `planned_hours` est **recalculée** (`IndividualPlanning::refresh($enrolment)`) dans la transaction de chaque
écriture d'écart et au déplacement / à l'annulation / à la suppression d'une séance qui porte des écarts (crochet
existant `Seances::onScheduleChange`). Retirer le dernier écart remet `planned_hours` = `planned_hours_base` et vide la
base. Un changement des heures de la session ne touche pas la base (même règle qu'aujourd'hui dans
`SessionModules::recompute` : les heures des stagiaires sont gardées, avertissement). Le champ « Heures prévues » de
l'inscription est en lecture seule tant qu'il y a des écarts (« calculées d'après son planning individuel :
base 35 h − 7 h dispensées ») ; le modifier à la main passe par la base (`Enrolments::update` écrit `planned_hours_base`
puis `refresh`).

## 7. Écritures : `Sessions\Planning\IndividualPlanningWriter`

Opérations : `exemptSeances(Enrolment, list<seanceId>, ?reason)`, `exemptModule(Enrolment, moduleId, ?reason)`,
`addSeances(Enrolment, list<seanceId>, ?reason)`, `setOwnHours(Enrolment, seanceId, starts, ends, break)`,
`remove(EnrolmentAdjustment)`. Chacune dans une transaction, sous verrou de l'inscription puis de la (des) séance(s)
triée(s) par id (même ordre que `Attendances`), puis `refresh` des heures prévues.

Règles :
- inscription vivante (inscrit, confirmé ; sorti seulement pour des séances ≤ son jour de sortie) ; jamais une séance
  annulée ; jamais une séance d'un **mois clôturé** (de la session de la séance : `Closures::assertOpen`, 409) ;
- **présence existante** : dispenser une séance où le stagiaire a une présence (ou une signature) est refusé (409, « une
  présence est saisie : effacez-la d'abord ») ; idem pour retirer une séance ajoutée ; horaires propres sur une séance déjà
  saisie : la présence passe « à revoir » (même règle que `Attendances::review`, présent recalculé à sa durée) ;
- dispense de module : toutes les séances du module (présentes et futures) ; refusée si une présence existe sur une
  séance du module ou des heures mensuelles (`monthly_hours`) sur un module « période » ; une dispense de séance
  déjà couverte par la dispense de son module est refusée (inutile) ;
- séance ajoutée : d'une session non annulée, hors corbeille, autre que la sienne, en mode séances ; pas déjà dans son
  planning ; le jour dans ses dates d'entrée / sortie ; conflit horaire avec une autre séance de son planning signalé
  (avertissement, pas un refus) ;
- le mode « séances » / « période » se lit sur le **module** de la séance (`session_modules.dating_mode`), jamais sur
  la session (une session mélange les deux) ;
- droits : `sessions.sessions.update` sur la session du stagiaire (celui d'`EnrolmentPolicy`) ; ajouter une séance d'une autre session exige aussi
  `sessions.sessions.view` sur cette session (on ne choisit que parmi ce qu'on voit) ;
- audit : `sessions.enrolment.planning_changed` sans donnée personnelle (le motif en `[donnée personnelle]`).

## 8. Effets sur les lecteurs existants

| Lecteur | Changement |
|---|---|
| `Attendances::expected`, `assertExpected`, `enrolmentOf` | délèguent à `IndividualPlanning` ; `enrolmentOf` accepte une inscription d'une autre session qui a la séance en ajout ; message français si dispensé (« Ce stagiaire est dispensé de cette séance. ») |
| `Attendances::record` / `allPresent` / `fromSigning` / `review` | « présent » = `minutesAt` (ses horaires) ; partiel borné par sa durée ; `review` recalcule à sa durée |
| `AttendanceRows` (grille, compteurs du mois) | lit `expectedAt` (remplace `attendsDay`) ; le stagiaire accueilli marqué « Rattrapage (D-… ) » ; dispensé absent de la grille |
| Émargement (`SlotSeances::expected`, `SlotValidation::writeAttendance`, `SlotRows`, `publicNames`, rattrapages) | l'hypothèse « toutes les séances d'un jour attendent les mêmes stagiaires » tombe : attendus du créneau = union des `expectedAt` des séances du créneau qu'il suit **et dont ses horaires touchent la demi-journée** (`Halves::of` sur ses horaires) ; la présence se calcule séance par séance pour celles qu'il suit |
| `RealisedHours` | aujourd'hui groupé par module de **sa** session : les minutes d'une séance ajoutée n'y sont pas. Ajout d'une entrée `by_module` « rattrapage » (`module_id` nul, `planned` = Σ séances ajoutées, `realised` = leurs présences) incluse dans `realised_minutes` ; module dispensé : `planned` = 0 ; module à horaires propres : `planned` réduit comme § 6 |
| `Billing::hours` | `realised_minutes` compte déjà les présences par `enrolment_id` (à garder égal à `RealisedHours`, test) ; `absent_minutes` se calcule sur **sa** durée (`minutesAt`, horaires propres joints en SQL) et ignore les séances dispensées ; `all_months_closed` / `closed_months` : un mois où il a une séance ajoutée n'est clôturé que si la session d'accueil l'a clôturé (et la sienne, si elle a des séances ce mois-là) — une séance ajoutée peut tomber après la fin de sa session : la facture finale attend |
| `Paperwork::sessionsFinallyClosedSince` (attestations automatiques) | une inscription qui a des séances ajoutées dans des mois non clôturés de la session d'accueil est sautée (rattrapée au passage suivant, fenêtre de 30 jours partant de la dernière clôture concernée) |
| `MonthlyHours` | refuse la saisie sur un module dispensé |
| `TraineePlanning`, `TraineeFollowUp` | `seancesOf` ; une séance ajoutée affiche sa session d'origine ; dispensée non listée (le suivi peut afficher « N séances dispensées ») |
| `Paperwork::enrolment` (`schedule`, `planned_minutes`, `completeOn`) | ses séances et horaires ; convocation et attestation suivent |
| `Paperwork::sessionEnrolments`, `convocable`, `absentOn` | inchangés pour les inscrits ; un stagiaire accueilli ne reçoit **pas** de convocation de la session d'accueil (la sienne liste la séance ajoutée) |
| `DayBeforeReminders` | stagiaires = `expectedAt` (accueillis inclus, ses horaires dans le texte) ; formateurs inchangés |
| `SessionChecklist`, `Retention`, questionnaires, capacité | inchangés (ne lisent que les inscrits de la session) ; la carte présences de la session d'accueil compte les accueillis comme attendus |
| Outlook (`SeanceOutlook`) | inchangé |
| RGPD | `EnrolmentAnonymisation` efface le `reason` des écarts ; `PersonSessionsExport` ajoute une section « planning individuel » ; `FicheReferences` (fusion) rien (lié à l'inscription) |

## 9. Écrans

- **Suivi de formation** (fiche candidat) et **inscription côté session** (bouton « Planning individuel » dans
  `enrolment-list.tsx`) ouvrent le même panneau `individual-planning.tsx` :
  - liste de ses séances (date, horaires, module, session d'origine si ajoutée), dispensées grisées en bas ;
  - par séance : « Dispenser », « Horaires pour lui » (début, fin, pause), « Retirer » (séance ajoutée) ;
  - par module : « Dispenser du module » ;
  - « Ajouter des séances » : recherche de session (libellé, numéro), puis cases à cocher des séances à venir ou du mois
    ouvert, avec l'avertissement de conflit ;
  - en tête : heures prévues calculées « 35 h (session 42 h − 7 h dispensées) ».
- Le panneau ne s'affiche qu'avec le droit d'écriture ; sinon lecture seule dans le suivi.

## 10. Livraisons

1. **L1 — dispense et horaires individuels** (même session) : table, `IndividualPlanning`, writer, attendus /
   présences / émargement / heures prévues / documents / rappel, panneau sans « Ajouter ».
2. **L2 — séances ajoutées** d'une autre session : `enrolmentOf` élargi, grille et émargement de la session d'accueil,
   clôtures croisées, recherche de séances, mention « Rattrapage ».

Chaque livraison : TDD (Pest), tests d'architecture verts, doc `tms/docs/sessions.md` (§ planning individuel),
`data-dictionary.md`, CHANGELOG.

## 11. Laissé au développeur

Nom exact des classes et routes ; découpage du panneau React ; requêtes groupées (nombre fixe par page) ; message
français exact des refus ; ordre fin des verrous en cas de séances de plusieurs sessions (toujours par id croissant).
