# Émargement en ligne — conception

Date : 2026-11-20 · Module : Sessions (`tms/app/Modules/Sessions/Attendance/Signing/`) · Version visée : 0.11.x (CHANGELOG « Non publié »)

## 1. Intention

CAP FORMATION présente le TMS le 30 novembre 2026 et le fait tester par une équipe réelle début décembre. L'émargement papier (feuille imprimée + scan, 0.8.0) existe ; il faut une **version en ligne** : les stagiaires signent leur présence au doigt, sans compte, depuis un **QR code affiché par le formateur**, une fois par **demi-journée** ; le formateur signe et valide ; les présences du TMS se remplissent seules ; une **feuille d'émargement PDF signée**, archivée et scellée (sha256), sert de preuve Qualiopi et financeur.

Version simple, solide, démontrable. Le Conseil régional exigera SoWeSign : pas d'adaptateur maintenant, mais une frontière qui permettra un connecteur dans `app/Modules/Connectors` plus tard.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Deux créneaux par jour et par session : **matin** et **après-midi**, coupés à **13 h** (comme la feuille papier).
- Un QR code commun par créneau, affiché par le formateur (écran, vidéoprojecteur) ; le même écran sert en « mode tablette » (on passe l'appareil).
- Le stagiaire choisit son nom parmi les attendus et dessine sa signature ; un nom signé n'est plus proposé.
- Le formateur suit en direct, signe, valide : les présences s'écrivent par le code existant (`Attendances::record`).
- Feuille PDF signée par session et par mois, archivée (`TenantStorage`), empreinte sha256, téléchargeable.
- RGPD : dessins, adresse IP et navigateur effacés par l'anonymisation ; rien de personnel dans les journaux.

**Non-objectifs (plus tard)**
- Lien personnel par stagiaire envoyé par e-mail ; code par SMS.
- Adaptateur SoWeSign (seulement la frontière).
- Temps réel par websockets (interrogation périodique suffit).
- Émargement des heures « par mois » des modules à intervalle (`MonthlyHours`) : hors périmètre, ils restent saisis à la main.

**Anti-objectifs (échec même si « ça marche »)**
- Une seconde source de vérité des présences : la grille `attendances` reste la seule ; l'émargement l'alimente.
- Un lien public qui révèle le nom complet des stagiaires, leurs coordonnées, ou dont l'invalidité se distingue (expiré / inconnu / validé = même page 404).
- Un stagiaire bloqué en pleine séance (dépendance à un e-mail, à un compte, au réseau du formateur).
- Toucher aux zones des sessions parallèles (Finance / facture libre, questionnaires de satisfaction).

## 3. Contraintes

- Règles de `CLAUDE.md` : `tenant_id` + `BelongsToTenant` + RLS sur toute table ; français ; TDD ; jobs `TenantAwareJob` ; `TenantStorage` pour tout fichier ; cache préfixé tenant ; journal sans valeur personnelle (`$auditPersonal`).
- Sessions ne nomme aucune table du CRM (`ModuleBoundariesArchTest`) : noms par `Crm\Public\Fiches::personLabels`.
- Lien public : organisme donné par l'hôte (comme `test-positionnement/{token}`) ; seul le sha256 du jeton est stocké.
- Gotenberg (`PdfRenderer`) est le seul moteur PDF ; gabarit Blade `{{ }}` seulement, aucune ressource externe (dessins en data URI).
- `bacon/bacon-qr-code` est déjà une dépendance (2FA) : QR rendu côté serveur en SVG, aucun service externe.

## 4. Approches envisagées

1. **Extension du module Sessions** (`Attendance/Signing/`), porte publique `Sessions\Public\AttendanceSigning` pour un futur connecteur. — **Retenue** par l'utilisateur : le moins de code neuf, déjà branché sur la visibilité, les droits présences, les clôtures et le RGPD des inscriptions.
2. Généraliser `Core\Signatures` : écartée, il est pensé « un document, un signataire, un code e-mail, un PDF re-rendu » ; le plier à « 15 stagiaires, une page commune » le fragiliserait.
3. Module `Emargement` à part : écartée pour l'échéance ; tout passerait par des services publics de Sessions (séances, attendus, présences) et les tests d'architecture, pour un gain que la porte publique de l'approche 1 donne déjà.

## 5. Décisions de l'utilisateur

- Unité de signature : **deux créneaux, matin et après-midi, coupés à 13 h**.
- Identification : **QR code commun par demi-journée**, affiché par le formateur.
- Architecture : approche 1.
- Validation : **le formateur de la séance, ou un gestionnaire de présences** à défaut ; la feuille nomme le valideur et sa qualité.

## 6. Conception

### 6.1 Créneau (`attendance_sign_slots`)

Un créneau = (session, jour de Paris, `half` ∈ `morning|afternoon`). Il couvre **toutes les séances non annulées** de la session ce jour-là qui chevauchent la demi-journée (`start < 13:00` pour le matin, `end > 13:00` pour l'après-midi, même règle que `AttendanceSheetPdf`). Unique (`tenant_id`, `training_session_id`, `date`, `half`).

Colonnes : `id`, `tenant_id`, `training_session_id`, `date`, `half`, `token_hash` (sha256, unique, null une fois fermé/validé), `status` (`open|closed|validated`), `opened_at`, `opened_by`, `validated_at`, `validated_by`, `trainer_signature_path` (PNG, zone `attendance_signatures`), `trainer_signature_sha256`, `trainer_name` (nom affiché du valideur, figé), `validator_role` (`trainer|manager`), timestamps.

- **Ouvrir** (`AttendanceSigning::open`) : créé au premier clic, ou rouvert s'il est `closed` (nouveau jeton, l'ancien meurt). Seulement **le jour même** (Paris), session non annulée, au moins une séance du créneau non annulée, mois non clôturé (`Closures::assertOpen`). Le créneau validé ne se rouvre pas (409).
- **Fermer** : jeton effacé, signatures gardées ; rouvrir possible le même jour.
- **Fin de journée** : un jeton n'est utilisable que si `date` = aujourd'hui (Paris) ; aucun traitement planifié nécessaire.
- **Droit** : `sessions.attendance.manage` sur **au moins une** séance du créneau dans son périmètre (`SessionsVisibility::seances`) ; « Ses séances » = le formateur d'une de ces séances. Sinon 404.

### 6.2 Signature d'un stagiaire (`attendance_signatures`)

Colonnes : `id`, `tenant_id`, `slot_id`, `enrolment_id`, `drawing_path` (null après anonymisation), `drawing_sha256`, `signed_at`, `source` (`online` ; une clé de connecteur plus tard), `ip`, `user_agent` (≤ 300), timestamps. Unique (`slot_id`, `enrolment_id`).

- Attendus d'un créneau = union des `Attendances::expected($seance)` de ses séances (même jour : même règle d'entrée/sortie).
- Signer (`AttendanceSigning::sign`, transaction, verrou du créneau) : créneau `open`, jeton valide, aujourd'hui, inscription attendue, pas déjà signée (409 « Vous avez déjà signé. »). Dessin validé par `Core\Signatures\SignatureDrawing` (PNG relu par GD, ≤ 200 Ko, non vide), archivé sous `TenantStorage` zone `attendance_signatures` (`<session>/<slot>/<signature>.png`).
- **Effacer une signature** (formateur ou gestionnaire, créneau non validé) : quelqu'un a signé sous le mauvais nom. Fichier supprimé, ligne supprimée, journalisé sans nom.
- Journal : `attendance_signature` audité avec ids et `signed_at` ; `drawing_path`, `ip`, `user_agent` dans `$auditPersonal`.

