# Journal des modifications

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions selon
[SemVer](https://semver.org/lang/fr/).

## [Non publié]

## [0.3.1] - 2026-11-14

Première publication installable (Proxmox / serveur) ; la 0.3.0 n'a pas pu être publiée.

### Corrigé

- Interface en français même sans `APP_LOCALE` dans la configuration.
- Pages trouvées sur les systèmes sensibles à la casse (serveurs Linux).
- Ordre des exécutions de connecteurs stable quand deux exécutions démarrent à la même seconde.
- Intégration continue : tests, vérification des types et audit des dépendances fiables sur GitHub.

## [0.3.0] - 2026-11-14

Lot 1c : pipeline commercial, actions et relances, planning, agenda.

### Ajouté

- **Opportunités** sur une entreprise ou un particulier : objet, DAS, étape, montant estimé et **montant pondéré**,
  clôture prévue, priorité, mode de financement, interlocuteurs, responsable. Liste filtrable avec totaux et
  **export Excel**.
- **Étapes du pipeline** paramétrables avec leur probabilité : la probabilité suit l'étape (une saisie manuelle est
  conservée au prochain changement d'étape, puis l'étape reprend la main), « Gagné » demande la date de gain, « Perdu » **exige un motif de perte**.
  Chaque changement est tracé dans l'historique de la fiche.
- **Pipeline (kanban)** : une colonne par étape avec nombre, montant et pondéré ; glisser-déposer à la souris ou au
  clavier ; affaires gagnées et perdues des 90 derniers jours ; liste par étape sur téléphone.
- **Actions** (appel, rendez-vous, e-mail, rappel…) et **saisie rapide « + Action »** depuis n'importe quel écran du
  CRM, préremplie avec la fiche ouverte : fait maintenant ou à faire, résultat, compte rendu, **prochaine relance**
  en un clic (demain, dans une semaine, dans un mois). Les actions faites mettent à jour le dernier contact.
