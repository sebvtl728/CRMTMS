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

Les tests de volume (groupe `slow` : import de 10 000 lignes en moins de 60 s, recherche sur
50 000 fiches en moins d'une seconde sous RLS) et la conversion réelle d'un devis en PDF (service
`gotenberg`, démarré avec l'application ; adresse `GOTENBERG_URL`, par défaut `http://gotenberg:3000`)
sont exclus de la commande ci-dessus (`phpunit.xml`) ; la CI les lance à part (service `gotenberg` du job) :

```bash
docker compose exec app php artisan test --group=slow
```

### Tests de bout en bout (Playwright)

Le test `tests/e2e/admin.spec.ts` rejoue le parcours d'un administrateur dans un vrai navigateur : lien
d'activation, mot de passe, enrôlement 2FA (code TOTP calculé avec `otplib`), création d'une société SCOP,
invitation d'un utilisateur, journal d'audit. Il enregistre la session de l'administrateur
(`tests/e2e/.auth/admin.json`, ignoré par git) que réutilise `tests/e2e/crm.spec.ts` : import d'un CSV de
3 lignes par l'assistant « Importer », fiches visibles dans la liste, annulation de l'import, puis le parcours
d'une fiche (création, interlocuteur, note, avertissement de doublon et fusion, recherche globale, export Excel)
et son affichage sur téléphone (375 px). Le service Sirene n'est pas appelé : son échec est simplement constaté.
`tests/e2e/pipeline.spec.ts` couvre le pipeline : opportunité créée depuis une entreprise, glissée jusqu'à
« Proposition » dans le kanban, refus de « Perdu » sans motif puis acceptation avec motif, saisie rapide d'un appel
avec relance le lendemain, relance visible dans « À faire » et dans le planning, menu « Commercial », accueil
(pipeline pondéré du mois), puis un compte rendu de rendez-vous au téléphone (375 × 812). `tests/e2e/report.spec.ts`
rejoue le compte rendu d'un rendez-vous du jour en moins de 6 gestes. `tests/e2e/quotes.spec.ts` couvre le devis :
formation créée dans le catalogue, ajoutée à une opportunité « Étude », « Calculer le montant proposé », devis créé
depuis l'opportunité, aperçu PDF (vrai rendu par le service `gotenberg` de la pile, réponse `application/pdf`),
envoi (mailer `log` de la pile e2e : le résultat est vérifié à l'écran, statut « Envoyé », sans boîte aux lettres),
opportunité passée en « Proposition », « Accepté » puis « Gagné », devis listé avec un taux de réussite du mois de
100 %. La société créée par `admin.spec.ts` y prend le préfixe `CF` (celui de la société d'installation est `DEV`). `tests/e2e/brochures.spec.ts` couvre la bibliothèque de plaquettes (dépôt, PDF invalide refusé, titre modifié, fichier remplacé = nouvelle version, téléchargements). `tests/e2e/interests.spec.ts` vérifie le nom et le logo de la société par défaut dans la barre latérale et le titre de l'onglet, puis les **formations d'intérêt** d'une entreprise (choix, filtre de la liste, formation proposée sur une nouvelle opportunité). `tests/e2e/planning.spec.ts` invite un formateur, génère les séances d'une session avec l'assistant (aperçu puis création), les retrouve au calendrier et au planning des séances, puis le formateur ouvre une séance de « Mon planning » et marque « Tous présents ».
Tous réutilisent la session de
l'administrateur : les fichiers se lancent ensemble, dans l'ordre alphabétique (un seul processus).

Il s'exécute sur une pile dédiée (`compose.e2e.yaml` : application `app-e2e`, base `tms_e2e`, organisme `e2e`,
port **8081**, assets compilés, file `sync`, e-mails écrits dans le journal ; elle utilise le `gotenberg` de la pile de
développement) : la base et les données de développement ne sont jamais touchées. Prérequis :
la pile de développement démarrée (`docker compose up -d`, Colima inclus) et Node.js 20+ sur la machine hôte.

```bash
cd tms
npm ci                            # sur la machine hôte, à refaire après chaque changement de package-lock.json
npx playwright install chromium   # une seule fois : télécharge le navigateur (~100 Mo)
npm run e2e                       # compile les assets, recrée tms_e2e, installe l'organisme, lance les tests
```

Le conteneur `vite` a son propre `node_modules` (volume Docker) : une dépendance ajoutée par
`docker compose run --rm vite npm install …` n'est pas présente sur l'hôte. Si `npm run e2e` échoue à la
compilation (« Could not resolve "@dnd-kit/core" », par exemple), relancez `npm ci` sur l'hôte.

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
bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/proxmox.sh)"
```

Les questions s'affichent (whiptail). Sans questions :

```bash
TMS_NONINTERACTIVE=1 TMS_DOMAIN=tms.example.org TMS_ADMIN_EMAIL=admin@example.org \
TMS_TENANT_NAME="CAP FORMATION" CT_IP=192.168.1.50/24 CT_GW=192.168.1.1 \
TMS_DIST_URL=https://github.com/sebvtl728/CRMTMS/releases/latest/download \
  bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/proxmox.sh)"
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
TMS_DIST_URL=https://github.com/sebvtl728/CRMTMS/releases/latest/download \
  bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/install.sh)"
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