### 6.3 Validation par le formateur

`AttendanceSigning::validate($slot, $drawing, $user)` en une transaction : verrou du créneau, dessin du valideur obligatoire (`SignatureDrawing`), créneau → `validated`, jeton effacé, signature archivée, `trainer_name` = nom de l'utilisateur, `validator_role` = `trainer` (il est le formateur d'une séance du créneau) ou `manager`. Le valideur doit atteindre **toutes** les séances non annulées du créneau avec `sessions.attendance.manage` : un formateur dont le créneau contient la séance d'un autre formateur reçoit 403 « Une autre séance de cette demi-journée est animée par un autre formateur : un gestionnaire des présences la valide. ». La feuille affiche « Validé par Marie Martin (formatrice) » ou « (gestionnaire des présences) ».

Puis, pour chaque séance non annulée du créneau, pour chaque stagiaire attendu, la présence est **calculée sur les demi-journées validées** de cette séance :
- signé sur toutes les demi-journées que la séance chevauche (toutes validées) → `present` ;
- signé sur une partie seulement, ou une demi-journée de la séance pas encore validée → `partial`, minutes = part de la durée de la séance au prorata des minutes d'horloge des demi-journées signées (`round(duration × chevauchement / (fin − début))`, bornée à `1 … duration − 1`) ;
- aucune signature sur les demi-journées validées → `absent`.

