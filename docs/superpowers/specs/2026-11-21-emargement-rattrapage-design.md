# Rattrapage d'émargement — conception

Date : 2026-11-21 · Module : Sessions (`tms/app/Modules/Sessions/Attendance/Signing/`) · Prolonge `2026-11-20-emargement-design.md` (qui reste l'autorité pour tout ce qui n'est pas dit ici) · Version visée : 0.12.x (CHANGELOG « Non publié »)

## 1. Intention

L'émargement en ligne ne s'ouvre aux stagiaires que le jour même. Un stagiaire qui n'a pas signé (oubli, retard, téléphone déchargé) est noté **absent** à la validation, et la seule correction aujourd'hui est une saisie manuelle dans la grille, **sans signature**. Pour le financeur et l'auditeur Qualiopi, CAP FORMATION veut une **preuve signée** de ces présences rattrapées.

Deux façons de rattraper, toutes deux demandées par l'utilisateur :

1. **Sur place, en différé** : un gestionnaire rouvre une demi-journée passée en mode tablette ; le stagiaire signe sur l'appareil.
2. **À distance** : le stagiaire reçoit un **lien personnel par e-mail**, puis un **code à 6 chiffres par e-mail**, et signe la demi-journée manquée.

Décision de l'utilisateur : une signature de rattrapage **ne change pas la présence d'elle-même**. Elle est enregistrée « rattrapage » ; un gestionnaire **confirme** la présence d'un clic. La facturation au réalisé reste sous contrôle humain.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Rattrapage sur place d'une demi-journée **passée** (jamais le jour même : l'émargement normal suffit), tant que le mois n'est pas clôturé.
- Rattrapage à distance : lien personnel (7 jours, usage unique) + code e-mail (6 chiffres, 10 minutes, 5 essais, un nouveau code au plus par minute — mêmes règles que `Core\Signatures`).
- File **Rattrapages à confirmer** dans l'onglet « Présences » de la session ; **Confirmer** écrit la présence (même calcul que la validation) ; **Refuser** (motif) garde la signature, sans présence.
- Feuille d'émargement signée : mention « Signé en rattrapage le JJ/MM/AAAA à HH:MM (sur place | à distance, code e-mail), confirmé par <nom> le … » ; « Rattrapage à confirmer » tant que non confirmé.

**Non-objectifs**
- Rattrapage d'une demi-journée **jamais ouverte** (aucun créneau) : reste saisie manuelle ou papier. *(Appel du concepteur : le créneau est le support de la preuve ; en créer un après coup pour un seul stagiaire est un autre chantier. La porte connecteur `createPast` existe déjà si besoin.)*
- SMS, rattrapage du formateur (sa signature reste celle de la validation).
- Relance automatique des absents.

**Anti-objectifs**
- Une présence facturée sans action humaine à partir d'une signature à distance.
- Un lien de rattrapage qui révèle d'autres stagiaires (il ne porte qu'une personne) ou dont l'invalidité se distingue (même page 404 que l'émargement).
- Écraser une saisie manuelle de la grille (`attendances.source` null reste intouchable).
- Une seconde source de vérité des présences.

## 3. Contraintes

- Règles de `CLAUDE.md` (RLS, `BelongsToTenant`, français, TDD, `TenantAwareJob`, `TenantStorage`, `$auditPersonal`, aucune valeur personnelle dans les journaux).
- Mois clôturé (`Closures::assertOpen`) : ni ouverture de rattrapage, ni signature, ni confirmation.
- Une signature par (créneau, inscription) : **unique existant gardé** ; un rattrapage n'est possible que pour un attendu **sans signature** sur ce créneau.
- Le stagiaire doit être **attendu** ce jour-là (`Attendances::expected`, date d'entrée et de sortie).
- Envoi d'e-mails par `TenantMailer` en tâche de fond, après commit, motif d'échec sans texte du serveur ; modèles d'e-mails paramétrables (Administration › Modèles d'e-mails).

## 4. Approches envisagées

1. **Étendre l'émargement existant** — **retenue**. Moins de code, droits, clôtures, feuille et RGPD déjà branchés.
2. Réutiliser `Core\Signatures` pour le rattrapage à distance — écartée : pensé « un document, un signataire, PDF re-rendu » ; deux mécanismes à maintenir. On en reprend seulement les **règles** du code e-mail.
3. Module à part — écarté (duplication).

## 5. Décisions de l'utilisateur

- Les deux rattrapages (sur place et à distance).
- Signature de rattrapage **confirmée par un gestionnaire** avant d'écrire la présence (option B).
- Rattrapage à distance **avec code par e-mail**.

## 6. Conception

### 6.1 Données

