# Journal des modifications

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) ; versions selon
[SemVer](https://semver.org/lang/fr/).

## [Non publié]

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

[Non publié]: https://github.com/OWNER/REPO/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/OWNER/REPO/releases/tag/v0.1.0
