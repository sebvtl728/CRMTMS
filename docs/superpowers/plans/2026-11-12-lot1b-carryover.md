# Reprises du lot 1a à traiter dans le lot 1b

Issues des relectures du lot 1a (branche `lot1a-socle`). À intégrer au plan 1b ; les trois premiers blocs sont à faire **en premier**.

## 1. Premier lot de 1b (avant la première mise en production CAP)

- **Gestion des utilisateurs dans `/admin/users`** : désactiver un salarié, changer un rôle, renvoyer / révoquer une invitation (aujourd'hui seules les commandes `tms:user:activation-link` et `tms:user:reset-2fa` existent).
- Pages d'erreur : les messages anglais du framework (429 « Too Many Attempts », 405 qui révèle la route et les méthodes, 413) remplacent le texte français — n'afficher le message que pour nos `abort()`.
- Connecteur SMTP : changer `encryption` (ex. `tls` → `none`) sur le même hôte garde le mot de passe stocké → ajouter `encryption` aux réglages qui exigent de ressaisir les secrets.
- Commandes de récupération : exiger `--tenant` quand `tms.mode=multi`.
- Mailer `failover` non considéré comme « n'envoie nulle part » (risque de jeton dans les logs si un jour configuré).

## 2. Fondations utiles à 1b

- Test d'architecture : tout modèle sur une table métier utilise `BelongsToTenant` (à écrire avant les nouveaux modèles).
- Helper de chemin de stockage par organisme (imports).
- Exécuteur « pour chaque organisme » pour les tâches planifiées (purge des jetons de réinitialisation et invitations expirés, réactivations 1e).
- Décider entre l'enum `LegalForm` (sociétés) et le référentiel `legal_form_org` (organisations clientes).
- Helper `Rls::defaultTenantFromSession($table)` (expression dupliquée dans 3 migrations).
- Contexte `tenant_id` dans les logs (`Log::shareContext`).
- `TMS_SEED_CAP` par défaut à 0 dès qu'un deuxième client existe.
- Ziggy publie toutes les routes (y compris admin) aux visiteurs.

## 3. Durcissement avant production

- `REVOKE UPDATE, DELETE` sur `audit_logs` pour `tms_app` ; journal : `distinct()` sur tout le journal à chaque page, valeurs imbriquées « [object Object] ».
- Jobs des organismes suspendus : décider s'ils tournent ; `REVOKE` écritures sur `tenants` / `tenant_domains` quand le super-admin existe ; garde `current() !== null` dans le listener `Looping`.
- Connexion : temps de réponse constant (Timebox) ; recontrôle d'expiration dans la transaction d'activation ; verrouillage progressif sur le défi 2FA ; FK `(model_id, tenant_id)` sur `model_has_roles`.
- `TenantMailer` : cache DNS, classification IPv6 6to4/Teredo/fec0.
- Restauration : vider les files Horizon/Redis ; fichier SQL en clair dans `/tmp` du conteneur postgres pendant la restauration.
- Mise à jour : si `restore.sh` échoue pendant un retour arrière, garder app/worker/scheduler arrêtés ; alerte quand la sauvegarde nocturne est refusée par le verrou ; images d'infrastructure des fichiers dev/e2e/CI non épinglées ; recommander tmux / `systemd-run` (coupure SSH).
- Publication : épingler les actions GitHub par SHA ; éviter `actionlint` via curl|bash ; **vérification cosign dans l'installateur et la mise à jour** (aujourd'hui seule l'empreinte de l'image, issue du même hôte que l'archive, est vérifiée).
- e2e Playwright dans la CI.

## 4. Validations manuelles en attente (utilisateur)

- Créer le dépôt GitHub, renseigner `TMS_GITHUB_REPO` (3 fichiers, voir `tms/docs/release.md`), pousser, publier `v0.1.0-rc.1`, vérifier la build amd64 et la signature cosign.
- Installation réelle sur un nœud Proxmox (Docker dans un LXC non privilégié : stockage ZFS/overlay, AppArmor), puis `proxmox.sh update`.
- Hôte local : `~/.docker/config.json` référence `docker-credential-desktop` absent (gêne les `pull` locaux) — retirer la clé `credsStore`.