- **« À faire »** : vos actions en retard, du jour et de la semaine (jours à l'heure de Paris), « Fait »,
  « Reporter », vue **Équipe** pour la direction ; compteur dans le menu.
- **Récapitulatif quotidien** par e-mail à 7 h 30, sur demande (profil).
- **Planning de la semaine** (rendez-vous et appels planifiés, jour par jour sur téléphone, planning d'un collègue
  pour la direction) ; les types d'action concernés se règlent dans les référentiels (« Planning commercial »).
- **Compte rendu de RDV** au téléphone en un seul formulaire : résultat, compte rendu, étape de l'opportunité et
  prochaine relance enregistrés ensemble.
- **Mon agenda** : lien d'abonnement **iCal** privé (Outlook, Google Agenda, Apple Calendrier) et, avec Microsoft
  365, **envoi direct des rendez-vous dans Outlook** (connecteur à déclarer dans Azure, voir `docs/connectors.md`).
  Outlook suit aussi la fiche et l'opportunité (corbeille, restauration, fusion) ; « Déconnecter Outlook » retire
  de l'agenda les rendez-vous envoyés, et une reconnexion ne crée pas de doublon.
- **Accueil** : « Mes relances » (en retard / aujourd'hui), **pipeline pondéré du mois**, **prochains
  rendez-vous**, dans le périmètre de chacun.
- Menu **Commercial** réorganisé : Pipeline, Opportunités, À faire, Planning, Compte rendu de RDV, Entreprises,
  Personnes, Imports, RGPD.
- Fusion, export RGPD et anonymisation couvrent les opportunités et les actions.
- Documentation : guide utilisateur (pipeline, relances, planning, compte rendu, agenda), guide d'administration,
  dictionnaire de données. Tests de bout en bout du parcours pipeline et du compte rendu sur téléphone.

### Modifié

- L'historique d'une fiche se pagine sans perte ni doublon, même pour des évènements à la même microseconde.
- Les fiches sont lisibles sur un téléphone de 375 px sans défilement horizontal (fil d'Ariane raccourci).
- L'export des personnes propose aussi le système source, l'identifiant source et la base légale.
- Les exports Excel écrivent montants, probabilités et dates comme de vrais nombres et de vraies dates (format
  français) : ils s'additionnent, se trient et se filtrent dans Excel.

### Sécurité et fiabilité

- **Imports** : surveillance des imports bloqués (reprise automatique une fois, puis échec signalé), une ligne
  refusée par la base passe en erreur sans arrêter l'import, contrôle du contenu réel des fichiers déposés, nom du
  fichier d'origine masqué au journal et remplacé au bout de 30 jours.
- **Connecteurs** : les connexions sortantes (Sirene, Microsoft, SMTP) sont attachées aux adresses vérifiées
  (protection contre la redirection DNS).
- **Pièces jointes** des fiches en corbeille depuis plus de 12 mois effacées du stockage (« Fichier supprimé »).
- Lien iCal : jeton aléatoire dont seule l'empreinte est stockée, limité en nombre d'appels ; jetons Microsoft
  chiffrés, jamais journalisés.
- Responsable DAS : opportunités, actions et chiffres de l'accueil limités à ses DAS.
- Test de volume : recherche sur 50 000 fiches en moins d'une seconde sous isolation des organismes.

## [0.2.0] - à publier

Lot 1b : fiches commerciales, import, export et RGPD.

### Ajouté

- **Entreprises** et **Personnes** : saisie rapide (un seul champ obligatoire), particuliers et interlocuteurs,
  rattachements avec fonction et rôle dans la décision, DAS, responsable, corbeille et restauration, interface
  utilisable sur téléphone.
- **Historique** de chaque fiche : notes, pièces jointes (20 Mo) et modifications, avec dernier contact.
- **Recherche** : recherche globale (Ctrl K) insensible aux accents, filtres de liste, filtres enregistrés
  (privés ou partagés), choix des colonnes.
- **Doublons et fusion** : avertissement à la saisie, fusion champ par champ avec conservation de l'historique
  et des rattachements ; jamais de comparaison entre organismes.
- **Complétion par SIRET** depuis l'annuaire officiel des entreprises (connecteur Sirene) ; contrôle de la clé
  du SIRET.
- **Import** CSV / Excel pas à pas (entreprises, personnes, interlocuteurs, notes) : détection de l'encodage
  et du séparateur, association des colonnes et des valeurs, simulation sans écriture, stratégie face aux
  doublons, rapport Excel, profils réutilisables, **annulation** qui préserve le travail des utilisateurs.
- **Export Excel** des listes filtrées, tracé au journal d'audit, protégé contre l'injection de formules.
- **RGPD** : base légale, consentements datés, opposition à la prospection, export des données d'une personne,
  durée de conservation paramétrable (3 ans par défaut), liste « À anonymiser », anonymisation irréversible
  automatique après 30 jours, e-mail de synthèse aux responsables RGPD.
- **Droits CRM** par rôle (huit permissions), visibilité limitée aux DAS suivis pour le Responsable DAS.
- **Gestion des utilisateurs** : changement de rôle, désactivation / réactivation, renvoi et révocation
  d'invitation, garde-fou « au moins un administrateur actif ».
- **Accueil** : raccourcis (nouvelle entreprise, nouveau particulier, recherche), compteurs et cinq dernières
  fiches consultées.
- Documentation : guide utilisateur `docs/crm.md`, dictionnaire de données et guide d'administration mis à jour.
- Tests de bout en bout du parcours de fiches (création, interlocuteur, note, doublon et fusion, recherche,
  export, affichage mobile).

### Modifié

- Le fuseau horaire de la session PostgreSQL suit celui de l'application (Europe/Paris).
- Les messages d'erreur affichés à l'utilisateur sont tous en français ; seuls les messages prévus pour lui
  sont montrés, les autres erreurs affichent un texte fixe.

### Sécurité

- Les valeurs des données personnelles n'apparaissent jamais en clair dans le journal d'audit.
- Fichiers des organismes rangés par organisme dans un stockage privé ; adresses appelées par les connecteurs
  filtrées (adresses internes refusées).

## [0.1.0] - à publier

Lot 1a : socle de l'application.

### Ajouté

- Socle Laravel 12 / Inertia / React : authentification, rôles et permissions, double authentification
  obligatoire pour l'administrateur et la direction, mots de passe Argon2id.
- Multi-organismes : isolation par `tenant_id` et sécurité au niveau des lignes PostgreSQL (rôle applicatif
  non propriétaire), mode « un organisme » ou plateforme partagée.
- Journal d'audit en ajout seul, référentiels paramétrables par organisme, interface en français.
- Socle des connecteurs externes (adaptateurs isolés, secrets chiffrés).
- Exploitation : image de production, `compose.prod.yaml` (Caddy, PostgreSQL, Redis, Horizon, Gotenberg),
  installateur en une ligne (Proxmox LXC et Debian), sauvegardes restic quotidiennes, restauration,
  mise à jour avec retour arrière automatique.
- Publication : image multi-architecture signée (cosign), archive de version, sommes de contrôle.

[Non publié]: https://github.com/sebvtl728/CRMTMS/compare/v0.3.1...HEAD
[0.3.1]: https://github.com/sebvtl728/CRMTMS/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/sebvtl728/CRMTMS/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/sebvtl728/CRMTMS/releases/tag/v0.1.0