`attendance_signatures` (existante) gagne :
- `kind` : `on_time` (défaut, signatures existantes) | `catch_up_onsite` | `catch_up_remote` ;
- `confirmed_at`, `confirmed_by` (null tant que non confirmé ; toujours null pour `on_time`) ;
- `refused_at`, `refused_by`, `refusal_reason` (300) — rattrapage refusé, signature gardée comme trace ;
- `source` reste `online` (ou la clé d'un connecteur).

Nouvelle table `attendance_catch_up_links` (RLS, `BelongsToTenant`) : `id`, `tenant_id`, `slot_id`, `enrolment_id`, `email` (figé à l'envoi), `token_hash` (sha256, unique), `expires_at` (7 jours), `used_at`, `revoked_at`, `code_hash` (HMAC comme `Core\Signatures`), `code_expires_at`, `code_attempts`, `code_sent_at`, `email_status`, `email_error_code`, `created_by`, timestamps. Un lien actif au plus par (créneau, inscription) : un nouvel envoi révoque le précédent.

Nouvelle colonne sur `attendance_sign_slots` : `catch_up_token_hash` (sha256, nullable, unique), `catch_up_opened_at`, `catch_up_opened_by`, `catch_up_expires_at` — le **mode tablette de rattrapage** d'un créneau passé. *(Appel du concepteur : un jeton distinct de `token_hash` pour ne jamais rouvrir l'émargement normal d'un jour passé ; il expire à la fin du jour d'ouverture, Paris.)*

### 6.2 Rattrapage sur place

- Onglet « Présences », créneau d'un **jour passé**, ouvert au moins une fois (statut `open`, `closed` ou `validated`), mois non clôturé : bouton **Rattrapage sur place** (droit `sessions.attendance.manage` sur une séance du créneau, comme l'émargement).
- Ouvre une page plein écran (même page formateur que l'émargement, mode « rattrapage » affiché en bandeau : « Rattrapage du lundi 21 novembre, matin ») avec **Ouvrir la page de signature ici** (mode tablette). Pas de QR code par défaut *(appel du concepteur : sur place, l'appareil passe de main en main ; le QR reste disponible sous « Afficher le QR code » si besoin)*.
- Page publique `emargement/r/{token}` (même gabarit `public/attendance-signing`, bandeau « Rattrapage ») : ne liste que les attendus **sans signature** de ce créneau ; prénom + initiale ; signer → `kind = catch_up_onsite`, `signed_at` = maintenant.
- Le jeton s'arrête : à la fermeture par le gestionnaire, à la fin du jour (Paris), ou à la clôture du mois.

### 6.3 Rattrapage à distance

- Même onglet / page du créneau : pour chaque attendu sans signature, **Envoyer un lien de rattrapage** (adresse e-mail de la fiche, modifiable ; refus clair s'il n'y en a pas). Modèle d'e-mail `attendance_catch_up` (`{lien}` obligatoire, `{date}`, `{demi_journee}`, `{session}`, `{organisme}`).
- Page publique `emargement/l/{token}` : « Bonjour Léa, signez votre présence du lundi 21 novembre, matin — <session> » ; case « Je confirme avoir été présent(e) à cette demi-journée » ; **Recevoir mon code** (modèle `attendance_catch_up_code`, `{code}`) ; pad de signature ; code ; **Signer**. Même règles que `Core\Signatures::sendCode` / `sign` (10 min, 5 essais, 1 par minute ; dessin vérifié avant le code).
- Signature : `kind = catch_up_remote`, lien `used_at`. Lien inconnu, expiré, révoqué, utilisé, mois clôturé, inscription plus attendue, session annulée ou à la corbeille : même page 404 que l'émargement.
- Throttles : réutiliser `attendance-signing` / `attendance-signing-post` par jeton.

### 6.4 Confirmation

- Onglet « Présences » de la session, mois choisi : bloc **Rattrapages à confirmer** (nombre en pastille) : stagiaire, demi-journée, type, heure de signature, dessin en vignette. Actions **Confirmer** / **Refuser** (motif), droit `sessions.attendance.manage` avec un périmètre autre que « Ses séances » *(appel du concepteur : le formateur ne confirme pas lui-même des heures qui seront facturées ; comme la génération de la feuille signée)*.
- **Confirmer** (transaction, mêmes verrous que la validation) : la signature compte comme signée ; pour chaque séance du créneau, la présence du stagiaire est **recalculée** par la règle de la validation (§ 6.3 de la spec d'origine) **si** le créneau est validé et la ligne de présence est `signing` ou absente ; une saisie manuelle n'est jamais touchée (le bloc l'indique : « Présence saisie à la main : non modifiée »). Créneau pas encore validé : confirmer est permis, la validation à venir en tiendra compte.
- Seules les signatures `on_time` et les rattrapages **confirmés** comptent dans le calcul des présences.
- Journal : `attendance_signature` confirmé / refusé (ids, sans nom ni motif en clair : `refusal_reason` dans `$auditPersonal`).

### 6.5 Feuille signée

Chaque cellule rattrapée porte le dessin et la mention (§ 2). Non confirmée : « Rattrapage à confirmer ». Refusée : « Rattrapage refusé » (sans dessin). Annexe : méthode du rattrapage (« sur place, appareil de l'organisme » / « à distance, lien personnel et code e-mail à usage unique »), empreintes des dessins.

### 6.6 RGPD

- Anonymisation (`SigningAnonymisation`) : comme les signatures existantes ; en plus, liens de rattrapage de la personne : `email` effacé, lien révoqué.
- Export RGPD : le type de chaque émargement (à l'heure, rattrapage sur place, à distance) et sa confirmation.

### 6.7 Documentation

`tms/docs/sessions.md` (section « Rattrapage »), `tms/docs/admin.md` (modèles d'e-mails), `tms/docs/data-dictionary.md`, `CLAUDE.md` (deux lignes), `CHANGELOG.md`.

## 7. Laissé au concepteur du plan

- Noms des classes, découpage des migrations, forme des réponses JSON et des props.
- Partage du code HMAC avec `Core\Signatures` (extraire un petit utilitaire si c'est simple, sinon dupliquer les quelques lignes, testées).
