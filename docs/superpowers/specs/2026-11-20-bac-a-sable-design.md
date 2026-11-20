# Bac à sable : réinitialisation et réseau local - conception courte

Demande du 2026-11-20 : le TMS lancé sur le Mac (`localhost:8080`) sert de bac à sable et de **démonstration pour
d'autres organismes**. Il faut (1) un bouton pour le remettre à zéro, avec deux choix, (2) l'ouvrir au réseau local,
(3) une société émettrice **fictive**. **L'effacement ne doit jamais exister sur le serveur de production (Proxmox).**

## Garde-fous (production)

- Le bac à sable n'existe que si `config('tms.sandbox')` est vrai, c'est-à-dire `TMS_SANDBOX=true` **et**
  `APP_ENV` = `local` (ou `testing` pour les tests). L'installateur et `update.sh` n'écrivent jamais `TMS_SANDBOX` ; le
  serveur tourne en `APP_ENV=production` : la condition y est toujours fausse.
- Hors bac à sable : la page et l'action répondent 404, le menu ne montre rien, la commande `tms:sandbox:reset`
  refuse et n'efface rien. Un test le vérifie pour `APP_ENV=production` même avec `TMS_SANDBOX=true`.
- Dans le bac à sable : droit « Paramètres » (administrateur, 2FA), confirmation en tapant « RÉINITIALISER ».

## Deux choix

- **Effacer les données de travail** : `TRUNCATE` (sans `CASCADE` : une table gardée qui pointerait vers une table
  effacée fait échouer la commande au lieu d'être vidée en silence) de toutes les tables **sauf** : comptes et droits
  (`users`, `user_das`, rôles, permissions, périmètres), organisme (`tenants`, `tenant_domains`), référentiels,
  modèles d'e-mails et de documents, connecteurs, réglages de facturation, champs personnalisés, profils d'import,
  salles et fermetures, sessions de connexion, liaisons d'agenda, tables techniques (migrations, cache, files). Le
  catalogue (formations, plaquettes, tests en ligne) est effacé : il décrit le vrai organisme. Les fichiers de
  l'organisme (`tenants/<id>/`) sont supprimés.
- **Repartir de zéro** : `TRUNCATE tenants CASCADE` (toutes les données de l'organisme), puis l'organisme est recréé
  par `TenantProvisioner` (rôles, référentiels par défaut), les droits par `PermissionSync`, et **le seul compte de
  l'administrateur qui a cliqué** est recréé à l'identique (même id, e-mail, nom, mot de passe haché, 2FA) : il reste
  connecté. Les fichiers sont supprimés.
- Dans les deux cas : l'organisme s'appelle « Organisme de démonstration » et la société par défaut devient fictive
  (« Organisme de démonstration », SAS, 1 rue de l'Exemple 75000 Paris, sans SIRET, TVA, NDA, logo ni signature,
  mentions vidées) ; les autres sociétés sont supprimées.

## Réseau local

Le port 8080 écoute déjà sur toutes les interfaces (Colima) ; `APP_URL` du `.env` local passe à
`http://MacBook-Pro-de-Sebvtl.local:8080` pour que les liens envoyés par e-mail fonctionnent depuis un autre poste.
