# Journal des modifications

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions selon
[SemVer](https://semver.org/lang/fr/).

## [Non publié]

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

[Non publié]: https://github.com/OWNER/REPO/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/OWNER/REPO/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/OWNER/REPO/releases/tag/v0.1.0