#### Derrière Nginx Proxy Manager ou un autre proxy qui termine le TLS

Cas rencontré sur le terrain : le serveur n'est pas joignable directement depuis Internet, un proxy existant
(Nginx Proxy Manager, Traefik, HAProxy…) reçoit le trafic et le relaie au TMS.

1. Sur le serveur du TMS, mettre `TMS_TLS=internal` dans `/opt/tms/.env`, puis recréer Caddy pour qu'il relise la
   variable : `docker compose --project-directory /opt/tms -f /opt/tms/compose.prod.yaml --env-file /opt/tms/.env up -d caddy`. Caddy ne demande plus de certificat
   Let's Encrypt (le proxy s'en charge) et sert le domaine avec son autorité locale.
2. Dans le proxy, pour le domaine du TMS : schéma **https**, port **443**, adresse IP du serveur TMS, **WebSockets
   activés**. Le proxy doit présenter le nom du domaine à Caddy (SNI), sinon Caddy ne sait pas quel certificat
   servir et la connexion échoue ; dans Nginx Proxy Manager, onglet *Advanced* de l'hôte :
   ```nginx
   proxy_ssl_server_name on;
   proxy_ssl_name <domaine>;
   ```
   Le certificat public (Let's Encrypt) est obtenu par le proxy, comme pour ses autres sites.
3. Si la box ou le routeur ne fait pas de *NAT loopback* (le domaine public ne répond pas depuis le réseau local),
   ajouter une réécriture DNS locale du domaine vers l'**adresse IP du proxy sur le réseau local** (par exemple
   *DNS rewrites* d'AdGuard Home, ou l'équivalent de Pi-hole / du routeur).

**Limite actuelle** : le TMS ne fait confiance qu'à Caddy pour `X-Forwarded-For` ; derrière un second proxy, il voit
l'adresse du proxy pour tous les visiteurs. Les limites par adresse IP (connexion, lien d'activation, flux iCal,
visiteurs non connectés…) sont alors **partagées par tous les utilisateurs**, et le journal d'audit note l'adresse
du proxy. Un mode « proxy dédié » (adresse du proxy de confiance configurable) est à prévoir ; d'ici là, ne pas
mettre `TRUSTED_PROXIES=*`.

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

#### Ce qui tourne chaque nuit

L'installateur crée `tms-backup.service` et `tms-backup.timer` (systemd) : `ops/backup.sh` chaque nuit à 02:30
(`Persistent=true` : rattrapée au démarrage si la machine était éteinte), dépôt chiffré `/var/backups/tms`
(rétention : 14 jours, 8 semaines, 12 mois). Vérifier :

```bash
systemctl list-timers tms-backup.timer
journalctl -u tms-backup.service -n 50
```

Sur Proxmox, une sauvegarde `vzdump` du conteneur complète restic.

#### La page Administration › Sauvegardes

Les administrateurs voient dans l'application (menu Administration › Sauvegardes) : la date, le résultat, la taille et
la version de la dernière sauvegarde, l'état de la copie hors du serveur et la liste des sauvegardes disponibles.
Si le serveur ne l'a jamais écrit (fichier d'état absent), la page affiche « Aucune sauvegarde connue » en alerte.
Un bandeau s'affiche sur l'accueil des administrateurs quand la dernière sauvegarde a échoué, date de plus de
36 heures ou est inconnue. La page ne permet pas de télécharger les données ni de restaurer : ces opérations restent
des commandes sur le serveur (voir ci-dessous).

