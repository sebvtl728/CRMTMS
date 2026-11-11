# TMS sur mesure — Document de conception

Date : 11 novembre 2026 · Auteur : Sébastien (demande) / Claude (rédaction)
Statut : **brouillon à valider**

Documents sources (font partie intégrante de cette conception) :

- `Cdc/Cartographie projet – TMS sur mesure.md` — périmètre fonctionnel complet du TMS (16 modules, Qualiopi, finance, portails). Abrégé **[CARTO]**.
- `Cdc/cdc-activité-commercial.md` — cahier des charges détaillé de la gestion commerciale. Abrégé **[CDC]**.

Ce document ne recopie pas ces sources : il fixe les décisions, l'ordre de construction, l'architecture et la spécification détaillée du **lot 1**. Les lots suivants seront précisés chacun par leur propre document avant développement.

Légende : **[décidé]** = décidé par Sébastien · **[choix Claude]** = décision du rédacteur, modifiable · **[builder]** = laissé au développeur.

---

## 1. Intention

Construire un **TMS (Training Management System) complet** pour organismes de formation, inspiré de Formdev, couvrant tout le cycle : prospection → devis → dossier → session → émargement → évaluation → facturation → BPF → Qualiopi. **[décidé]**

Pourquoi maintenant : CAP FORMATION utilise aujourd'hui **Formevol**, qui **n'a pas de gestion commerciale** ; le suivi commercial est dispersé (mails, notes, agenda) [CDC]. Le besoin urgent est donc de **doter l'entreprise d'une gestion commerciale**, puis de remplacer Formevol pour le reste. Le contenu exact de Formevol n'est pas encore connu. **[décidé]**

Le logiciel est conçu pour être, à terme, **vendu à d'autres organismes de formation** (SaaS ou auto-hébergé). Premier client : CAP FORMATION. **[décidé]**

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**

1. Lot 1 en production rapidement : un CRM complet ([CDC] intégral), là où aucun outil commercial n'existe aujourd'hui.
2. Socle pensé dès le départ pour le TMS complet : aucun lot ultérieur ne doit imposer de refonte du modèle de données ni de seconde migration.
3. Multi-organismes (multi-tenant) dès le premier jour, avec isolation stricte des données.
4. Ouverture : tout logiciel externe se branche via des **connecteurs** (import, export, synchronisation) et une API. **[décidé]**
5. Sécurisé, évolutif, facile à maintenir et à mettre à jour.
6. Installation en **une seule ligne de commande**, notamment sur Proxmox. **[décidé]**

**Non-objectifs (lot 1)**

