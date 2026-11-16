# Lot 2a — Dossiers, sessions, inscriptions : conception

Statut : soumis à validation de l'utilisateur (2026-11-15) ; les § 9 et 10 complètent et précisent les § 5.1 et 5.4. Autorité générale : `docs/superpowers/specs/2026-11-11-tms-design.md` (§ lot 2 « Opérationnel »). Ce document précise le premier sous-lot.

## 1. Intention

Passer du commercial à l'opérationnel chez CAP FORMATION : un devis signé devient un **dossier**, qui inscrit des **stagiaires** sur des **sessions** et répartit le prix entre ses **financeurs**. C'est le pivot de tout le TMS : planning (2b), documents (2c), émargement (2d), facturation (lot 3), BPF et Qualiopi (lot 4) s'appuient dessus.

Pourquoi maintenant : l'utilisateur veut le TMS opérationnel juste après le CRM ; l'ordre chronologique du métier a été choisi (2a dossiers et inscriptions → 2b planning → 2c documents → 2d émargement).

## 2. Ce que l'utilisateur a dit (décidé)

- Un dossier peut avoir **plusieurs financeurs**, le plus souvent **OPCO + entreprise** ; chacun aura sa propre facture (lot 3).
- CAP FORMATION fait de l'**intra** (une seule entreprise) et de l'**inter** (plusieurs entreprises ou particuliers ensemble).
- Un même dossier peut regrouper **plusieurs formations et plusieurs sessions**.
- **À la signature du devis, les noms des stagiaires et les dates des sessions sont connus** : pas de « places à nommer ».
- Étapes du dossier proposées et acceptées (§ 5.3) ; règle d'abandon paramétrable acceptée (§ 5.6).
- Organisation du code : un module **`Sessions`** (option recommandée, non contestée).

