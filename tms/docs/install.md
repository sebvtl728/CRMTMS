# Installation

## Développement

Prérequis : Docker avec Compose v2.

```bash
cd tms
cp .env.example .env
# Renseigner dans .env : DB_PASSWORD, DB_OWNER_PASSWORD, POSTGRES_SUPERUSER_PASSWORD,
# POSTGRES_APP_PASSWORD (= DB_PASSWORD), POSTGRES_OWNER_PASSWORD (= DB_OWNER_PASSWORD),
# HOST_UID / HOST_GID (id -u / id -g). Générer par exemple avec `openssl rand -hex 16`.
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose run --rm vite sh -c "npm ci && npm run build"
docker compose exec app php artisan migrate --database=pgsql_owner
```

Organisme par défaut (mode `TMS_MODE=single`, slug `TMS_DEFAULT_TENANT`) : `docker compose exec app php artisan db:seed`.
En mode `multi`, l'organisme est trouvé par le nom d'hôte (table `tenant_domains`).

Comptes : pas d'inscription publique ni de compte par défaut. Un utilisateur est créé par invitation
(`/admin/users`, lien d'activation à usage unique valable 24 h) ou par l'installateur (`tms:install`).
Un même e-mail peut exister dans plusieurs organismes ; chaque compte ne se connecte que sur l'hôte de son organisme.
Sessions : `SESSION_DRIVER=database` (révocation à la désactivation d'un compte) et `SESSION_DOMAIN` vide
(cookie limité à l'hôte, jamais partagé entre sous-domaines d'organismes).

Application : http://localhost:8080 — santé : http://localhost:8080/health

## Rôles PostgreSQL

| Rôle        | Usage                                            |
|-------------|--------------------------------------------------|
| `tms_owner` | propriétaire du schéma, migrations uniquement    |
| `tms_app`   | rôle applicatif (LOGIN, NOBYPASSRLS, non propriétaire) |

Les migrations se lancent toujours avec la connexion propriétaire :

```bash
docker compose exec app php artisan migrate --database=pgsql_owner
```

Les rôles et les bases `tms` / `tms_test` sont créés au premier démarrage du conteneur
`postgres` par `docker/postgres/init.sh` (mots de passe lus dans `POSTGRES_OWNER_PASSWORD`
et `POSTGRES_APP_PASSWORD`). Pour rejouer l'initialisation : `docker compose down -v`.

## Tests

```bash
docker compose exec app php artisan test
```

Les tests s'exécutent en tant que `tms_app` sur la base `tms_test` ; le schéma est recréé via
`pgsql_owner` (voir `tests/RefreshDatabaseAsOwner.php`). Les assets doivent avoir été
compilés (`npm run build`).

### Tests de bout en bout (Playwright)

Le test `tests/e2e/admin.spec.ts` rejoue le parcours d'un administrateur dans un vrai navigateur : lien
d'activation, mot de passe, enrôlement 2FA (code TOTP calculé avec `otplib`), création d'une société SCOP,
invitation d'un utilisateur, journal d'audit. Il enregistre la session de l'administrateur
(`tests/e2e/.auth/admin.json`, ignoré par git) que réutilise `tests/e2e/crm.spec.ts` : import d'un CSV de
3 lignes par l'assistant « Importer », fiches visibles dans la liste, annulation de l'import (les deux fichiers
se lancent donc ensemble, dans cet ordre).

Il s'exécute sur une pile dédiée (`compose.e2e.yaml` : application `app-e2e`, base `tms_e2e`, organisme `e2e`,
port **8081**, assets compilés) : la base et les données de développement ne sont jamais touchées. Prérequis :
la pile de développement démarrée (`docker compose up -d`, Colima inclus) et Node.js 20+ sur la machine hôte.

```bash
cd tms
npm install
npx playwright install chromium   # une seule fois : télécharge le navigateur (~100 Mo)
npm run e2e                       # compile les assets, recrée tms_e2e, installe l'organisme, lance le test
```

`tests/e2e/prepare.sh` (appelé par `tests/e2e/global-setup.ts`) démarre la pile e2e, recrée la base `tms_e2e`,
applique les migrations et exécute `tms:install` ; le lien d'activation obtenu est transmis au test. En cas
d'échec, la trace est dans `test-results/` (`npx playwright show-trace <trace.zip>`). Ces tests ne sont pas
encore exécutés par la CI GitHub (ils demandent la pile Docker complète) : suite à prévoir.

## Production : installation en une ligne

Deux scripts publiés avec chaque version : `proxmox.sh` (crée un conteneur LXC sur un nœud Proxmox VE et
y lance l'installation) et `install.sh` (installe sur une machine Debian 12 ou Ubuntu 24.04). Ils refusent
de s'exécuter sur une installation existante (`/opt/tms/.env` présent) : pour une nouvelle version, utiliser
`ops/update.sh`.

### Prérequis

- Proxmox VE 8 (accès root au nœud) **ou** une machine Debian 12 / Ubuntu 24.04 vierge (root).
- 2 cœurs, 4 Go de mémoire, 20 Go de disque (valeurs par défaut du conteneur).
- Un nom de domaine (ex. `tms.example.org`) qui pointe vers l'adresse de la machine, et les ports **80 et 443**
  joignables depuis Internet pour obtenir le certificat (voir « Réseau interne » sinon).
- L'e-mail de l'administrateur et le nom de l'organisme.

### Sur Proxmox VE (conteneur LXC)

Sur le nœud, en root :

```bash
bash -c "$(curl -fsSL https://github.com/<propriétaire>/<dépôt>/releases/latest/download/proxmox.sh)"
```

Les questions s'affichent (whiptail). Sans questions :

```bash
TMS_NONINTERACTIVE=1 TMS_DOMAIN=tms.example.org TMS_ADMIN_EMAIL=admin@example.org \
TMS_TENANT_NAME="CAP FORMATION" CT_IP=192.168.1.50/24 CT_GW=192.168.1.1 \
TMS_DIST_URL=https://github.com/<propriétaire>/<dépôt>/releases/latest/download \
  bash -c "$(curl -fsSL https://github.com/<propriétaire>/<dépôt>/releases/latest/download/proxmox.sh)"
```

Le script vérifie `pveversion`, prend le prochain identifiant libre (`pvesh get /cluster/nextid`), télécharge
le modèle Debian 12 s'il manque (`pveam`), crée un conteneur **non privilégié** (`--unprivileged 1
--features nesting=1,keyctl=1 --onboot 1`, nécessaire à Docker), le démarre, attend le réseau, puis y copie
`install.sh` (vérifié avec `SHA256SUMS`) et l'exécute. Aucun secret ne transite par le nœud : ils sont générés
dans le conteneur. Les paramètres passent par un fichier copié avec `pct push` (mode 600, supprimé ensuite),
jamais par la ligne de commande.

Pour mettre à jour un conteneur existant : `proxmox.sh update <CTID>` (voir « Mise à jour » ci-dessous).

### Sur Debian 12 / Ubuntu 24.04

En root :

```bash
TMS_DOMAIN=tms.example.org TMS_ADMIN_EMAIL=admin@example.org TMS_TENANT_NAME="CAP FORMATION" \
TMS_DIST_URL=https://github.com/<propriétaire>/<dépôt>/releases/latest/download \
  bash -c "$(curl -fsSL https://github.com/<propriétaire>/<dépôt>/releases/latest/download/install.sh)"
```

Étapes : installation de Docker (dépôt officiel) si absent → téléchargement de `SHA256SUMS` et de
`tms-<version>.tar.gz` → vérification de la somme de contrôle (arrêt si elle diffère) → extraction dans `/opt/tms`
→ génération de `/opt/tms/.env` → `docker compose pull` → démarrage de PostgreSQL/Redis et migrations
(`ops/migrate.sh`) → démarrage complet et attente de `/health` → `php artisan tms:install` (organisme,
administrateur, référentiels CAP FORMATION) → minuterie de sauvegarde → affichage de l'adresse, du lien
d'activation et des secrets à conserver.

### Variables

| Variable | Défaut | Rôle |
|---|---|---|
| `TMS_DOMAIN` | (obligatoire) | Nom de domaine servi (sans `https://`) |
| `TMS_ADMIN_EMAIL` | (obligatoire) | Administrateur ; aussi contact ACME et destinataire des alertes |
| `TMS_TENANT_NAME` | (obligatoire) | Nom de l'organisme |
| `TMS_ADMIN_NAME` | `Administrateur` | Nom affiché de l'administrateur |
| `TMS_TENANT_SLUG` | dérivé du nom | Identifiant de l'organisme (`TMS_DEFAULT_TENANT`) |
| `TMS_VERSION` | `latest` | Version à installer (`latest` : lue dans `SHA256SUMS`) |
| `TMS_DIST_URL` | releases GitHub | Dossier contenant les fichiers de la version |
| `TMS_IMAGE_OWNER` / `TMS_IMAGE` | propriétaire du dépôt | Image `ghcr.io/<propriétaire>/tms` |
| `TMS_SEED_CAP` | `1` | `0` : ne pas charger les référentiels CAP FORMATION |
| `TMS_TLS` | `acme` | `internal` : certificat de l'autorité locale de Caddy |
| `TMS_MODE` | `single` | `single` = un organisme ; `multi` = plateforme partagée (organisme trouvé par nom d'hôte) |
| `TMS_PRINT_SECRETS` | `1` | `0` : ne pas afficher les secrets, seulement leur emplacement |
| `TMS_NONINTERACTIVE` | `0` | `proxmox.sh` : `1` = pas de questions |
| `CTID`, `CT_HOSTNAME`, `CT_CORES`, `CT_RAM`, `CT_DISK`, `CT_STORAGE`, `CT_BRIDGE`, `CT_IP`, `CT_GW`, `TEMPLATE_STORAGE` | prochain libre, `tms`, `2`, `4096`, `20`, 1er stockage actif, `vmbr0`, `dhcp`, —, `local` | `proxmox.sh` : conteneur (`CT_GW` obligatoire si `CT_IP` est une adresse fixe CIDR) |

Tant que le dépôt GitHub n'est pas renseigné dans les scripts (`TMS_GITHUB_REPO`), `TMS_DIST_URL` et
`TMS_IMAGE_OWNER` sont obligatoires ; l'installateur s'arrête avec un message clair sinon.

### Nom de domaine, certificats et réseau interne

Caddy obtient le certificat automatiquement (Let's Encrypt) **uniquement** si le domaine pointe vers le serveur
et si les ports 80 et 443 sont joignables depuis Internet (redirection de ports sur le routeur si besoin).
Pour une installation accessible seulement sur le réseau local, utiliser `TMS_TLS=internal` : Caddy signe le
certificat avec sa propre autorité ; les navigateurs affichent un avertissement tant que cette autorité n'est
pas approuvée (certificat racine : `docker compose -f /opt/tms/compose.prod.yaml exec caddy cat
/data/caddy/pki/authorities/local/root.crt`). Le nom doit tout de même être résolu par le DNS local.

### Où sont les secrets

Tous les secrets sont générés sur la machine (aucune valeur par défaut) et écrits **uniquement** dans
`/opt/tms/.env` (mode 600, root) : `APP_KEY`, mots de passe PostgreSQL (super-utilisateur, propriétaire,
application), `REDIS_PASSWORD`, `RESTIC_PASSWORD`. Ils ne passent jamais en argument de commande et ne sont
écrits dans aucun journal.

**À conserver HORS du serveur : `RESTIC_PASSWORD` et `APP_KEY`.** L'installateur les affiche une seule fois à
la fin (à copier dans un gestionnaire de mots de passe) ; `TMS_PRINT_SECRETS=0` n'affiche que leur emplacement.
Sans `RESTIC_PASSWORD`, les sauvegardes sont illisibles ; sans `APP_KEY`, les secrets des connecteurs et les
doubles authentifications sont perdus.

### Sauvegardes

L'installateur crée `tms-backup.service` et `tms-backup.timer` (systemd) : `ops/backup.sh` chaque nuit à 02:30
(`Persistent=true` : rattrapée au démarrage si la machine était éteinte), dépôt `/var/backups/tms`. Vérifier :
`systemctl list-timers tms-backup.timer`, `journalctl -u tms-backup.service`. Pour une copie hors site, renseigner
`RESTIC_REMOTE_REPOSITORY` dans `.env`. Sur Proxmox, une sauvegarde `vzdump` du conteneur complète restic.

### Mise à jour

En root, dans le conteneur ou sur la machine :

```bash
/opt/tms/ops/update.sh                    # dernière version publiée
/opt/tms/ops/update.sh --version 1.4.0    # version précise
```

Tant que le dépôt de publication n'est pas renseigné dans les scripts (`TMS_GITHUB_REPO`), préfixer la commande par
`TMS_DIST_URL=<dossier des fichiers de la version>`.

Déroulement :

1. Téléchargement de `SHA256SUMS` et de `tms-<version>.tar.gz`, vérification de la somme de contrôle (la version
   « dernière » est déterminée une seule fois). Le programme de mise à jour **de la nouvelle version** (vérifié)
   prend alors le relais : les corrections du programme de mise à jour s'appliquent dès la mise à jour qui les
   apporte.
2. Même version : rien à faire (code 0), sauf `--force`. Version plus ancienne (ordre « semver » : `0.1.0-rc.1` <
   `0.1.0` < `0.1.1`) : refusée, sauf `--allow-downgrade` (les migrations ne se défont pas : pour revenir en arrière,
   préférer `ops/restore.sh` avec le snapshot étiqueté `pre-update-<version>`).
3. Nouveaux paramètres : les clés de la nouvelle `.env.prod.example` absentes de `.env` y sont ajoutées
   (`CLÉ=valeur` : valeur par défaut copiée ; `CLÉ=__GENERATE__` : secret aléatoire généré ; `CLÉ=` vide : rien
   n'est ajouté, paramètre facultatif). Téléchargement des images de la nouvelle version. Rien n'est encore modifié.
4. Arrêt de app/worker/scheduler (aucune écriture ne peut être perdue), puis sauvegarde restic étiquetée
   `pre-update-<ancienne version>`. Si la sauvegarde échoue, les services redémarrent et rien n'est modifié.
5. L'état de la mise à jour est noté dans `/opt/tms/.update-in-progress` ; fichiers et `.env` précédents gardés dans
   `/opt/tms/.bundle-previous` ; remplacement des fichiers (`compose.prod.yaml`, `ops/`, `docker/caddy`,
   `docker/postgres`, `docs/`), nouveau `.env`, migrations, redémarrage, puis `/health` doit répondre avec la
   nouvelle version sous 90 s : « Mise à jour vers X.Y.Z réussie ».

**Retour arrière automatique** : si une étape du point 5 échoue (ou en cas d'interruption : Ctrl-C, arrêt,
déconnexion SSH), les fichiers et le `.env` précédents sont remis, la base et `storage/app` sont restaurés depuis la
sauvegarde du point 4 (`ops/restore.sh`), la pile redémarre sur l'ancienne version ; code de sortie **1** et message
« Échec de la mise à jour : retour à <ancienne> ». Si le retour arrière échoue lui-même : code **2**, le message
donne l'identifiant du snapshot et la commande de restauration manuelle (`/opt/tms/ops/restore.sh <snapshot>`) ;
supprimer `/opt/tms/.update-in-progress` une fois la situation rétablie.

**Mise à jour interrompue brutalement** (coupure de courant, `kill -9`, `pct stop`, manque de mémoire) : le
fichier `/opt/tms/.update-in-progress` reste en place. Relancer simplement `/opt/tms/ops/update.sh` : il effectue
d'abord le retour arrière à partir de l'état noté (fichiers, `.env`, snapshot), puis s'arrête (code 1) ; relancer
ensuite la mise à jour. Si `/opt/tms/ops/update.sh` est absent (interruption pendant le remplacement des fichiers) :
`TMS_DIR=/opt/tms /opt/tms/.bundle-previous/ops/update.sh`. Si les services app/worker/scheduler restent arrêtés
sans ce fichier (interruption pendant la sauvegarde), rien n'a changé :
`docker compose -f /opt/tms/compose.prod.yaml --env-file /opt/tms/.env up -d`.

Chaque exécution ajoute son résultat à `/opt/tms/logs/update.log`.

Une mise à jour, une sauvegarde et une restauration ne peuvent pas tourner en même temps (verrou
`/opt/tms/.ops.lock`) : la mise à jour et la restauration refusent immédiatement ; la sauvegarde attend jusqu'à
15 minutes (`TMS_BACKUP_LOCK_WAIT`, en secondes).

**Sur Proxmox**, depuis le nœud (recommandé) :

```bash
proxmox.sh update <CTID> [--version 1.4.0]
```

Il prend d'abord un instantané du conteneur `pre-update-<date>` (`pct snapshot`, les 3 plus récents sont gardés ;
si le stockage ne gère pas les instantanés, un avertissement est affiché et la mise à jour continue, protégée par
la sauvegarde restic), puis lance `ops/update.sh` dans le conteneur et renvoie son code de sortie. En cas d'échec
(1 ou 2), le message rappelle le dernier recours : `pct rollback <CTID> <instantané>` puis `pct start <CTID>`
(rétablit tout le conteneur, y compris les données écrites depuis).

### Étapes suivantes

1. Ouvrir le lien d'activation affiché (valable 24 h, usage unique), choisir le mot de passe (12 caractères
   minimum) et enrôler la double authentification.
2. Configurer la messagerie de l'instance dans `/opt/tms/.env` (`MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT`,
   `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`), puis
   `docker compose -f /opt/tms/compose.prod.yaml --env-file /opt/tms/.env up -d`. Tant qu'elle ne l'est pas
   (`MAIL_MAILER=log`), les alertes de sauvegarde ne partent pas par e-mail.
3. Compléter la fiche société, inviter les utilisateurs.

### Récupérer l'accès administrateur

Lien d'activation expiré ou jamais reçu, 2FA perdue : voir [`admin.md`](admin.md#9-récupérer-laccès-administrateur).
En bref, depuis `/opt/tms` :

```bash
docker compose -f compose.prod.yaml --env-file .env exec app php artisan tms:user:activation-link <e-mail> [--role=admin_organisme] [--tenant=<identifiant>]
docker compose -f compose.prod.yaml --env-file .env exec app php artisan tms:user:reset-2fa <e-mail> [--tenant=<identifiant>]
```

### Installation interrompue

Si l'installation échoue après la création de `.env`, le message indique comment repartir de zéro :
`docker compose -f /opt/tms/compose.prod.yaml --env-file /opt/tms/.env down -v` puis supprimer `/opt/tms/.env`
(**efface la base** : uniquement sur une installation neuve). Sur Proxmox : `pct stop <CTID> && pct destroy <CTID>`.

### Contenu d'une version (contrat avec la publication)

Fichiers attachés à chaque version (workflow `.github/workflows/release.yml`, voir [`release.md`](release.md)) :
`tms-<version>.tar.gz`, `SHA256SUMS` (format `sha256sum`, liste **l'archive, `install.sh` et `proxmox.sh`**),
`SHA256SUMS.cosign.bundle` (signature cosign de `SHA256SUMS`), `install.sh`, `proxmox.sh`. Image :
`ghcr.io/<propriétaire>/tms:<version>` (`linux/amd64` et `linux/arm64`, signée ; `:latest` hors pré-version).

Contenu de l'archive (à la racine, noms triés, date et propriétaire fixes : reproductible) : la liste
`ops/release/files.txt`, **source unique** lue par `ops/release/make-bundle.sh` (publication et tests) :
`compose.prod.yaml`, `.env.prod.example`, `ops/{lib,migrate,backup,restore,update}.sh`, `docker/caddy/Caddyfile`,
`docker/postgres/init.sh`, `docker/postgres/init.sql`, `release.env` (généré : `TMS_IMAGE_DIGEST`, empreinte de
l'image de la version), `docs/install.md`. L'installateur et `ops/update.sh` recopient cette empreinte dans `.env`
(`TMS_IMAGE_DIGEST`) et `compose.prod.yaml` tire alors `…/tms:<version>@<empreinte>` (empreinte vérifiée par
Docker) ; vide (construction locale), le tag seul est utilisé. `fetch_bundle` (`ops/lib.sh`, recopié dans
`install.sh`) refuse une archive à laquelle manque l'un de ces fichiers ; `ops/tests/release.bats` vérifie que
les deux listes concordent : **ajouter un fichier à l'archive = l'ajouter à `files.txt`** (et à `fetch_bundle` s'il
est indispensable).

Nouveau paramètre : l'ajouter à `.env.prod.example` (valeur par défaut, ou `__GENERATE__` pour un secret). Le
programme de mise à jour de la version précédente lance `ops/update.sh` de la nouvelle avec
`TMS_UPDATE_REEXEC=1 … --version <version> --bundle-dir <dossier>` : cette interface doit rester stable.

### Validation manuelle sur Proxmox (à réaliser à chaque version)

Sur un nœud de test : `TMS_DIST_URL=<release de test> bash -c "$(curl -fsSL <release de test>/proxmox.sh)"`.
Attendu : conteneur créé, `https://<domaine>/health` → `{"status":"ok",…}`, lien d'activation affiché,
connexion de l'administrateur et enrôlement 2FA réussis, `systemctl list-timers` dans le conteneur montre
`tms-backup.timer`.

### Installation manuelle (Docker Compose)

L'image de production est construite par la cible `prod` de `docker/app.Dockerfile`
(assets Node 22, dépendances PHP sans outils de développement, utilisateur non root,
`APP_VERSION` fixé à la construction). Les caches Laravel (config, routes, événements,
vues) sont générés au démarrage du conteneur par `docker/entrypoint.sh`.

```bash
docker build -f docker/app.Dockerfile --target prod --build-arg APP_VERSION=1.0.0 -t ghcr.io/<owner>/tms:1.0.0 .
cp .env.prod.example .env      # puis renseigner les variables (voir le fichier)
docker compose -f compose.prod.yaml --env-file .env up -d --wait
ops/migrate.sh   # rôle propriétaire dans un conteneur éphémère uniquement
```

Seul Caddy publie des ports (80/443) ; PostgreSQL et Redis ne sont pas exposés. Caddy est la seule
adresse de confiance pour `X-Forwarded-*` (`TRUSTED_PROXIES`), ce qui active HTTPS/HSTS et l'IP cliente
correcte. `/health` est aussi servi en HTTP interne (conteneur `caddy`, port 8081, non publié).

#### Sauvegardes et restauration

`ops/backup.sh [--tag <libellé>]` exporte la base (`pg_dump -Fc`) et `storage/app`, puis les enregistre dans un
dépôt **restic** chiffré (`RESTIC_REPOSITORY`, par défaut `/var/backups/tms`, copie distante optionnelle
`RESTIC_REMOTE_REPOSITORY`). Rétention : 14 jours, 8 semaines, 12 mois. La dernière ligne affichée est l'identifiant du
snapshot ; en cas d'échec un e-mail est envoyé à `TMS_ALERT_EMAIL` (`php artisan tms:alert`). Seul Docker est requis
sur l'hôte (restic s'exécute via l'image officielle `restic/restic`).

`ops/restore.sh <snapshot|latest>` arrête app/worker/scheduler, restaure la base (schéma `public` supprimé et recréé
puis export rejoué, le tout en une transaction : les objets créés après le snapshot disparaissent ; droits et
privilèges par défaut repris tels qu'exportés) et les fichiers, relance la pile et attend `/health`.

Tests d'intégration (pile jetable, secrets générés) : `bats ops/tests` depuis `tms/` (Docker requis).

**À conserver HORS du serveur : `RESTIC_PASSWORD` et `APP_KEY`.** `APP_KEY` chiffre les secrets des connecteurs et les
doubles authentifications ; le snapshot (chiffré) en contient une copie (`meta.env`). Sur un serveur neuf, la restauration
refuse une `APP_KEY` différente ; `ops/restore.sh --with-app-key <snapshot>` adopte celle de la sauvegarde (écrite dans
`.env`, mode 600, seulement une fois la base restaurée ; l'ancien fichier est gardé en `.env.bak-<date>`, mode 600). Les snapshots portent l'étiquette `version:<TMS_VERSION>` pour retrouver celui d'une version donnée.
La sauvegarde lit le volume `storage` directement : elle fonctionne même si le conteneur `app` est arrêté, et l'alerte
e-mail retombe sur un conteneur éphémère.