- Facturation, sessions, dossiers, Qualiopi, portails, LMS : lots suivants.
- Gestion des abonnements payants des organismes clients (facturation SaaS de l'éditeur) : plus tard.
- Migration Formevol : Formevol n'ayant pas de données commerciales, elle n'est pas sur le chemin critique du lot 1 (voir §9.3).
- Interface d'inscription en libre-service des organismes et routage par sous-domaine complet : la première installation tourne sur Proxmox pour un seul organisme (voir §8, 1a).
- Application mobile native : le web responsive suffit.

**Anti-objectifs (échec même si « ça marche »)**

- Un organisme peut voir, même une seule fois, des données d'un autre organisme.
- La saisie d'une action commerciale prend plus de 2 minutes ou demande une formation lourde. [CDC]
- Un lot ultérieur oblige à réécrire ou re-migrer les données du lot 1.
- Un connecteur est codé « en dur » dans le cœur métier au lieu d'être un adaptateur isolé.
- Une mise à jour peut casser une installation sans retour arrière possible.
- Un mot de passe ou secret par défaut existe dans une installation.

## 3. Contraintes

- Interface **en français** ; dates, montants et formats français (séparateur CSV `;`, Excel). [CDC]
- **RGPD** : base légale, durée de conservation, droits d'accès/effacement, anonymisation, consentement. [CDC]
- Hébergement **en France**. [CARTO §11]
- Utilisable sur **ordinateur et mobile** (saisie après rendez-vous). [CDC]
- Volumétrie : quelques centaines à quelques dizaines de milliers de contacts par organisme sans dégradation.
- Le futur éditeur devra fournir une **attestation NF203 / anti-fraude TVA** pour la facturation (lot 3) : la conception de la facturation devra le permettre.
- Qualiopi : référentiel 2026 tel que décrit dans [CARTO §5] ; intitulés à vérifier sur le texte officiel avant le lot 4.

## 4. Ordre de construction (lots)

Ordre **révisé** par rapport à [CARTO §14] : le CRM passe en tête. **[décidé]**

| Lot | Contenu | Source |
| --- | --- | --- |
| **1 – Socle + CRM** | Socle technique (multi-tenant, utilisateurs, rôles, 2FA, audit, sauvegardes, connecteurs, installateur) ; gestion commerciale complète ; catalogue simplifié ; import CSV ; tableaux de bord commerciaux | [CDC] + [CARTO §6] |
| **2 – Opérationnel** | Sessions, dossiers, apprenants, plannings, moteur de documents, émargement natif + connecteur SoWeSign, transformation devis gagné → dossier | [CARTO §3, 4, 8] |
| **3 – Gestion** | Facturation, échéanciers, règlements, relances, NF203, export comptable (FEC, Sage…), BPF, moteur d'évènements, signature électronique | [CARTO §8, 9] |
| **4 – Qualité et pédagogie** | 33 indicateurs Qualiopi, quiz, questionnaires, registres, dossier d'audit, RGPD complet | [CARTO §5, 7] |
| **5 – Ouverture** | Portails apprenant/client/formateur, LMS, campagnes, API publique, paiement en ligne, IA | [CARTO §7, 11, 12] |

Le lot 1 est livré en **incréments utilisables** (voir §8), pour que CAP FORMATION puisse basculer de Formevol le plus tôt possible.

## 5. Architecture technique

### 5.1 Stack **[choix Claude, validé par Sébastien]**

| Couche | Choix |
| --- | --- |
| Interface | React + TypeScript, Tailwind CSS, bibliothèque de composants accessible (ex. shadcn/ui) **[builder pour la bibliothèque]** |
| Liaison front/back | Inertia.js (pas d'API séparée pour l'interface interne) |
| Serveur | Laravel 12 (PHP 8.4) |
| Base de données | PostgreSQL 17 (extensions `pg_trgm`, `unaccent` pour la recherche et les doublons) |
| Files et cache | Redis + workers Laravel (Horizon) |
| PDF / documents | Gotenberg (conversion .docx/HTML → PDF) ; utilisé dès le lot 1 pour les devis |
| Proxy / HTTPS | Caddy (certificats automatiques) |
| Conteneurs | Docker Compose : `app`, `worker`, `scheduler`, `postgres`, `redis`, `gotenberg`, `caddy` |
| Tests | Pest (unitaires, fonctionnels), Playwright (parcours critiques) |
| CI | **[builder]** — par défaut GitHub Actions : tests, analyse statique (Larastan, ESLint), audit des dépendances, construction et signature de l'image |

### 5.2 Organisation du code **[choix Claude]**

- Monorepo unique. Code métier découpé **par module** (dossiers par domaine : `Crm`, `Catalogue`, `Core`, `Connectors`, puis `Sessions`, `Finance`, `Qualite`…), chaque module avec ses modèles, actions, politiques d'accès, tests.
- Un module ne lit pas directement les tables d'un autre : il passe par ses services publics ou par des évènements Laravel. Objectif : pouvoir ajouter les lots 2 à 5 sans toucher au CRM.
- Structure exacte des dossiers : **[builder]**.

### 5.3 Multi-organismes (multi-tenant) **[choix Claude]**

Deux niveaux distincts — à ne pas confondre :

- **Organisme (tenant)** : le client du logiciel (ex. CAP FORMATION). Isolation totale.
- **Société** : entité juridique *dans* un organisme (NDA, SIRET, numérotation, logo, modèles). Un organisme a 1..n sociétés. [CARTO §9 multi-société]

Mise en œuvre :

- **Une seule base**, colonne `tenant_id` sur toutes les tables métier.
- **Double protection** : (1) filtrage automatique côté Laravel (scope global obligatoire) ; (2) **Row-Level Security PostgreSQL** activée sur chaque table métier, la connexion applicative positionnant `app.tenant_id` par requête. Une requête sans organisme défini ne renvoie rien.
- Identification de l'organisme par **sous-domaine** (`cap.exemple.fr`) ou domaine personnalisé.
- Une installation auto-hébergée pour un seul organisme utilise le même code avec un seul tenant.
- **Tests d'isolation obligatoires** dans la CI pour chaque nouvelle table (un test qui échoue si une table métier n'a ni `tenant_id` ni politique RLS).
- Un rôle **Super-admin plateforme** (l'éditeur) crée et suspend les organismes ; il n'accède pas aux données métier sans procédure tracée **[choix Claude]**.

### 5.4 Listes paramétrables

Comme le logiciel sera vendu à d'autres organismes, les listes métier de [CDC] sont des **référentiels paramétrables par organisme**, pré-remplis pour CAP FORMATION : domaines d'activité (DAS), étapes du pipeline, types d'action, résultats d'action, origines de contact, motifs de perte, types d'établissement, secteurs, tailles, priorités, modes de financement, situations professionnelles. **[choix Claude]**

Les étapes système « Gagné » et « Perdu » ne sont pas supprimables (elles portent des règles).

## 6. Sécurité

Cible : **OWASP ASVS niveau 2**. **[choix Claude]**

- Mots de passe : Argon2id, politique de longueur minimale (12), vérification contre les mots de passe compromis.
- **Authentification lot 1 = comptes locaux** **[choix Claude]**. Le SSO authentik/OIDC de [CARTO §11] n'est pas dans le lot 1 mais le modèle de compte le prévoit : table d'identités externes (fournisseur + identifiant) liée à l'utilisateur, pour ajouter un connecteur OIDC par organisme plus tard sans refonte.
- **Double facteur TOTP** : obligatoire pour administrateurs et direction, activable pour tous ; l'administrateur de l'organisme peut l'imposer à tous. Codes de secours.
- Sessions : cookies `Secure`, `HttpOnly`, `SameSite=Lax`, expiration d'inactivité paramétrable ; déconnexion des autres sessions.
- Protection CSRF, en-têtes de sécurité (CSP stricte, HSTS), limitation de débit sur connexion, API et import.
- Droits par module et par action (rôles + permissions par organisme) ; filtrage des listes « mes fiches » possible. [CARTO §11]
- **Journal d'audit** : connexions, échecs, changements de droits, création/modification/suppression des fiches (avant/après), exports, imports. Non modifiable depuis l'interface.
- Secrets des connecteurs **chiffrés** en base (clé hors base).
- Pièces jointes stockées hors de la racine web, servies via contrôle d'accès ; types et tailles limités.
- **Sauvegardes** : base + fichiers, quotidiennes, **chiffrées**, copiées hors du serveur (S3 compatible ou autre serveur via restic **[builder pour l'outil]**), rétention paramétrable, **test de restauration documenté**.
- Mises à jour de sécurité des dépendances : audit automatique en CI.

## 7. Modèle de données (socle, pensé pour tout le TMS)

Seules les entités du lot 1 sont créées maintenant, mais leur forme anticipe les lots suivants.

| Entité | Rôle | Remarques d'anticipation |
| --- | --- | --- |
| Organisme (tenant) | Client du logiciel | Paramètres, domaine, statut |
| Société | Entité juridique de l'organisme | Raison sociale, **forme juridique** (dont **SCOP** — CAP FORMATION est une SCOP), capital (fixe ou **variable**), RCS, SIRET, régime de TVA, mentions légales générées automatiquement sur les documents (ex. « SCOP SARL à capital variable ») ; recevra NDA, Qualiopi, banque, numérotation (lot 3) |
| Utilisateur | Compte interne | Rattaché à un organisme ; rôles ; DAS suivis |
| **Organisation** | Personne morale : entreprise cliente/prospect ; plus tard OPCO, financeur, sous-traitant | Champ `types` multiple ; SIRET, NAF, **forme juridique** (liste paramétrable : SARL, SAS, SA, EURL, EI, **SCOP**, SCIC, association, collectivité, établissement public…, préremplie par SIRET quand possible), secteur, type d'établissement, taille, adresse, site web, DAS, statut (prospect / client / inactif), origine |
| **Personne** | Personne physique unique : particulier prospect, interlocuteur d'entreprise, et plus tard apprenant, formateur | **Une seule table** pour éviter les doublons entre CRM et apprenants ; champs particulier : projet, situation professionnelle, financement envisagé |
| Rattachement | Personne × Organisation | Fonction, rôle dans la décision (décideur, prescripteur, utilisateur, financeur…), coordonnées pro, référent, dates |
| Domaine d'activité (DAS) | Référentiel | 4 DAS de CAP FORMATION pré-remplis |
| Formation (programme) | Catalogue | Lot 1 : intitulé, code, durée (heures/jours), public, tarif entreprise, tarif particulier, financements possibles, DAS. Deviendra le **Programme** complet de [CARTO §3] (versions, Qualiopi) sans changer de table |
| **Opportunité** | Affaire en cours | Compte = Organisation **ou** Personne (particulier) ; cible dérivée (entreprise / particulier) ; DAS ; formations (1..n) ; montant estimé ; probabilité ; montant pondéré calculé ; date de clôture prévue ; priorité ; étape ; responsable ; motif de perte ; dates de création, de gain/perte ; interlocuteurs liés |
| Historique d'étapes | Chaque changement d'étape horodaté | Sert au calcul du cycle de vente et aux statistiques |
| **Action** | Échange ou tâche | Type, date, durée, résultat, compte-rendu, prochaine relance (date + type), responsable ; liée à Organisation, Personne et/ou Opportunité ; statut fait / à faire |
| **Devis** | Proposition commerciale | Société émettrice, destinataire, contact, référence auto, date, validité, lignes (formation, quantité, prix, remise, TVA), totaux HT/TVA/TTC, statut (brouillon, envoyé, relancé, accepté, refusé, expiré), date d'envoi ; lié à une opportunité ; deviendra la source du Dossier (lot 2) |
| Formation suivie | Historique client (fidélisation) | Lot 1 : saisie manuelle ou import (formation, date, satisfaction, besoins futurs) ; remplacé/alimenté par les vraies participations au lot 2 |
| Objectif commercial | Cible de CA | Par période (mois, trimestre, année), par DAS, par commercial |
| Pièce jointe | Fichier lié à toute fiche | Stockage contrôlé |
| Consentement / base légale | RGPD | Par personne : base légale, consentements (mail, SMS, prospection), date, source |
| Journal d'audit | Sécurité | Voir §6 |
| Connecteur, Exécution de connecteur | Couche connecteurs | Voir §10 |
| Lot d'import | Traçabilité des imports | Permet l'annulation d'un import |
| Champ personnalisé | Extensibilité | Définition par organisme et par type de fiche ; valeurs typées **[choix Claude]** |

Toutes les tables métier : `tenant_id`, horodatages, auteur de création/modification, suppression logique (corbeille) sauf mention contraire.

## 8. Lot 1 — spécification fonctionnelle

Incréments livrables dans cet ordre **[choix Claude]** ; chacun utilisable en production.

### 1a – Socle

- **Première installation : Proxmox, un seul organisme (CAP FORMATION)**. **[décidé]** Priorité du 1a : l'**installateur Proxmox en une ligne** (§11) avec sauvegarde/restauration et mise à jour avec retour arrière. L'isolation multi-organismes (tenant_id + RLS + tests) est construite dès le 1a ; en revanche l'écran super-admin de création d'organismes et le routage par sous-domaine peuvent rester minimaux (organisme créé par l'installateur) et sont complétés avant la première vente.
- Création d'organisme, de sociétés, d'utilisateurs ; invitation par mail ; 2FA ; rôles.
- Rôles du lot 1 : Super-admin plateforme, Administrateur d'organisme, Direction (lecture complète + pilotage), Commercial (CRM complet sur ses fiches + lecture des autres, paramétrable), Assistant(e) (saisie fiches et actions, pas de suppression ni d'export massif), Responsable de DAS (lecture et pilotage filtrés sur ses DAS), Chargé(e) de relations entreprises (comme Commercial). Droits fins ajustables par organisme.
- Paramétrage des référentiels (§5.4), journal d'audit consultable par l'administrateur, sauvegardes, installateur (§11).

### 1b – Fiches et import

- Fiches Entreprise, Particulier, Interlocuteur selon [CDC « Gestion des contacts et comptes »].
- Préremplissage par SIRET via l'API publique Recherche d'entreprises / SIRENE **[builder pour l'API exacte, à vérifier]** ; saisie manuelle toujours possible.
- **Fil chronologique** sur chaque fiche : actions, changements d'étape, devis, notes, pièces jointes.
- **Détection des doublons** à la création et à l'import : SIRET identique, e-mail identique, téléphone normalisé identique, nom + code postal proches (similarité trigrammes). Écran de **fusion** de deux fiches avec choix champ par champ, historique conservé.
- Recherche globale rapide (nom, e-mail, téléphone, SIRET, ville).
- Listes avec filtres, tri, **filtres enregistrés**, colonnes choisies, **export Excel (.xlsx)** des résultats filtrés (journalisé).
- **Import CSV** générique (voir §9).

### 1c – Pipeline et actions

- Étapes par défaut : Nouveau, Contacté, Étude, Proposition, Négociation, Gagné, Perdu. Probabilité par défaut par étape, modifiable par opportunité.
- Vue **kanban** (glisser-déposer entre étapes) et vue liste ; filtres par DAS, cible, responsable, priorité, période.
- Passage à « Perdu » : **motif de perte obligatoire**. Passage à « Gagné » : date de signature enregistrée.
- Distinction entreprise / particulier : filtre et statistiques séparés ; durées de cycle séparées ; pour le particulier, le **mode de financement** est mis en avant sur la fiche opportunité.
- Durée du cycle de vente = date de gain − date de création.
- Actions : types [CDC] (prise de RDV, rappel, envoi de nouveauté, suivi, RDV physique, envoi de devis) + appel, e-mail, note ; résultat (réussi, échec, en attente, à replanifier) ; prochaine relance.
- **Saisie rapide** : bouton « + action » accessible partout, formulaire court, pré-rempli depuis la fiche courante ; écran mobile « compte rendu de RDV » (action + prochaine relance + étape en un seul formulaire). Cible : moins de 2 minutes. [CDC]
- **Relances** : écran « À faire » (aujourd'hui, en retard en rouge, cette semaine) ; badge de compteur dans le menu ; mail récapitulatif quotidien optionnel.
- **Planning de la semaine** : agenda des RDV et appels par utilisateur.
- **Synchronisation Outlook** **[choix Claude]** : connexion Microsoft 365 par utilisateur (Microsoft Graph, OAuth) pour **pousser** les RDV et relances du TMS dans le calendrier Outlook ; en alternative, un **flux iCal** (abonnement .ics privé par utilisateur) compatible avec tout agenda. Synchronisation dans l'autre sens : hors lot 1.

### 1d – Catalogue et devis

- Catalogue de formations [CDC « Offre de formation »] rattachées aux DAS.
- Une opportunité porte 1..n formations ; le montant estimé peut être calculé depuis les tarifs (entreprise ou particulier selon la cible) puis ajusté.
- Devis créé depuis une opportunité : lignes depuis les formations, totaux HT/TVA/TTC, **PDF** généré depuis un modèle par société (logo, mentions), validité ; numérotation par société.
- **TVA** **[choix Claude]** : paramètre par société « exonérée de TVA (art. 261-4-4° a du CGI) » ou « assujettie ». Exonérée → lignes à 0 %, mention légale imprimée sur le PDF ; assujettie → taux par ligne (20 % par défaut). Statut réel de CAP FORMATION à saisir au paramétrage.
- Multi-société en lot 1 : une société par défaut ; écrans multi-société réduits au strict nécessaire (choix de la société émettrice sur le devis, numérotation et modèle PDF par société).
- Statuts : brouillon, envoyé, relancé, accepté, refusé, expiré (automatique à la date de validité).
- Lien pipeline **[choix Claude]** : envoi du devis → l'opportunité passe au moins à « Proposition » ; devis accepté → proposition de passer l'opportunité à « Gagné » ; devis refusé → proposition de passer à « Perdu » (motif demandé). L'utilisateur confirme.
- Envoi du devis par e-mail depuis le TMS (SMTP de l'organisme), archivé dans le fil.
- Taux de réussite des devis (acceptés / envoyés) par période.

### 1e – Fidélisation et tableaux de bord

- Fiche client : formations déjà suivies, satisfaction, besoins futurs.
- **Rappels de réactivation** créés automatiquement à J+6 mois et J+12 mois après une formation suivie (délais paramétrables) sous forme d'actions « à faire ».
- Taux de rétention et de réachat (clients ayant au moins deux formations sur une période glissante paramétrable).
- **Règles de calcul** **[choix Claude]** :
  - **CA signé** = total HT du devis accepté lié à l'opportunité ; à défaut, **montant final** saisi (obligatoire) au passage à « Gagné ». Date de référence = date de gain.
  - **CA prévisionnel** = somme des montants estimés des opportunités ouvertes ; **pipeline pondéré** = somme montant × probabilité.
  - **CA par DAS** = calculé **par ligne** (formation de l'opportunité ou ligne de devis), chaque formation portant son DAS ; le DAS de l'opportunité est son DAS principal (filtres, affectation). Une opportunité multi-DAS compte une fois en nombre de dossiers, dans son DAS principal.
  - **Lead qualifié** = organisation ou particulier dont une opportunité atteint l'étape « Contacté » pendant la période (seuil d'étape paramétrable).
  - **Taux de conversion** = opportunités gagnées / opportunités créées sur la période (par date de création).
- **Tableaux de bord** : les 12 indicateurs de [CDC « Tableaux de bord et indicateurs »], chacun avec sa **formule et sa période affichées**, filtrables par DAS, cible, commercial, période (mois, trimestre, année), comparaison aux **objectifs** de CA, export Excel et PDF.
- Accueil personnalisé : relances du jour, opportunités à clôturer ce mois, pipeline pondéré, objectifs.

### RGPD (transverse au lot 1)

- Base légale par personne (intérêt légitime pour la prospection B2B, consentement pour la prospection des particuliers) et registre des consentements.
- Durée de conservation par catégorie, paramétrable ; par défaut **3 ans après le dernier contact** pour un prospect sans relation contractuelle (recommandation CNIL) **[choix Claude]**, puis **anonymisation automatique** (tâche planifiée, journalisée, avec liste préalable consultable par l'administrateur).
- Export des données d'une personne (droit d'accès) en un clic ; effacement/anonymisation sur demande ; opposition à la prospection.

## 9. Import et migration

### 9.1 Import générique (lot 1)

- Formats : CSV (séparateur `;` par défaut, détection `,` et tabulation ; encodage UTF-8 et Windows-1252 détectés) et Excel (.xlsx).
- Types importables : organisations, personnes, rattachements, opportunités, actions (historique d'échanges), formations suivies, catalogue.
- Étapes : dépôt du fichier → **correspondance des colonnes** (assistée par les noms) → correspondance des valeurs de listes (ex. « Salon » → origine « Salon ») → **simulation** (aperçu, erreurs ligne par ligne, doublons détectés) → choix de stratégie pour les doublons (ignorer, fusionner, créer) → import en tâche de fond → **rapport** téléchargeable.
- Les correspondances sont enregistrables comme **profil d'import** réutilisable.
- Chaque import est un **lot annulable** (suppression de tout ce qu'il a créé, tant que ces fiches n'ont pas été modifiées depuis).
- Conservation de l'**identifiant d'origine** (système source + id) sur chaque fiche importée, pour les imports successifs (mise à jour au lieu de doublon).

### 9.2 Export

Export Excel de toutes les listes ; export complet des données d'un organisme (archive) pour la réversibilité — argument commercial et obligation de fait pour un SaaS. **[choix Claude]**

### 9.3 Formevol

Formevol est l'outil actuel de CAP FORMATION ; il **ne contient pas de gestion commerciale**. Son contenu exact est inconnu à ce jour : la migration Formevol sera rattachée au lot dont elle concerne les données (probablement lot 2 : organisations, apprenants, sessions, dossiers). Le lot 1 démarre donc par une saisie neuve et l'import CSV générique (fichiers Excel, contacts dispersés). Quand l'export Formevol sera fourni, un **profil d'import « Formevol »** (correspondances + éventuelles transformations) sera ajouté **sans modifier le moteur d'import**. Prévoir une **migration à blanc** sur une copie, validée par l'utilisateur, avant la bascule réelle.

## 10. Connecteurs et ouverture **[décidé : principe ; choix Claude : forme]**

- Le TMS est **la source de vérité**. Les logiciels externes se branchent via des **adaptateurs** isolés, de trois types : **import**, **export**, **synchronisation** (deux sens).
- Chaque adaptateur : activation et paramétrage par organisme (et par société si utile), identifiants chiffrés, bouton « tester la connexion », **journal des exécutions** (quoi, quand, résultat, erreur), relance manuelle d'un envoi échoué.
- **Webhooks sortants** paramétrables sur évènements (opportunité gagnée, devis accepté…), signés.
- **API REST** documentée (OpenAPI), authentifiée par jetons par organisme avec droits limités. Lot 1 : lecture/écriture organisations, personnes, opportunités, actions. Étendue aux lots suivants.

Adaptateurs prévus :

| Adaptateur | Type | Lot |
| --- | --- | --- |
| CSV / Excel générique | Import | 1 |
| Formevol | Import (profil) | 1 (dès documentation) |
| Recherche d'entreprises / SIRENE | Préremplissage | 1 |
| Microsoft 365 / Outlook, flux iCal | Synchronisation (sens TMS → agenda) | 1 |
| SMTP de l'organisme | Envoi de mails | 1 |
| SoWeSign | Synchronisation (sessions/apprenants → SoWeSign ; présences et documents signés ← SoWeSign) | 2 ; utilisé en parallèle de l'émargement natif, activable ou non |
| FEC | Export comptable légal | 3 |
| Sage (100, 50 : fichier d'import ; Business Cloud : API) | Export comptable | 3 — version exacte de Sage à préciser |
| EBP, Cegid/Quadratus, ACD, Pennylane | Export comptable | 3, à la demande |
| Signature électronique, SMS, Mailjet, Stripe, LMS Learncraft, Nextcloud, liste publique des OF, RNCP/RS | Divers | 2 à 5, voir [CARTO §12] |

## 11. Installation, mise à jour, exploitation **[décidé : une ligne ; choix Claude : détail]**

- **Proxmox** : une commande à lancer sur le nœud :
  `bash -c "$(curl -fsSL https://<domaine-de-distribution>/install/proxmox.sh)"`
  Questions interactives avec valeurs par défaut (domaine, e-mail admin, CPU, RAM, disque, stockage Proxmox, réseau). Crée un **LXC Debian 12 non privilégié** avec `nesting=1`, installe Docker, télécharge la **version publiée** de l'image (empreinte vérifiée), **génère tous les secrets**, démarre la stack, crée l'administrateur (2FA à l'activation), affiche l'URL. Mode non interactif possible via variables d'environnement.
- **VPS ou serveur Debian/Ubuntu** : même installateur sans l'étape LXC (`install.sh`).
- **Mise à jour en une ligne** : sauvegarde → (snapshot Proxmox si disponible) → téléchargement de la nouvelle version → migrations → redémarrage → vérification de santé → **retour arrière automatique** en cas d'échec.
- Les scripts sont lisibles, servis en HTTPS, versionnés avec le code.
- Image Docker construite et **publiée par la CI** à chaque version (versionnage sémantique, notes de version). Registre **[builder]**.
- Point de santé (`/health`), journaux centralisés par conteneur, alerte mail en cas d'échec de sauvegarde.
- Hébergement recommandé pour le SaaS : VPS en France (OVHcloud, Scaleway). o2switch mutualisé **n'est pas** compatible (pas de Docker ni de workers permanents). **[choix Claude, validé]**

## 12. Qualité du code et maintenance

- Tests automatisés obligatoires pour : isolation multi-organismes, droits, règles du pipeline (motif de perte, calculs), import (doublons, annulation), calcul des indicateurs.
- Analyse statique PHP et TypeScript en CI ; formatage automatique.
- Migrations de base versionnées et réversibles quand c'est possible.
- Documentation : guide d'installation, guide d'administration, dictionnaire des données, guide « ajouter un connecteur ».
- Données de démonstration (seed) réalistes pour CAP FORMATION (4 DAS, référentiels du [CDC]).

## 13. Laissé au builder

- Bibliothèque de composants UI, choix des icônes, design détaillé (sobre, lisible, dense en information pour un usage quotidien).
- Structure exacte des dossiers et conventions de nommage.
- Outil de sauvegarde (restic ou équivalent), registre d'images, plateforme de CI.
- API exacte de préremplissage SIRET (à vérifier : API Recherche d'entreprises de l'État).
- Bibliothèque de génération Excel et de graphiques.

## 14. Points ouverts (non bloquants pour démarrer le lot 1)

| Point | Bloque | Défaut en attendant |
| --- | --- | --- |
| Date butoir de mise en production du lot 1 | Planning | Incréments 1a → 1e livrés au plus tôt |
| Contenu et export Formevol | Profil Formevol (probablement lot 2) | Import générique |
| Messagerie : Microsoft 365 confirmé ? | Connecteur Outlook 1c | Flux iCal |
| Version exacte de Sage | Lot 3 | FEC |
| SoWeSign : usage actuel et maintien | Lot 2 | Émargement natif + adaptateur optionnel |
| Durées de conservation RGPD définitives | Paramétrage | 3 ans après dernier contact |
| Objectifs chiffrés de CA par DAS | Saisie des objectifs | Saisis par l'administrateur dans l'outil |
| Nom commercial du produit et domaine de distribution | Installateur, marque | Nom de code provisoire |
| Texte officiel du décret Qualiopi 2026-728 | Lot 4 | Résumés de [CARTO §5] |