**Sauvegarder maintenant** : le bouton demande une sauvegarde immédiate (étiquette `manuel`). Le serveur la lance dans
la minute (`tms-backup-request.path`, installé et mis à jour automatiquement) ; le bouton indique « Sauvegarde
demandée… » puis « Sauvegarde en cours… » et la page se met à jour à la fin. Si le bouton reste sur « demandée » :

```bash
systemctl status tms-backup-request.path
journalctl -u tms-backup-request.service -n 50
```

Pour la lancer à la main, sans passer par la page : `sudo /opt/tms/ops/backup.sh --tag manuel`.

#### Être prévenu en cas d'échec

Renseigner dans `/opt/tms/.env` l'adresse qui doit recevoir l'alerte (envoyée par l'application si une sauvegarde
échoue ; les connecteurs de messagerie doivent être configurés) :

```bash
sudo nano /opt/tms/.env      # ajouter une ligne : TMS_ALERT_EMAIL=admin@votre-organisme.fr
```

#### Copie hors du serveur

Une sauvegarde qui reste sur le serveur disparaît avec lui (panne de disque, incendie, vol). Pour une copie
automatique, ajoutez dans `/opt/tms/.env` les variables ci-dessous : à chaque sauvegarde, la nuit comme à la demande,
le dernier état est copié dans le dépôt distant, avec la même rétention. Le dépôt distant est créé automatiquement
au premier passage. Il est chiffré avec `RESTIC_PASSWORD` (ou `RESTIC_REMOTE_PASSWORD` si vous voulez un autre mot
de passe pour le distant).

**Exemple 1 : un NAS (Synology, QNAP…) joint par SSH/SFTP.** La copie de restic s'exécute dans un conteneur Docker
qui n'a pas accès aux clés SSH du serveur. Le plus simple est donc de monter le NAS sur le serveur par SSHFS
(protocole SFTP, clé dédiée), puis de désigner ce dossier. Sur le NAS, activez SSH/SFTP et créez un utilisateur
`tms-backup` qui n'a accès qu'à un dossier `tms-backups`. Sur le serveur TMS, en root :

```bash
apt-get install -y sshfs
ssh-keygen -t ed25519 -N '' -C 'tms-sauvegardes' -f /root/.ssh/tms_nas      # clé dédiée, sans phrase secrète
ssh-copy-id -i /root/.ssh/tms_nas.pub tms-backup@nas.example.lan            # installe la clé sur le NAS
ssh -i /root/.ssh/tms_nas tms-backup@nas.example.lan 'echo ok'              # doit afficher « ok » sans mot de passe
ssh-keyscan nas.example.lan >> /root/.ssh/known_hosts                       # empreinte du NAS (à vérifier)
mkdir -p /mnt/nas-tms
chattr +i /mnt/nas-tms           # tant que le NAS n'est pas monté, rien ne peut s'écrire dans ce dossier
```

Montage permanent : ajoutez cette ligne (une seule ligne) à `/etc/fstab`, puis montez :

```bash
echo 'tms-backup@nas.example.lan:/tms-backups /mnt/nas-tms fuse.sshfs _netdev,allow_other,IdentityFile=/root/.ssh/tms_nas,reconnect,ServerAliveInterval=15 0 0' >> /etc/fstab
mount /mnt/nas-tms
mountpoint /mnt/nas-tms          # doit répondre « is a mountpoint »
```

Puis dans `/opt/tms/.env` :

```
RESTIC_REMOTE_REPOSITORY=/mnt/nas-tms/tms
```

Attention : sans le `chattr +i` ci-dessus, un NAS non monté (panne, redémarrage) ferait écrire la « copie distante »
sur le disque du serveur lui-même. Dans un conteneur LXC Proxmox, SSHFS demande l'option `fuse=1` du conteneur
(Options › Fonctionnalités) ; à défaut, montez le NAS en NFS ou SMB sur l'hôte Proxmox et partagez le dossier avec le
conteneur.

**Exemple 2 : un stockage compatible S3** (Scaleway, OVH, Backblaze B2, Wasabi, MinIO…). Créez un compartiment
(bucket) dédié et une clé d'accès limitée à ce compartiment. Les identifiants vont dans un fichier à part, lisible par
root seul (jamais directement dans `.env`) :

```bash
install -m 600 /dev/null /opt/tms/restic-remote.env
nano /opt/tms/restic-remote.env
```

Contenu du fichier (une variable par ligne, sans guillemets) :

```
AWS_ACCESS_KEY_ID=votre-identifiant
AWS_SECRET_ACCESS_KEY=votre-secret
AWS_DEFAULT_REGION=fr-par
```