Ainsi une séance 9 h–17 h validée le matin seulement montre déjà « partiel » ; la validation de l'après-midi la recalcule (« présent » si signé deux fois). Une demi-journée oubliée se corrige à la main dans la grille.

Une présence **saisie à la main** n'est jamais écrasée : nouvelle colonne `attendances.source` (`null` = saisie, `signing` = écrite par l'émargement) ; `Attendances::record` la remet à `null`, l'émargement ne réécrit que les lignes `signing` ou absentes. Écriture par une méthode interne d'`Attendances` (même verrou de séance, mêmes règles). **Toute la validation est une transaction** : mois clôturé → 409 « Ce mois est clôturé… », rien n'est validé ; une séance annulée entre-temps est ignorée.

**Validation tardive** (appel du concepteur) : l'ouverture aux stagiaires n'existe que le jour même ; la **validation** reste possible les jours suivants tant que le mois n'est pas clôturé (créneau `open` ou `closed`). L'onglet « Présences » signale les créneaux ouverts non validés d'un jour passé (« à valider »).

Avant de valider, l'écran montre qui a signé, qui manque, et prévient : « Les stagiaires qui n'ont pas signé seront notés absents. »

### 6.4 Écrans

**Formateur / gestionnaire (authentifié)**
- Point d'entrée : dans la grille de présences d'une séance (`seance-details.tsx` / « Saisir les présences », y compris depuis « Mon planning »), un bouton **Émargement en ligne** (créneau(x) du jour seulement) ; et dans l'onglet « Présences » de la session, l'état de chaque créneau (ouvert, validé, nb signés / attendus).
- Page plein écran `sessions/emargement/{slot}` : QR code (SVG serveur) + lien court en clair, liste des attendus avec ✓ / en attente (interrogation JSON toutes les 5 s), actions : effacer une signature, fermer, **Signer et valider** (pad `signature-pad.tsx`).
- Mode tablette : bouton « Ouvrir la page de signature ici » (même page publique sur l'appareil du formateur).

**Stagiaire (public, sans compte)** — `emargement/{token}`, page Inertia `public/attendance-signing` :
- En-tête : organisme, session (libellé), date, « Matin » / « Après-midi ».
- Liste des attendus non encore signés, **prénom + initiale du nom** (« Léa D. ») ; homonymes départagés par un rang (« Léa D. (2) »). Jamais nom complet, e-mail ni autre donnée.
- Choisir son nom → pad de signature → « Je signe ma présence » → écran « Merci, votre présence est enregistrée », puis retour à la liste (mode tablette).
- Lien inconnu, mal formé, fermé, validé, d'un autre jour, session à la corbeille : **même page 404** « Ce lien d'émargement n'est plus valide. Demandez au formateur. »
- Throttle `attendance-signing` : par jeton + adresse (une classe derrière une seule IP : ~60 req/min par jeton), signature `attendance-signing-post` plus strict (~30/min par jeton).

### 6.5 Feuille d'émargement signée (PDF)

`SignedSheetPdf` + gabarit `documents/attendance-signed-sheet.blade.php` : par **session et par mois** (même découpage que les clôtures). Contenu : organisme (nom, logo), session, période ; par jour : créneaux (heures, modules, formateur), tableau stagiaire × matin / après-midi avec le **dessin** et l'heure de signature, ou « absent », et la présence finale de la grille (statut + durée) ; signature du formateur et heure de validation par créneau ; mention de la méthode (« signature électronique simple, lien d'émargement à usage unique par créneau, horodatage serveur ») ; empreinte de chaque dessin en annexe.

