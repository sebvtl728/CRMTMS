# Livret d'apprentissage, partie B — suivi en ligne

Statut : **validée** (25/11/2026). Suite de `2026-11-23-livret-apprentissage-design.md` (partie A faite : livret PDF pré-rempli, `Admissions/Booklet/`, grille de compétences du Catalogue, dispenses, réglages).

## Intention

Les formateurs de CAP FORMATION remplissent aujourd'hui à la main le suivi mensuel, les grilles A / ECA / NA et les visites d'un livret papier de 60 pages par apprenti, puis courent après les signatures du tuteur et de l'apprenti. **But : gagner du temps.** Le formateur saisit sa part dans le TMS, l'apprenti et le tuteur remplissent la leur par un lien sécurisé et signent en ligne, et le livret PDF de la partie A sort complet.

## Découpage (validé)

- **B1 — saisie interne** (pour le test de début décembre) : droit et écran formateur « Mes apprentis », grilles A / ECA / NA colonne centre, visites, observations « centre » et visa du formateur ; le PDF reprend tout.
- **B2 — liens mensuels** (pour le test) : l'apprenti puis le tuteur reçoivent un lien par mois, remplissent leur part, signent (dessin + code par e-mail) ; le PDF imprime textes et signatures. Envoi par un bouton.
- **B3 — après le test** : envoi automatique chaque mois et rappels (`Core\Automation\DailyAutomations`), mesures Qualiopi (indicateurs 11 et 28, `Qualite\Public\Measures`), ligne « À traiter ».

## Approches (choisie : A)

- **A. Suivi propre au livret, sur le modèle de l'émargement en ligne** — tables du livret dans `Admissions/Booklet`, lien par mois et par signataire (sha256 seul), signature sur la saisie elle-même avec `SignatureDrawing` et un code HMAC ; le livret régénéré imprime dessins et heures. Simple, adapté à deux signataires et à un contenu saisi en ligne.
- B. `Core\Signatures` : écarté. Le socle signe un PDF figé par un seul signataire ; il faudrait deux PDF par mois (24 par an et par apprenti), impossibles à réunir proprement dans le livret.
- C. Espace permanent par personne (un lien pour tout le contrat) : écarté, lien valable 1 à 3 ans, contraire à l'esprit ASVS et à la demande (lien mensuel).

## Décisions de l'utilisateur

1. B1 + B2 pour le test, B3 ensuite.
2. Le **représentant légal** signe **à la main** sur le PDF (sa case reste vide) ; jamais de lien pour lui.
3. Les **formateurs** saisissent eux-mêmes (pas le bureau seul) : nouveau droit à périmètre « Ses séances » et écran « Mes apprentis ».
4. Approche A.

## Ce que voit chacun

### La page mensuelle (modèle CAP, inchangé)

Chaque mois du contrat a une page **« entreprise »** et, si l'apprenti a des séances en centre ce mois-là (règle de la partie A), une page **« centre »**. Chaque page : semaines 1, 2, 3, 4-5 ; colonne apprenti (travaux de la semaine) ; colonne observations (du **tuteur** pour la page entreprise, du **formateur** pour la page centre) ; quatre signatures (tuteur, représentant légal, apprenti, formateur).

Qui fait quoi :

| Page | Colonne apprenti | Observations | Signatures en ligne |
|---|---|---|---|
| Entreprise | apprenti (lien) | tuteur (lien) | apprenti, tuteur ; visa formateur |
| Centre | apprenti (lien) | formateur (TMS) | apprenti ; visa formateur |