## 3. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Créer un dossier en un clic depuis un devis accepté (client, contact, lignes, montant repris), ou à la main.
- Créer des sessions intra et inter d'une formation du catalogue (dates, lieu, places, responsable).
- Inscrire des stagiaires (personnes du CRM, ou créées sur place) sur une session, pour le compte d'un dossier.
- Suivre les financements par financeur (montant, état de l'accord, n° de prise en charge) et le reste à financer.
- Suivre l'état du dossier et de chaque inscription (dont abandon avec motif).
- Listes, filtres, exports Excel des dossiers, sessions, inscriptions ; historique ; droits par domaine et périmètre (0.5.0).

**Non-objectifs (sous-lots suivants)**
- Créneaux, intervenants, salles, conflits d'agenda (2b).
- Génération de convention, convocation, attestations (2c) ; « Bon pour accord » en ligne (2c, avec la signature).
- Émargement et calcul des heures réalisées (2d) ; SoWeSign (2d).
- Factures, échéances, règlements (lot 3) ; BPF (lot 3) ; portails (lot 5).
- Reprise Formevol (attend leur export).

**Anti-objectifs (échec même si « ça marche »)**
- Une personne en double entre le CRM et les stagiaires.
- Un total de financements qui ne correspond pas au prix sans que l'utilisateur le voie.
- Une modification du devis accepté par le dossier (le devis reste figé ; le dossier vit sa vie).
- Un module qui lit les tables du CRM ou du Catalogue directement.

## 4. Contraintes

Toutes celles du `CLAUDE.md` (RLS, `BelongsToTenant`, référentiels paramétrables, français, TDD, montants en centimes avec `brick/math`, fuseau Paris, files porteuses du tenant, audit sans donnée personnelle en clair). En plus :
- Module `tms/app/Modules/Sessions/` ; il ne nomme aucune table du Crm ni du Catalogue (test d'architecture, comme Catalogue/Crm aujourd'hui). Il consomme : `Crm` via services publics (lecture d'un devis accepté et de ses lignes ; recherche, lecture et création de personnes et d'entreprises ; visibilité) ; `Catalogue\Trainings` (titre, durée en heures, DAS).
- Droits par domaine (0.5.0) : nouveaux domaines **Dossiers** (`sessions.dossiers.view|update|delete`) et **Sessions et inscriptions** (`sessions.sessions.view|update|delete`), à périmètre (« Ses fiches » = responsable ou créateur ; « Ses DAS » = DAS de la formation de la session / DAS du dossier ; « Tout »).
- Données personnelles des stagiaires (date de naissance, situation de handicap…) : `$auditPersonal`, anonymisation RGPD étendue (l'Anonymiser appelle le module, comme `QuoteAnonymisation`).

## 5. Le modèle (ce qui est décidé)

### 5.1 Dossier
- Numéro automatique par organisme (`D-2026-0001`, séquence annuelle Paris, même mécanique que `quote_sequences`), société émettrice, **contractant** (entreprise **ou** particulier, comme le devis), contact, responsable, devis d'origine (facultatif), DAS principal (celui de l'opportunité), notes.
- **Lignes** : une par formation (formation du catalogue, libellé, quantité, unité, prix unitaire HT, remise, total HT) — reprises du devis accepté, modifiables ensuite dans le dossier. **Prix du dossier** = somme des lignes HT.
- **Financements** : une ligne par financeur — organisme financeur (entreprise du CRM : OPCO, l'entreprise elle-même, France Travail, Région…), type de financement (référentiel paramétrable : OPCO, entreprise, CPF, France Travail, personnel…), montant HT, **état** (à demander, demandé, accordé, refusé), n° de prise en charge, date d'accord, rattachement facultatif à une ligne de formation. Alerte visible quand somme des financements ≠ prix (« Reste à financer : 900,00 € » ou « Financements supérieurs au prix »). Refus d'un financeur : l'utilisateur reporte le montant sur un autre financeur ou le laisse « à financer » (pas de bascule automatique).

### 5.2 Session
- Formation du catalogue, référence auto (`S-2026-0001`), **type intra / inter**, dates de début et de fin, heures prévues (reprises de la formation, modifiables), lieu (adresse libre ou « chez le client », « distanciel »), nombre de places (inter), responsable, état (planifiée, confirmée, en cours, terminée, annulée).
- Intra : rattachée à une entreprise ; seuls ses dossiers y inscrivent (contrôle à l'inscription).
- Inter : ouverte à plusieurs dossiers ; au-delà des places, l'inscription passe en **liste d'attente** (ordre d'arrivée, promotion manuelle).

### 5.3 États du dossier
Créé → Convention envoyée → Convention signée → Convocations envoyées → En cours → Réalisé → (lot 3 : Facturé → Soldé) ; Annulé (motif obligatoire, depuis n'importe quel état avant Réalisé). Les financements ont leur propre état, en parallèle (la demande à l'OPCO peut précéder ou suivre la convention). En 2a, les passages « convention envoyée/signée », « convocations envoyées » se font à la main (dates enregistrées) ; 2c les automatisera à la génération et à la signature des documents. « En cours » et « Réalisé » se déduisent des dates des sessions de ses inscriptions, avec passage manuel possible.

### 5.4 Inscription (participation)
Stagiaire (personne du CRM) × session × dossier × ligne de formation du dossier ; état (inscrit, en attente, confirmé, abandon, annulé), dates d'entrée et de sortie, heures prévues (de la session), prix unitaire repris de la ligne, catégorie BPF (référentiel ; servira au lot 3), situation de handicap / besoins d'adaptation (donnée personnelle, facultative), notes. Un stagiaire n'est inscrit qu'une fois sur une même session.

### 5.5 Création depuis un devis accepté
Bouton « Créer le dossier » sur un devis accepté (une seule fois ; ensuite lien vers le dossier). Assistant en trois écrans : 1) récapitulatif repris (contractant, contact, lignes, montant) ; 2) pour chaque ligne : choisir une session existante de cette formation ou en créer une (intra pré-remplie pour l'entreprise) ; 3) stagiaires : chercher parmi les personnes du CRM (les salariés rattachés au contractant en premier) ou créer sur place, puis les inscrire. Financements ajoutés ensuite sur le dossier (pré-rempli : une ligne « entreprise » du montant total). Tout en une transaction.

### 5.6 Abandon
Sur une inscription : date de sortie, **motif** (référentiel paramétrable ; chaque motif porte un drapeau « force majeure »). Réglage d'organisme « Règle en cas d'abandon » : *prorata seulement* (défaut) ou *prorata + dédit de X %* ; surchargeable par dossier. 2a enregistre et affiche la règle applicable ; les heures réalisées (2d) et le calcul des factures (lot 3) l'utiliseront. Force majeure ⇒ jamais de dédit.

## 6. Approches considérées

| Approche | Pour | Contre | Retenue |
|---|---|---|---|
| **Un module `Sessions`** (dossiers, sessions, inscriptions, financements) | Les quatre objets changent ensemble ; frontières claires vers Crm, Catalogue et la future Finance | Module assez gros | **Oui** (recommandée, non contestée) |
| Deux modules `Dossiers` et `Sessions` | Frontières plus fines | Beaucoup de câblage entre deux parties indissociables | Non |
| Tout dans `Crm` | Plus rapide au début | Crm déjà gros ; contraire à la spec (un module par domaine) | Non |

## 7. Écrans

- **Dossiers** : liste (numéro, contractant, formations, prix, reste à financer, état, responsable), filtres, export ; page dossier (en-tête état + actions, lignes, financements avec alerte, inscriptions par session, historique).
- **Sessions** : liste et vue calendrier mensuelle simple (par dates, sans créneaux), page session (infos, places restantes, inscrits par dossier, liste d'attente).
- **Fiche personne** : bloc « Formations suivies » (ses inscriptions) ; **fiche entreprise** : bloc « Dossiers ».
- **Devis accepté** : bouton « Créer le dossier » / lien vers le dossier.
- Accueil : « Sessions qui démarrent dans les 7 jours ».

## 8. Laissé au constructeur

Noms exacts des tables et classes, découpage des contrôleurs, forme exacte des listes et du calendrier, choix des index (en respectant la note RLS du `CLAUDE.md`), ordre des tâches du plan.

## 9. Décisions prises après la relecture du constructeur (choix Claude, à vérifier)

- **Financeur** : une entreprise du CRM **ou** une personne (stagiaire qui paie lui-même, particulier en CPF) — même forme que le compte d'un devis (deux colonnes + CHECK).
- **Lignes du devis sans formation** (frais de déplacement, repas, forfait) : reprises dans le dossier comme lignes « frais », comptées dans le prix et la comparaison avec les financements, sans session ni inscription.
- **États** : dossier, session et inscription ont des états **fixés dans le code** (comme les statuts de devis) car ils pilotent des règles ; les listes métier autour (types de financement, motifs d'abandon et d'annulation, catégories BPF) sont des référentiels paramétrables. « En cours » / « Réalisé » sont calculés à la lecture d'après les dates des sessions tant que personne ne les a posés à la main ; un état posé à la main n'est plus recalculé.
- **Visibilité** : une session se voit selon le droit Sessions ; sur une session inter, on voit le nom des inscrits de tous les dossiers, mais prix, financements et contractant d'un dossier ne se voient qu'avec le droit Dossiers sur ce dossier. Une inscription se modifie avec le droit Sessions et inscriptions. Un dossier créé à la main a un DAS obligatoire, proposé d'après la formation de sa première ligne.
- **Recherche de stagiaire** : elle porte sur toutes les personnes de l'organisme (nom, prénom, date de naissance, entreprise actuelle seulement) pour éviter les doublons, même pour un utilisateur limité à ses DAS ; une personne créée comme stagiaire est une personne ordinaire du CRM.
- **Point de départ** : le lot 2a démarre après la fusion de la 0.5.0 dans `main` (il s'appuie sur ses droits par domaine).

## 10. Réponses de l'utilisateur (2026-11-15)

- **Mode de prix d'une ligne de formation, au choix** : *prix par stagiaire* (le montant de la ligne est réparti à parts égales entre les inscrits ; chaque inscription porte sa part ; prorata d'abandon sur cette part) ou *prix de groupe* (le prix reste sur la ligne ; prorata sur les heures du groupe). Proposé d'après l'unité du devis (« stagiaire » → par stagiaire ; « forfait », « jour », « heure », « session » → de groupe), modifiable. Répartition au centime : le reste de la division va aux premières inscriptions (somme exacte).
- **Niveau du financement : pas encore connu** → modèle souple : un financement appartient au dossier et peut viser en plus **une ligne de formation** ou **une inscription** (les deux facultatives, l'une ou l'autre). Le « reste à financer » se calcule au niveau du dossier, et par ligne / par inscription quand des financements les visent. Le lot 3 facturera ce qui est saisi.
- **Données du stagiaire** : sur la personne (réutilisées) : date de naissance (existe déjà), niveau de diplôme et situation professionnelle (référentiels paramétrables), ajoutés au Crm par un service public d'écriture ; sur l'inscription : besoins d'adaptation / situation de handicap (facultatif, donnée sensible : `$auditPersonal`, anonymisée). **Pas de numéro de sécurité sociale** (le CPF passe par la plateforme de la Caisse des Dépôts).