- Bouton **Générer la feuille signée** dans l'onglet « Présences » (mois choisi) : rendu Gotenberg, archivé (`TenantStorage` zone `attendance_sheets`, `signed-<AAAA-MM>-<horodatage>.pdf`), ligne `attendance_signed_sheets` (`training_session_id`, `month`, `path`, `pdf_sha256`, `generated_by`, `generated_at`). Chaque génération = nouvelle version, jamais écrasée ; la liste montre les versions, la plus récente en tête, et son empreinte.
- Téléchargement : sert toujours l'archive, vérifiée par sha256 (410 si perdue ou modifiée). Échec Gotenberg = 503 en français, rien d'archivé.
- Droit (appel du concepteur) : **générer** = gestionnaires de présences (`sessions.attendance.manage` avec un périmètre autre que « Ses séances », sur la session) ; **télécharger** = qui atteint une séance de la session avec ce droit (formateur compris).
- Génération possible à tout moment (appel du concepteur) : mois non clôturé ou créneaux non validés permis ; un créneau non validé apparaît « Non validé », sans signature du formateur ; un jour sans créneau ouvert apparaît « Émargement papier ou saisie manuelle » avec les présences de la grille. L'en-tête indique « Mois clôturé le … » ou « Mois non clôturé ».

### 6.6 Frontière connecteur (SoWeSign plus tard)

`Sessions\Public\AttendanceSigning` (service public, aucun type interne exposé) :
- `slots(string $sessionId, string $from, string $to): list<array>` — créneaux, attendus (ids d'inscription, personne), séances couvertes ;
- `recordExternal(string $slotId, string $enrolmentId, CarbonImmutable $signedAt, ?string $png, string $source): void` — même règles que `sign` sauf jeton ; `source` = clé du connecteur ;
- `validateExternal(string $slotId, string $source, ?string $trainerPng, string $trainerName): void`.

Un futur `Connectors\SoWeSign` les appellera depuis un job `TenantAwareJob` ; rien dans Sessions ne nomme SoWeSign. Tests : la porte publique est couverte par des tests unitaires (une source factice).

### 6.7 RGPD

- `Gdpr\EnrolmentAnonymisation` : pour les inscriptions de la personne, dessins supprimés (`drawing_path` null, fichier effacé), `ip`, `user_agent` à null ; ligne gardée (`signed_at`, preuve d'horaire).
- Feuilles signées archivées : preuve collective, gardées — sauf **session individuelle** (un seul stagiaire), supprimées comme les scans.
- Export RGPD de la personne (`PersonSessionsExport`) : ses émargements (date, demi-journée, heure de signature, source).
- La signature du formateur (utilisateur) n'est pas concernée par l'anonymisation d'un stagiaire.

### 6.8 Qualité et documentation

- Mesure Qualiopi facultative (si simple) : indicateur 11 / 19 non requis pour la démo — **non fait**.
- Docs : `tms/docs/sessions.md` (nouvelle section « Émargement en ligne »), `tms/docs/data-dictionary.md`, `CLAUDE.md` (bloc architecture court), `CHANGELOG.md` « Non publié ».

## 7. Laissé au concepteur du plan

- Nom exact des classes et contrôleurs, découpage des migrations.
- Forme des props Inertia et des réponses JSON.
- Rendu du QR (SVG inline) et taille du lien court.
- Valeurs exactes des throttles.
- Tests e2e : un scénario Playwright (ouvrir, signer sur la page publique, valider, voir la grille remplie) si le temps le permet, sous le verrou `.superpowers/e2e.lock`.