Le **visa du formateur** = case « Visé » dans le TMS (nom de l'utilisateur et date imprimés), pas de dessin. Le tuteur ne signe que la page entreprise (il ne voit pas la page centre).

### Formateur (TMS)

- Menu **« Mes apprentis »** : les candidatures avec contrat d'alternance que l'utilisateur peut suivre (droit ci-dessous) ; colonnes apprenti, entreprise, formation, mois en attente. Également un lien **« Suivi du livret »** sur la carte Alternance de la candidature (une ligne ajoutée à la carte : à faire après fusion de la branche « Suivi de formation », pour ne pas se marcher sur `show.tsx`).
- Page **« Livret de <apprenti> »** (`/admissions/livret/{candidature}`), trois onglets :
  - **Suivi mensuel** : les mois du contrat jusqu'au mois en cours (Paris), du plus récent au plus ancien. Par mois : état de chaque part (« À envoyer », « Envoyé le … », « Rempli », « Signé le … », « Signé sur papier »), textes de l'apprenti et du tuteur en lecture, observations « centre » modifiables, case « Visé ». Boutons « Envoyer à l'apprenti », « Envoyer au tuteur », « Renvoyer », « Signé sur papier » (B2).
  - **Grille** : l'arbre de compétences de la formation, savoir-faire par savoir-faire, A / ECA / NA côté **centre** (modifiable) et côté **entreprise** (lecture seule, rempli par le tuteur).
  - **Visites** : date, points abordés, formateur (l'utilisateur, modifiable dans la liste des formateurs de l'apprenti), ajout / modification / suppression. Les émargements de visite restent à la main sur le PDF.
- Bouton « Livret d'apprentissage (PDF) » (celui de A), accessible aussi avec le nouveau droit.

### Apprenti (lien, B2)

Page publique `livret/{token}` : en-tête (organisme, mois, entreprise, formation) ; pour chaque page du mois (entreprise, centre) ses quatre cadres « Semaine… » (texte, 2 000 caractères chacun), enregistrés au fil de l'eau ; puis **« Signer »** : « Recevoir mon code » (e-mail), saisie du code à 6 chiffres + dessin, envoi. Une seule signature de l'apprenti couvre les deux pages du mois. Après signature : page en lecture seule, lien fermé.

### Tuteur (lien, B2)

Même page : textes de l'apprenti (lecture, page entreprise seulement), ses quatre cadres « Observations », puis la **grille côté entreprise** (A / ECA / NA par savoir-faire, valeurs déjà saisies les mois précédents reprises), puis la même signature. Après signature : lecture seule, lien fermé.

## Règles

### Envoi (B2 : manuel)

- « Envoyer à l'apprenti » : possible pour un mois commencé (jamais un mois futur), si la personne a un e-mail (sinon bouton grisé : « Ajoutez l'e-mail de l'apprenti sur sa fiche »). Même règle pour le tuteur (`tutor_person_id` du contrat, e-mail de sa fiche CRM ; sans tuteur : « Indiquez le tuteur dans le contrat »).
- **Enchaînement** : quand l'apprenti a signé, le lien du tuteur part tout seul (s'il a un e-mail et qu'aucun lien tuteur n'est actif). Le formateur peut aussi l'envoyer avant, sans attendre l'apprenti ; le tuteur voit alors les cadres apprenti tels qu'ils sont.
- Un lien par (candidature, mois, signataire) actif à la fois : « Renvoyer » crée un nouveau jeton et ferme l'ancien.
- Validité : **30 jours** (jours de Paris, jusqu'à 23 h 59). Un lien expiré ou fermé = même page 404 que tout lien invalide.
- Le destinataire est l'e-mail de la fiche **au moment de l'envoi**, figé sur le lien (affiché dans l'état).

### Signature

- Code à 6 chiffres envoyé par e-mail, haché HMAC, 10 minutes, 5 essais, un envoi par minute (mêmes constantes que `Core\Signatures` / rattrapage d'émargement).
- Dessin obligatoire, validé par `SignatureDrawing` (PNG relu par GD, 200 Ko, non vide), archivé dans `TenantStorage` zone `booklet_signatures`.
- On garde : heure, IP, navigateur, nom du signataire figé (prénom NOM de la fiche à l'envoi).
- Une signature fige la part du signataire pour ce mois (ses textes ; pour le tuteur, la grille entreprise telle qu'envoyée reste modifiable seulement par un lien suivant).
- **« Signé sur papier »** (formateur) : marque la part (apprenti ou tuteur) comme signée à la main, ferme son lien ; le PDF laisse alors la case vide pour la signature manuscrite et imprime « Signé sur papier ».
- Le **visa** du formateur fige ses observations « centre » du mois ; « Retirer le visa » est possible (journalisé).
- Le formateur ne modifie jamais un texte de l'apprenti ou du tuteur.

### Grille de compétences

- La grille vit en jsonb sur la formation, sans identifiants. Une évaluation est clée par **`skill_key` = sha256 du chemin normalisé** (titre AT | titre CP | savoir-faire, espaces réduits, minuscules). Si la grille de la formation change, les évaluations dont la clé n'existe plus sont gardées mais ni affichées ni imprimées (mention « N évaluations portent sur des savoir-faire retirés »).
- Une valeur par (candidature, savoir-faire, côté) : la dernière saisie l'emporte (pas d'historique par mois). Côté centre : utilisateur ; côté entreprise : lien tuteur. Valeurs `A`, `ECA`, `NA` ou vide.

### Droit et périmètre

- Nouveau droit **`admissions.booklet.manage`** « Suivre le livret d'apprentissage » (thème « Livret d'apprentissage ») : voir la page du livret et le PDF, saisir observations centre, grille centre, visites, visa, envoyer les liens.
- Périmètres offerts : **« Ses séances »** (apprentis dont la session du dossier compte au moins une séance non annulée du formateur lié à l'utilisateur, lu par une nouvelle méthode de `Sessions\Public\Admission`), « Ses DAS » (`candidatures.das_id`), « Tout ». Pas « Ses fiches ».
- Défauts (mon choix, réglable) : Formateur → « Ses séances » ; administrateur → « Tout » ; responsable DAS, chargé de relations, assistant → même périmètre que leur `admissions.candidatures.update`. `Scopes::trainerOnly` reste vrai pour un formateur qui n'a que ce droit en plus (menu réduit + « Mes apprentis »).
- Le formateur pur ne voit **pas** la candidature elle-même (ni données sensibles) : seulement la page du livret. `Route::bind` de la page du livret : 404 hors périmètre.

### PDF (reprise dans `BookletPdf`)

- Pages mensuelles : textes de l'apprenti, observations (tuteur ou formateur), dessins de signature (data URI) avec « Signé en ligne le JJ/MM/AAAA à HH:MM » sous chacun, ou « Signé sur papier » ; visa : « Visé par <nom> le … » ; case représentant légal toujours vide.
- Grille : un « X » dans la colonne de la valeur, centre et entreprise.
- Visites : les visites saisies, puis des lignes vides pour atteindre 6 (au moins 2 vides).
- Toujours rendu à la demande, jamais archivé (inchangé).

## Données (Admissions, toutes avec `tenant_id`, `BelongsToTenant`, RLS)

- `booklet_months` : `candidature_id`, `month` (date, 1er du mois), `place` (`company` | `centre`), `apprentice_entries` jsonb (`w1`, `w2`, `w3`, `w45`), `observations` jsonb (mêmes clés), `apprentice_signed_at`, `apprentice_signature_path`, `apprentice_signer_name`, `apprentice_paper` bool, `tutor_signed_at`, `tutor_signature_path`, `tutor_signer_name`, `tutor_paper` bool, `visa_by`, `visa_at`, IP / navigateur des signatures ; unique (`candidature_id`, `month`, `place`). Ligne créée au premier envoi ou à la première saisie. (L'apprenti signe une fois par mois : sa signature est écrite sur les deux lignes du mois.)
- `booklet_links` : `candidature_id`, `month`, `signer` (`apprentice` | `tutor`), `person_id`, `email`, `token_hash` (sha256), `expires_at`, `sent_at`, `email_status`, `closed_at`, `code_hash`, `code_expires_at`, `code_attempts`, `code_sent_at`, `created_by` (null quand l'enchaînement l'envoie) ; index unique partiel « un lien ouvert par (candidature, mois, signataire) ».
- `booklet_evaluations` : `candidature_id`, `skill_key`, `side` (`centre` | `company`), `level` (`A` | `ECA` | `NA`), `updated_by` (null pour le tuteur), `updated_at` ; unique (`candidature_id`, `skill_key`, `side`).
- `booklet_visits` : `candidature_id`, `visited_on`, `points` (texte, 3 000), `trainer_user_id`, `created_by`, suppression logique.
- Mise à jour de `tms/docs/data-dictionary.md`.

## Envois et pages publiques

- Modèles d'e-mails (registre `MailTemplateRegistry`, `{lien}` requis) : `booklet_apprentice`, `booklet_tutor`, `booklet_code`. Variables : `prenom`, `nom`, `mois`, `apprenti`, `entreprise`, `formation`, `lien`, `date_limite`, `organisme`, `code` (pour le code).
- Job `SendBookletLink` (`TenantAwareJob`, `ShouldBeEncrypted`, après commit, `TenantMailer`, jeton en clair seulement dans la charge chiffrée, échec sans texte du serveur, `email_status`). Le code part de même (`SendBookletCode`).
- Routes publiques hors `auth` : `livret/{token}` (page), `livret/{token}/save`, `livret/{token}/code`, `livret/{token}/sign` ; `throttle:booklet-public` **par jeton + adresse** ; `findByToken` renvoie null pour tout cas invalide (inconnu, expiré, fermé, candidature à la corbeille ou anonymisée, contrat supprimé) → même page 404.
- Le tuteur ne reçoit jamais : textes de la page centre, données de la candidature autres que nom de l'apprenti, entreprise, formation, mois.

## Frontières

- Tout dans `Admissions/Booklet/`. Admissions lit la grille par `Catalogue\Trainings::competences`, les séances et formateurs par `Sessions\Public\Admission` (nouvelle méthode : candidatures / dossiers d'un formateur, et `Trainers::forUser` côté Sessions), les fiches par `Crm\Public\Fiches`. Aucun autre module n'importe le livret.
- Envoi par `Connectors\Smtp\TenantMailer` (déjà permis pour Admissions).

## RGPD et audit

- `Auditable` sur `booklet_months` (sans les textes : `apprentice_entries`, `observations` dans `$auditPersonal`), `booklet_evaluations`, `booklet_visits` (`points` dans `$auditPersonal`). Liens non audités ; adresse e-mail jamais journalisée.
- **Anonymisation** (`CandidatureAnonymisation`) : efface textes, observations, points des visites, dessins de signature (fichiers), IP, navigateur, noms figés, e-mails des liens ; ferme les liens. Les évaluations A / ECA / NA restent (sans donnée personnelle).
- **Export** (`PersonCandidaturesExport`) : section « livret » (mois, textes, signatures avec dates, évaluations, visites).
- Purge : celle des candidatures (`tms:admissions:purge`) ne touche pas un alternant ; rien de nouveau.

## Non-buts

- Signature en ligne du représentant légal ; émargement en ligne des visites ; PDF signé et archivé par mois ; historique des évaluations mois par mois ; espace permanent tuteur ou apprenti ; envoi automatique et rappels (B3) ; mesures Qualiopi (B3).

## Anti-buts

- Un lien qui laisse voir à un tuteur les données d'un autre apprenti ou les données sensibles de la candidature.
- Un formateur qui pourrait modifier ce que l'apprenti ou le tuteur a signé.
- Une saisie perdue : chaque cadre s'enregistre au fil de l'eau, avant la signature.

## B3 — validé le 25/11/2026

- **Envoi mensuel** : automatisme `booklet` (`Core\Automation\DailyAutomations`, déclaré par Admissions). Chaque jour d'un mois, pour chaque contrat d'alternance couvrant le **mois écoulé** (candidature hors corbeille, non anonymisée), le lien du mois écoulé part à l'apprenti, sauf si sa part est signée (en ligne ou papier) ou si un lien apprenti est ouvert pour ce mois ; une fois par mois et par candidature (`AutomationSends` `booklet`, occurrence `<AAAA-MM>:apprentice`) ; un envoi impossible (pas d'e-mail) est retenté les jours suivants. Le lien du tuteur suit la signature de l'apprenti (B2).
- **Rappels** : à J+7 et J+14 du **premier** lien d'une part (apprenti ou tuteur) d'un mois, si la part n'est ni signée ni signée sur papier et qu'un lien est ouvert : nouveau lien (l'ancien est fermé) ; `AutomationSends` `booklet_reminder`, occurrence `<AAAA-MM>:<signataire>:<1|2>` ; un rattrapage n'envoie qu'un e-mail.
- **Réglage** `automation.booklet` (Administration › Automatismes), **désactivé par défaut** ; « Exécuter maintenant » le déclenche.
- **Qualiopi** : indicateur 11 « Savoir-faire évalués côté centre (apprentis en cours) » ; indicateur 28 « Pages entreprise des mois écoulés signées par le tuteur (apprentis en cours) » et « Visites en entreprise sur 12 mois » (visites / apprentis dont le contrat couvre la période).
- **À traiter** : « Livrets avec des pages non visées depuis plus de 30 jours » (une page d'un mois fini depuis plus de 30 jours sans visa), droit `admissions.booklet.manage` et son périmètre, lien `/admissions/livret`.

## Laissé au constructeur

Mise en page exacte, libellés des boutons, découpage des contrôleurs, enregistrement au fil de l'eau (minuterie ou à la sortie du champ), ordre des tâches. Mes choix, à vérifier par l'utilisateur : défauts du nouveau droit, `skill_key` par hachage, enchaînement automatique vers le tuteur, une signature apprenti pour les deux pages du mois.
