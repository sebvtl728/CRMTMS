# TMS — gestion commerciale et de formation (CAP FORMATION)

Logiciel de gestion pour organismes de formation : fiches entreprises et personnes, pipeline commercial, actions et
relances, agenda, catalogue de formations, devis. Il s'auto-héberge sur **Proxmox** ou sur un serveur Debian/Ubuntu.

- Versions publiées : <https://github.com/sebvtl728/CRMTMS/releases>
- Documentation détaillée : [`tms/docs/install.md`](tms/docs/install.md) (installation, mise à jour, sauvegardes),
  [`tms/docs/admin.md`](tms/docs/admin.md) (administration), [`tms/docs/crm.md`](tms/docs/crm.md) (guide utilisateur)

---

## 1. Installation

### Prérequis

- Proxmox VE 8 (accès root au nœud) **ou** une machine Debian 12 / Ubuntu 24.04 vierge (root).
- 2 cœurs, 4 Go de mémoire, 20 Go de disque.
- Un nom de domaine (ex. `tms.mondomaine.fr`) qui pointe vers votre adresse IP publique.
- L'e-mail de l'administrateur et le nom de l'organisme.

### Sur Proxmox (conteneur LXC créé automatiquement)

Dans le shell du nœud Proxmox, en root :

```bash
bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/proxmox.sh)"
```

Le script pose ses questions (domaine, e-mail, nom de l'organisme, ressources du conteneur), crée un conteneur
Debian 12, installe la dernière version et affiche l'adresse du TMS à la fin.

### Sur un serveur Debian / Ubuntu

En root :

```bash
TMS_DOMAIN=tms.mondomaine.fr TMS_ADMIN_EMAIL=admin@mondomaine.fr TMS_TENANT_NAME="CAP FORMATION" \
  bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/install.sh)"
```

### Installation sans questions (Proxmox)

```bash
TMS_NONINTERACTIVE=1 TMS_DOMAIN=tms.mondomaine.fr TMS_ADMIN_EMAIL=admin@mondomaine.fr \
TMS_TENANT_NAME="CAP FORMATION" CT_IP=192.168.1.50/24 CT_GW=192.168.1.1 \
  bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/proxmox.sh)"
```

Variables utiles : `TMS_TLS=internal` (certificat interne, réseau local ou derrière un proxy), `TMS_VERSION=0.3.1`
(version précise), `CTID`, `CT_CORES`, `CT_RAM`, `CT_DISK`, `CT_STORAGE`, `CT_BRIDGE`. Liste complète dans
[`tms/docs/install.md`](tms/docs/install.md).

### ⚠️ À la fin de l'installation

L'installateur affiche **une seule fois** deux secrets : `APP_KEY` et `RESTIC_PASSWORD`. Copiez-les immédiatement
dans un gestionnaire de mots de passe, **hors du serveur** : sans eux, impossible de restaurer une sauvegarde.

L'administrateur reçoit ensuite un lien d'activation par e-mail ; la double authentification est demandée à la
première connexion.

---

## 2. Installation derrière Nginx Proxy Manager (ou un autre proxy)

Si un proxy (Nginx Proxy Manager, Traefik…) occupe déjà les ports 80 et 443 :

1. **Dans le conteneur TMS**, passer en certificat interne (le proxy fournit le vrai certificat) :

   ```bash
   pct enter <CTID>
   ```

   ```bash
   cd /opt/tms && sed -i 's/^TMS_TLS=.*/TMS_TLS=internal/' .env && docker compose -f compose.prod.yaml --env-file .env up -d caddy
   ```

   Relever l'adresse IP du conteneur :

   ```bash
   hostname -I
   ```

2. **Dans Nginx Proxy Manager**, créer le « Proxy Host » du domaine :
   - *Details* : Scheme **https**, Forward Hostname/IP = IP du conteneur, Forward Port **443**, cocher
     **Websockets Support** ;
   - *SSL* : certificat Let's Encrypt, cocher **Force SSL** ;
   - *Advanced* :

     ```
     proxy_ssl_server_name on;
     proxy_ssl_name tms.mondomaine.fr;
     ```

3. **DNS local** (AdGuard, Pi-hole…) : si la box ne sait pas renvoyer vers l'intérieur (cas de la Livebox), ajouter
   une réécriture DNS `tms.mondomaine.fr` → **adresse locale de Nginx Proxy Manager** (pas celle du conteneur TMS).

Limite actuelle : derrière un proxy, le TMS voit l'adresse du proxy au lieu de celle de chaque visiteur (les limites
anti-abus par adresse IP sont communes à tous).

---

## 3. Mise à jour

Chaque nouvelle version est publiée sur GitHub. Pour l'installer, dans le conteneur (ou sur le serveur), en root :

```bash
pct enter <CTID>
```

```bash
/opt/tms/ops/update.sh
```

Version précise :

```bash
/opt/tms/ops/update.sh --version 0.4.0
```

Depuis le nœud Proxmox, sans entrer dans le conteneur :

```bash
bash -c "$(curl -fsSL https://github.com/sebvtl728/CRMTMS/releases/latest/download/proxmox.sh)" -- update <CTID>
```

Ce que fait la mise à jour, dans l'ordre :

1. télécharge la nouvelle version et vérifie son empreinte ;
2. arrête l'application et fait une **sauvegarde complète** (base + fichiers) ;
3. remplace le programme, met à jour la base, redémarre ;
4. vérifie que l'application répond (« Mise à jour vers X.Y.Z réussie »).

En cas d'échec (ou d'interruption), **retour automatique** à l'ancienne version avec restauration de la sauvegarde.
Les réglages (`/opt/tms/.env`, dont `TMS_TLS`) sont conservés. Coupure : une à deux minutes — à faire hors des
heures d'utilisation. Relancer la commande sur une version déjà à jour ne fait rien.

---

## 4. Sauvegardes et restauration

Une sauvegarde chiffrée (restic) tourne **chaque nuit à 02:30**, conservée 14 jours, 8 semaines et 12 mois.

```bash
/opt/tms/ops/backup.sh                      # sauvegarde immédiate
```

```bash
/opt/tms/ops/restore.sh latest              # restaurer la dernière sauvegarde
```

```bash
/opt/tms/ops/restore.sh <snapshot>          # restaurer une sauvegarde précise
```

Chaque mise à jour laisse une sauvegarde étiquetée `pre-update-<ancienne version>`. Sur un serveur neuf, ajouter
`--with-app-key` pour reprendre la clé de la sauvegarde. Détails : [`tms/docs/install.md`](tms/docs/install.md).

---

## 5. Commandes utiles (dans le conteneur, dossier `/opt/tms`)

```bash
docker compose -f compose.prod.yaml --env-file .env ps          # état des services
```

```bash
docker compose -f compose.prod.yaml --env-file .env logs -f app # journaux de l'application
```

```bash
curl -fsS https://tms.mondomaine.fr/health                       # santé de l'application (remplacer le domaine)
```

---

## Développement

Pile de développement Docker (Colima sur macOS), tests et conventions : voir [`CLAUDE.md`](CLAUDE.md) et
[`tms/docs/install.md`](tms/docs/install.md) (section développement).