Puis dans `/opt/tms/.env` (l'adresse du service, ici un exemple, figure dans la documentation du fournisseur ; pour
AWS même, `s3:s3.amazonaws.com/nom-du-compartiment/tms`) :

```
RESTIC_REMOTE_REPOSITORY=s3:https://s3.fr-par.scw.cloud/nom-du-compartiment/tms
RESTIC_REMOTE_ENV_FILE=/opt/tms/restic-remote.env
```

**Vérifier que la copie fonctionne.** Lancez une sauvegarde et regardez le résultat :

```bash
sudo /opt/tms/ops/backup.sh --tag manuel
```

Les lignes « Copie vers le dépôt distant. » puis « Sauvegarde terminée. » doivent apparaître sans erreur. Sur la page
Administration › Sauvegardes, la ligne **Copie hors site** doit indiquer « Configurée, dernière copie réussie » ;
« dernière copie échouée » signifie que la sauvegarde locale est bonne mais que la copie a échoué (le message de la
commande en donne la cause : réseau, identifiants, NAS non monté). Une fois par trimestre, vérifiez aussi que les
sauvegardes sont lisibles depuis le distant, par exemple pour un NAS monté :
`ls /mnt/nas-tms/tms` (le dossier contient `config`, `data`, `snapshots`…).

#### À conserver HORS du serveur

Notez dans un gestionnaire de mots de passe, sur un autre appareil : **`RESTIC_PASSWORD`** (sans lui, aucune
sauvegarde n'est lisible), **`APP_KEY`** (sans elle, les secrets des connecteurs et les doubles authentifications sont
perdus) et, quand vous en créez un, le **mot de passe d'un export**. Ils se lisent dans `/opt/tms/.env`
(`sudo grep -E '^(RESTIC_PASSWORD|APP_KEY)=' /opt/tms/.env`). Ne les envoyez jamais par e-mail non chiffré.

### Déménager vers un autre serveur

Un **export** est un seul fichier chiffré qui contient la base, les fichiers déposés, la clé `APP_KEY` et la version :
les utilisateurs, leur double authentification et les secrets des connecteurs sont repris tels quels. Pendant
l'export la sauvegarde du serveur actuel continue de fonctionner ; le service reste utilisable.

**1. Sur l'ancien serveur**, en root :

```bash
sudo /opt/tms/ops/export.sh
```

La commande demande **deux fois un mot de passe d'export** (12 caractères minimum, différent de `RESTIC_PASSWORD`
de préférence ; notez-le hors du serveur). Elle fait une sauvegarde, la vérifie, puis écrit le fichier dans
`/var/backups/tms-exports/` (accès root seul), nommé `tms-export-<organisme>-<date>-v<version>.tar`, et affiche son
**empreinte SHA-256** puis son chemin. Notez l'empreinte.

