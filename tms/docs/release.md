# Publier une version

Une version est publiée par le workflow `.github/workflows/release.yml` quand un tag `vX.Y.Z` (ou
`vX.Y.Z-rc.1`, pré-version) est poussé. Rien n'est publié à la main.

## Étape unique, à faire une fois : renseigner le dépôt

Les scripts ne connaissent pas encore le dépôt GitHub. Dès qu'il existe, remplacer la valeur vide de
`TMS_GITHUB_REPO` (format `propriétaire/dépôt`) **dans les trois fichiers qui la déclarent**, le même jour et
dans le même commit :

- `ops/install/install.sh` (ligne `TMS_GITHUB_REPO="${TMS_GITHUB_REPO:-}"` : mettre la valeur par défaut après `:-`) ;
- `ops/install/proxmox.sh` (idem) ;
- `ops/lib.sh` (idem ; utilisé par `ops/update.sh`).

`ops/tests/install.bats` vérifie que les copies du code de téléchargement restent identiques. Tant que la valeur
est vide, `TMS_DIST_URL` (et `TMS_IMAGE_OWNER`) sont obligatoires. Dans `CHANGELOG.md`, remplacer aussi `OWNER/REPO`
dans les liens de bas de page. Autoriser les paquets : dans les paramètres du dépôt, *Actions → General →
Workflow permissions* doit permettre l'écriture des paquets pour `GITHUB_TOKEN` (le workflow demande lui-même ses
permissions) ; après la première publication, rendre le paquet `tms` visible/lisible par les installations
(privé : fournir un jeton de lecture sur les serveurs).

## Publier

1. Mettre à jour `VERSION` (par exemple `0.2.0`) et `CHANGELOG.md` (déplacer « Non publié » sous la nouvelle
   version, avec la date).
2. Vérifier en local : `bats ops/tests` (depuis `tms/`) et la CI verte sur la branche.
3. Fusionner dans `main` (le tag se pose sur un commit de `main`), puis :

   ```bash
   git tag v0.2.0-rc.1 && git push origin v0.2.0-rc.1   # pré-version : release marquée « pre-release », pas de :latest
   git tag v0.2.0 && git push origin v0.2.0             # version finale : :latest mis à jour
   ```

   Le workflow refuse un tag dont la partie `X.Y.Z` ne correspond pas à `VERSION`, ou un commit qui n'est pas sur `main`.
4. Le workflow, dans cet ordre (un échec ne laisse jamais une version à moitié publiée) : vérifie le tag
   (format, correspondance avec `VERSION`, commit présent sur `main`) et fait passer toute la CI sur le commit →
   construit l'image `prod` (amd64 + arm64, `APP_VERSION` = version), pousse **seulement**
   `ghcr.io/<propriétaire en minuscules>/tms:<version>` et la signe avec cosign (sans clé, identité OIDC du workflow)
   → construit `tms-<version>.tar.gz` et `SHA256SUMS` (`ops/release/make-bundle.sh <version> dist <empreinte>`) :
   l'archive contient `release.env` avec `TMS_IMAGE_DIGEST=sha256:…`, l'**empreinte de l'image poussée** ; l'archive
   étant vérifiée par `SHA256SUMS` (signé), installations et mises à jour tirent `…/tms:<version>@sha256:…` et Docker
   refuse toute autre image, même si le tag est déplacé → signe `SHA256SUMS`, crée la release en
   **brouillon**, y attache l'archive, `SHA256SUMS`, `SHA256SUMS.cosign.bundle`, `install.sh`, `proxmox.sh`, puis la
   publie → en dernier, hors pré-version, déplace `:latest` vers l'image signée. Si une étape échoue, corriger puis
   re-pousser un nouveau tag (ou relancer le workflow) ; une release en brouillon restante se supprime à la main.
5. Valider l'installation depuis la release (voir « Validation manuelle sur Proxmox » dans `install.md`).

Construire les fichiers localement (sans rien publier) : `ops/release/make-bundle.sh 0.2.0 /tmp/release`
(sans troisième argument, `release.env` ne fixe pas d'empreinte : l'image est tirée par son seul tag, ce qui
convient aux constructions locales et aux tests, jamais à une publication).

## Vérifier une signature

```bash
OWNER=<propriétaire en minuscules>; REPO=<dépôt>; VERSION=0.2.0
ID="https://github.com/$OWNER/$REPO/.github/workflows/release.yml@refs/tags/v$VERSION"

# Image (cosign 2.x ; le workflow utilise la version fixée par COSIGN_RELEASE)
cosign verify "ghcr.io/$OWNER/tms:$VERSION" \
  --certificate-identity "$ID" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com

# Fichiers de la release : signature de SHA256SUMS, puis sommes de contrôle
cosign verify-blob SHA256SUMS --bundle SHA256SUMS.cosign.bundle \
  --certificate-identity "$ID" \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
sha256sum -c SHA256SUMS      # macOS : shasum -a 256 -c SHA256SUMS
```

L'installateur vérifie déjà les sommes de contrôle ; la vérification cosign est un contrôle supplémentaire,
à la demande, de l'origine des fichiers.