**2. Copiez le fichier** sur le nouveau serveur (ou sur le poste d'où vous installez), par exemple :

```bash
scp /var/backups/tms-exports/tms-export-cap-formation-20261117-1430-v0.8.2.tar root@nouveau-serveur:/root/
```

Sur le nouveau serveur, vérifiez que l'empreinte est la même : `sha256sum /root/tms-export-*.tar`. Sans le mot de
passe d'export, le fichier est inutilisable : il peut donc transiter sans danger, mais supprimez-le des deux serveurs
quand le déménagement est terminé.

**3. Installez sur le nouveau serveur** (mêmes prérequis que l'installation habituelle, voir plus haut) en ajoutant
`--import` ; le mot de passe de l'export est demandé :

```bash
bash -c "$(curl -fsSL <adresse de publication>/install.sh)" install.sh --import /root/tms-export-cap-formation-20261117-1430-v0.8.2.tar
```

Il faut renseigner `TMS_DOMAIN` et `TMS_ADMIN_EMAIL` comme pour toute installation ; aucun organisme ni
administrateur n'est créé : ce sont ceux de l'export. Les secrets techniques (PostgreSQL, Redis, `RESTIC_PASSWORD`)
sont nouveaux, propres au nouveau serveur : **notez les nouvelles valeurs affichées à la fin**. Seule l'`APP_KEY`
est reprise de l'export.

Sur un serveur **déjà installé** (installation précédente, ou installation vide faite avant), importez directement :

```bash
sudo /opt/tms/ops/import.sh /root/tms-export-cap-formation-20261117-1430-v0.8.2.tar
```

Règle de version : l'import **refuse** un export plus récent que la version installée (message « mettez d'abord à
jour en <version> »), car une version ne sait pas lire les données d'une version plus récente. Dans ce cas, mettez
d'abord à jour : `sudo /opt/tms/ops/update.sh --version <version>`. Un export plus ancien est accepté : les
migrations sont rejouées après l'import.

**4. Comparez le récapitulatif de contrôle** affiché à la fin avec ce que vous voyez sur l'ancien serveur
(Organismes, Utilisateurs, Entreprises, Personnes, Sessions, Fichiers) : les nombres doivent être identiques. Puis
connectez-vous sur le nouveau serveur avec un compte existant (même mot de passe, même double authentification) et
ouvrez quelques fiches et un devis.

**5. Basculez le nom de domaine.** Le nouveau serveur obtient son certificat HTTPS quand le nom de domaine pointe vers
lui. Quand le contrôle est bon, modifiez l'enregistrement DNS (type A, ou AAAA) du nom de l'application pour
l'adresse du nouveau serveur ; la propagation prend de quelques minutes à quelques heures. Si le nouveau serveur est
derrière Nginx Proxy Manager, changez la cible du proxy host. Entre l'export et la bascule, ne saisissez plus rien
sur l'ancien serveur (ce qui y est saisi après l'export n'est pas repris) ; gardez-le éteint mais intact quelques
semaines.

**Revenir en arrière.** Avant de remplacer quoi que ce soit, l'import fait une **sauvegarde de sécurité** de l'état du
serveur (étiquette `pre-import`, visible sur la page Sauvegardes) et affiche sa commande de retour :

```bash
sudo /opt/tms/ops/restore.sh --with-app-key <identifiant de la sauvegarde pre-import>
```

Si l'import échoue avant tout changement (mauvais mot de passe, fichier tronqué ou endommagé, version trop récente),
il le dit (« rien n'a été modifié ») et le serveur reste tel quel. Sur l'ancien serveur, rien n'est modifié par
l'export : pour annuler le déménagement, il suffit de ne pas basculer le DNS.

### Mise à jour

En root, dans le conteneur ou sur la machine :

```bash
/opt/tms/ops/update.sh                    # dernière version publiée
/opt/tms/ops/update.sh --version 1.4.0    # version précise
```

Tant que le dépôt de publication n'est pas renseigné dans les scripts (`TMS_GITHUB_REPO`), préfixer la commande par
`TMS_DIST_URL=<dossier des fichiers de la version>`.

Mise à jour vers la 0.5.0 : la migration convertit automatiquement les droits existants (`crm.view`, `crm.create`,
`crm.update`, `crm.delete` deviennent des droits par domaine, sans changer l'accès de personne) et crée de nouveaux
index sur les tables des fiches ; le verrou est bref. Détail : `admin.md` (§ 10).

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

Seul Caddy publie des ports (80/443) ; PostgreSQL, Redis et Gotenberg ne sont pas exposés.

**Gotenberg** (conversion HTML → PDF des devis) fait partie de la pile : `docker compose up -d --wait`
(installateur, mise à jour, restauration) le démarre et attend son contrôle de santé, sans action de
l'installateur. Il tourne sans JavaScript ni accès réseau sortant (options de `compose.prod.yaml`) : les
documents sont autonomes (logo intégré). S'il est arrêté, l'aperçu ou l'envoi d'un devis affiche « Le PDF n'a pas
pu être généré, réessayez dans un instant. » ; les PDF des devis envoyés, archivés dans
`storage/app/private/tenants/<organisme>/quotes/` (volume `storage`, inclus dans la sauvegarde), restent consultables. Caddy est la seule
adresse de confiance pour `X-Forwarded-*` (`TRUSTED_PROXIES`), ce qui active HTTPS/HSTS et l'IP cliente
correcte. `compose.prod.yaml` la renseigne avec l'adresse fixe de Caddy (`TMS_CADDY_IP`, `172.29.250.10` par
défaut) ; **toute installation derrière Caddy ou un autre proxy doit la renseigner**. Sans elle, l'application voit
l'adresse du proxy pour tous les visiteurs : chaque limite par adresse IP (connexion, lien d'activation,
vérification d'e-mail, flux iCal « Mon agenda », page de santé, visiteurs non connectés en général) est alors partagée par
tout le monde et se déclenche pour tous à la fois, et le journal d'audit note l'adresse du proxy. Ne jamais
mettre `*`. `/health` est aussi servi en HTTP interne (conteneur `caddy`, port 8081, non publié).

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
