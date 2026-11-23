# Dictionnaire de données — lots 1a (socle), 1b (fiches, import, RGPD), 1c (pipeline, actions, agenda), 1d (catalogue, devis) et 0.5.0 (droits, rôles, chiffre d'affaires)

Identifiants : UUID v7 (`uuid`). Dates : `timestamp` (UTC en base ; affichage en heure de Paris). Suppression logique : colonne `deleted_at` (les lignes supprimées restent en base).

## Isolation multi-organismes (RLS)

Toute table métier porte `tenant_id uuid not null` (clé étrangère vers `tenants`), le trait `BelongsToTenant` côté application, et une politique **PostgreSQL Row-Level Security** `tenant_isolation` créée par `Rls::enable('<table>')` :

```sql
tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid   -- using ET with check
```

`ENABLE` et `FORCE ROW LEVEL SECURITY` s'appliquent, y compris au propriétaire. L'application se connecte avec `tms_app` (non propriétaire, `NOBYPASSRLS`) ; la requête positionne `app.tenant_id` avant toute lecture. Sans organisme courant, aucune ligne n'est visible ni écrivable. Seules les migrations utilisent `tms_owner`. Un test d'architecture échoue si une table métier n'a pas `tenant_id` + RLS ; les tables hors périmètre sont listées dans `config/tms.php` (`tenancy.unscoped_tables`) : `tenants`, `tenant_domains`, `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `permissions`. Les tables des lots 1b et 1c (sections « CRM » et « Connecteurs : agenda » ci-dessous) sont toutes soumises à la RLS.

## Plateforme (hors RLS)

### `tenants` — organismes
| Colonne | Type | Rôle |
|---|---|---|
| `id` | uuid, PK | Identifiant de l'organisme |
| `name` | string | Nom de l'organisme |
| `slug` | string, unique | Identifiant court (`TMS_DEFAULT_TENANT` en mode `single`) |
| `status` | enum `active` / `suspended` | Un organisme suspendu est inaccessible (503) |
| `settings` | jsonb, défaut `{}` | Paramètres : `security.force_2fa` (bool), `security.idle_timeout_minutes` (15–480, défaut 120), `gdpr.retention_years` (entier 1–10, défaut 3 : conservation RGPD, lot 1b) |
| `created_at`, `updated_at` | timestamp | |

### `tenant_domains` — noms d'hôte (mode `multi`)
`id` (uuid PK) · `tenant_id` (FK, cascade) · `host` (string, unique : nom d'hôte servi pour cet organisme) · horodatages.

### `permissions` — catalogue des permissions (spatie, global)
`id` (uuid PK) · `name` (ex. `users.manage`) · `guard_name` · horodatages ; unique (`name`, `guard_name`). Noms globaux sans donnée d'organisme ; la matrice rôle → permission, elle, est isolée.

### `sessions` — sessions web
`id` (string PK) · `user_id` (uuid, nullable, indexé) · `ip_address` · `user_agent` · `payload` · `last_activity` (int, indexé). Le dernier instant d'activité utilisé pour l'expiration par inactivité est conservé dans le contenu de la session (clé `last_activity_at`).

### Tables techniques
`migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` : tables Laravel (files d'attente, cache).

## Accès et utilisateurs (RLS)

### `users` — comptes
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `name`, `email` | string | Nom ; e-mail unique **par organisme** (`tenant_id`, `email`) |
| `email_verified_at` | timestamp, null | Renseigné à l'activation par lien |
| `password` | string, null | Argon2id ; `null` tant que l'invitation n'est pas acceptée |
| `is_platform_admin` | bool | Réservé à l'exploitation de la plateforme |
| `remember_token` | string, null | |
| `last_login_at` | timestamp, null | Dernière connexion |
| `deactivated_at` | timestamp, null | Compte désactivé (sessions révoquées) |
| `two_factor_secret` | text, null | Secret TOTP **chiffré** (`APP_KEY`) |
| `two_factor_recovery_codes` | text, null | Empreintes sha256 des codes non utilisés, JSON **chiffré** |
| `two_factor_confirmed_at` | timestamp, null | 2FA active quand renseigné |
| `two_factor_last_used_step` | bigint, null | Dernier pas TOTP accepté (anti-rejeu) |
| `daily_digest` | bool, défaut `false` | Récapitulatif quotidien des actions par e-mail (7 h 30, Paris), choisi par l'utilisateur dans son profil |
| `created_at`, `updated_at` | timestamp | |

### `password_reset_tokens` — réinitialisation de mot de passe
PK (`tenant_id`, `email`) · `token` (haché) · `created_at`. `tenant_id` est renseigné par défaut depuis `app.tenant_id`.

### `invitations` — invitations
`id`, `tenant_id` · `email`, `name` · `role` (nom du rôle proposé : un des sept rôles système ou un rôle personnalisé `custom_<uuid>`) · `token_hash` (char 64, unique : sha256 du jeton, jamais le jeton) · `expires_at` (+24 h) · `accepted_at` (null tant qu'en attente) · `user_id` (FK users, null) compte créé · `invited_by` (FK users, null) · horodatages ; index (`tenant_id`, `email`).

### `external_identities` — identités externes (SSO, préparées)
`id`, `tenant_id` · `user_id` (FK, cascade) · `provider` · `subject` · horodatages ; unique (`provider`, `subject`).

### `roles` — rôles de l'organisme
`id`, `tenant_id` · `name` (rôle système : `admin_organisme`, `direction`, `commercial`, `charge_relations`, `assistant`, `responsable_das`, `formateur` ; rôle personnalisé : `custom_<uuid>`, jamais affiché) · `label` (string 80, null : libellé du rôle ; null = libellé par défaut du rôle système) · `guard_name` · `requires_2fa` (bool : double authentification obligatoire ; toujours vraie pour `admin_organisme` et `direction`) · `system` (bool : les sept rôles système, jamais supprimés) · horodatages ; unique (`tenant_id`, `name`, `guard_name`) ; libellé unique par organisme (contrôle applicatif sérialisé).

### `role_has_permissions` — matrice rôle → permission
`permission_id` · `role_id` · `tenant_id` ; PK (`permission_id`, `role_id`). Clés étrangères composites (`role_id`, `tenant_id`) : un rôle ne peut pas recevoir de permission d'un autre organisme.

### `model_has_roles` — rôle d'un utilisateur
`role_id` · `model_type` · `model_id` (utilisateur) · `tenant_id` ; PK (`tenant_id`, `role_id`, `model_id`, `model_type`). Un rôle principal par utilisateur.

### `model_has_permissions` — permissions directes
Mêmes colonnes que ci-dessus avec `permission_id`. Non utilisée au lot 1a.

## Sociétés (RLS)

### `companies` — entités juridiques
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `name` | string | Raison sociale |
| `legal_form` | string | `sarl`, `sas`, `sasu`, `sa`, `eurl`, `ei`, `scop`, `scic`, `association`, `collectivite`, `etablissement_public`, `autre` (contrainte CHECK) |
| `scop_underlying_form` | string, null | `sarl`, `sa` ou `sas`, uniquement pour SCOP / SCIC |
| `capital_amount` | decimal(14,2), null | Capital |
| `capital_variable` | bool | Capital variable |
| `siret` | char(14), null | 14 chiffres (CHECK) ; contrôle de clé côté application |
| `rcs_city` | string, null | Ville du RCS |
| `vat_number` | string(20), null | N° de TVA intracommunautaire |
| `vat_regime` | string | `subject` (assujetti) ou `exempt` (CHECK) |
| `nda_number` | string(30), null | N° de déclaration d'activité |
| `address_line1`, `address_line2`, `address_postal_code`, `address_city` | string, null | Adresse |
| `address_country` | char(2) | Code ISO, défaut `FR` |
| `email`, `phone` | string, null | Contact |
| `is_default` | bool | Société par défaut ; **une seule** par organisme (index unique partiel) |
| `logo_path` | string, null | Logo des devis (PNG / JPEG ≤ 1 Mo) : chemin dans le stockage privé `tenants/<id>/companies/<company_id>/logo.<png\|jpg>` ; hors journal générique (évènements `company.logo_changed` / `company.logo_deleted`, sans contenu) |
| `quote_prefix` | string(10), défaut `DEV` | Préfixe des numéros de devis, `^[A-Z0-9]{1,10}$` (CHECK) ; **unique par organisme** parmi les sociétés non supprimées (index unique partiel `companies_quote_prefix_unique`) |
| `quote_validity_days` | smallint, défaut 30 | Validité proposée d'un devis, 1–365 (CHECK) |
| `quote_terms`, `quote_footer` | text, null | Conditions générales et pied de page imprimés sur les devis (≤ 5 000 caractères) |
| `created_at`, `updated_at`, `deleted_at` | timestamp | Suppression logique |

## Modèles d'e-mails (0.4.2, RLS)

### `mail_templates` — textes d'e-mails modifiés par l'organisme
`id`, `tenant_id` · `key` string(100) (`quote.send`, `quote.remind`, `users.invitation`, `crm.daily_digest` ; déclarés par les modules via `Core\Mail\MailTemplateRegistry`) · `subject` string(200) · `body` text (≤ 10 000, texte brut avec `{variables}`) · `updated_by` (FK `users`, null à la suppression) · `created_at`, `updated_at`. Unique (`tenant_id`, `key`). Pas de ligne = modèle par défaut du code. Journalisée (`mail_template`). Cache par organisme (`<tenant_id>:mail-template:<key>`), vidé à l'enregistrement.

## Référentiels (RLS)

### `reference_items` — valeurs des listes paramétrables
`id`, `tenant_id` · `list` (liste : `das`, `pipeline_stage`, `action_type`, `action_result`, `contact_origin`, `loss_reason`, `establishment_type`, `sector`, `company_size`, `priority`, `funding_mode`, `professional_situation`, `legal_form_org`, `decision_role`) · `code` (slug, CHECK `^[a-z0-9]+(-[a-z0-9]+)*$`) · `label` · `position` (ordre) · `is_system` (non modifiable / supprimable) · `is_active` · `meta` (jsonb selon la liste : `probability` et `outcome` (`won` / `lost`) des étapes du pipeline ; `planning` (`meeting` / `call`) des types d'action affichés au planning, dans le flux iCal et envoyés vers Outlook ; `color`) · horodatages · `deleted_at`. Unique (`tenant_id`, `list`, `code`) — le code d'une valeur supprimée reste réservé.

## Connecteurs (RLS)

### `connector_configs` — configuration d'un connecteur
`id`, `tenant_id` · `connector_key` (ex. `smtp`) · `company_id` (FK companies, null = tout l'organisme) · `enabled` · `settings` (jsonb, paramètres non secrets) · `secrets` (text, tableau **chiffré** `APP_KEY`) · horodatages. Unique (`tenant_id`, `connector_key`, `company_id`) avec `NULLS NOT DISTINCT`.

### `connector_runs` — journal d'exécution
`id`, `tenant_id` · `connector_config_id` (FK, cascade) · `direction` (`in` / `out`) · `status` (`pending` / `success` / `failed`) · `summary` · `error` (sans secret) · `payload_ref` · `started_at`, `finished_at` · horodatages ; index (`tenant_id`, `started_at`).

## Connecteurs : agenda (lot 1c, RLS)

### `calendar_tokens` — liens d'abonnement iCal (« Mon agenda »)
`id`, `tenant_id` · `user_id` (FK, cascade) · `token_hash` (char 64, unique : **empreinte sha256** du jeton ; le jeton lui-même n'est jamais stocké ni journalisé) · `created_at`, `last_used_at` (au plus une écriture par minute), `revoked_at` (timestamptz, µs). Index unique partiel (`user_id`) tant que `revoked_at` est null : un seul lien actif par utilisateur ; en créer un nouveau révoque l'ancien.

### `calendar_links` — connexion Microsoft 365 d'un utilisateur
`id`, `tenant_id` · `user_id` (FK, cascade, unique) · `provider` (`microsoft365`) · `status` (`active`, `reconnect` : Microsoft a refusé le renouvellement, jetons effacés) · `access_token`, `refresh_token` (text, **chiffrés** `APP_KEY`) · `expires_at`, `connected_at` · horodatages (timestamptz, µs). **Volontairement hors journal d'audit** (pas `Auditable`) : la ligne porte des jetons ; la connexion, la déconnexion et la reconnexion demandée sont journalisées à part (`calendar.outlook_*`, sans jeton).

### `calendar_link_events` — évènements Outlook créés pour un élément (action du CRM, séance)
`id`, `tenant_id` · `calendar_link_id` (FK, cascade : déconnecter supprime les lignes) · `source` varchar(40) (non nul, sans valeur par défaut : `crm.activity` pour une action du CRM, `sessions.seance` pour une séance ; 0.8.1) · `source_id` (uuid de l'élément, ex-`activity_id`, **sans clé étrangère** : le module Connecteurs ne lit pas les tables des autres modules) · `transaction_id` (uuid envoyé à Microsoft Graph, créé avant l'envoi pour qu'une reprise ne duplique pas l'évènement) · `outlook_event_id` (512, null tant que Graph n'a pas répondu) · horodatages. Unique (`calendar_link_id`, `source`, `source_id`) ; index (`source`, `source_id`).

## Audit (RLS)

### `audit_logs` — journal d'audit (append-only)
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `user_id` | uuid, null | Auteur (null : système ou connexion échouée) |
| `event` | string | `created`, `updated`, `deleted`, `restored`, `auth.login`, `auth.failed`, `auth.logout`, `auth.2fa_enabled`, `auth.2fa_disabled`, `access.role_changed`, `users.invitation_sent`, `users.invitation_resent`, `users.invitation_revoked`, `users.deactivated`, `users.reactivated`, `settings.security_changed`, `settings.gdpr_changed`, `export.run`, `gdpr.kept`, `crm.import_undone` (et d'autres évènements `crm.*` du module) |
| `auditable_type`, `auditable_id` | string, null | Objet concerné |
| `old_values`, `new_values` | jsonb, null | Valeurs avant / après (secrets : « [masqué] ») |
| `ip` | inet, null | Adresse IP |
| `user_agent` | text, null | Navigateur |
| `created_at` | timestamptz(6) | Date de l'évènement |

Un déclencheur (`audit_logs_immutable`) refuse tout `UPDATE` et `DELETE`, même pour le propriétaire de la table. Index sur (`tenant_id`, `created_at` / `event` / `user_id` / objet).

## CRM : entreprises, personnes, rattachements (lot 1b, RLS)

Conventions communes : UUID v7, `tenant_id`, suppression logique (`deleted_at`) sur les fiches, `created_by` / `updated_by` (FK `users`, `ON DELETE SET NULL`). Les listes de valeurs (forme juridique, secteur, DAS…) sont des **clés étrangères vers `reference_items`** (jamais un code). Les valeurs des champs personnels ne sont jamais écrites en clair dans `audit_logs` : le journal enregistre le nom du champ avec la valeur `[donnée personnelle]`.

**Colonnes générées (clés de recherche et de détection de doublons).** Sous RLS, PostgreSQL n'utilise un index que pour des conditions faites de fonctions « leakproof » : `lower()`, `regexp_replace()` ou `unaccent()` ne le sont pas, un index d'expression ne servirait donc à rien. Les clés de comparaison sont donc des **colonnes générées stockées** (comparaison `=` sur du texte, leakproof) avec leur index partiel (`where … is not null and deleted_at is null`). L'application ne les écrit jamais.

### `organisations` — entreprises (clients et prospects)
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `name` | string | Raison sociale (seul champ obligatoire) |
| `siret` | char(14), null | 14 chiffres (CHECK) ; **unique par organisme** parmi les fiches non supprimées (`organisations_siret_unique`) |
| `naf_code` | string(6), null | Code NAF |
| `idcc` | string(4), null | IDCC (convention collective), 4 chiffres |
| `covered_idcc` | jsonb, null | IDCC couverts par un OPCO (liste de codes à 4 chiffres) : `Fiches::opcoFor` propose l'OPCO d'un contractant ; sans fiche qui le couvre, la table officielle embarquée (`Organisations\OfficialOpcos`, `official-opco-idcc.php`, tirée de la table SIRET-OPCO de France compétences) |
| `legal_form_id`, `sector_id`, `establishment_type_id`, `company_size_id`, `origin_id` | uuid, null | FK `reference_items` (listes `legal_form_org`, `sector`, `establishment_type`, `company_size`, `contact_origin`) |
| `status` | string | `prospect` (défaut), `client`, `inactif` (CHECK) |
| `responsible_user_id` | uuid, null | FK `users` (`SET NULL`) |
| `email`, `phone`, `website` | string, null | Coordonnées |
| `address_line1`, `address_line2`, `postal_code`, `city`, `country` | string, null ; `country` char(2) défaut `FR` | Adresse |
| `notes_summary` | text, null | Résumé libre |
| `last_contact_at` | timestamptz, null | Dernier contact (mis à jour par les notes ; base du calcul RGPD) |
| `source_system`, `source_id` | string, null | Origine d'un import (unique par organisme quand renseignés) : permet de remettre à jour la même fiche |
| `merged_into_id` | uuid, null | Fiche qui a absorbé celle-ci (fusion) |
| `merged_sources` | jsonb | Origine des champs fusionnés |
| `search_vector` | text, générée | Nom, ville, SIRET sans accents ni majuscules ; index trigramme (recherche) |
| `dup_email`, `dup_phone`, `dup_postal_code`, `dup_city` | text, générées | Clés de détection des doublons (e-mail en minuscules, téléphone en chiffres, code postal, ville sans accents) |
| `created_at`, `updated_at`, `deleted_at` | timestamp | |

### `organisation_types` — types d'une entreprise

`tenant_id`, `organisation_id` (→ `organisations`, cascade), `type_id` (→ `reference_items`, liste `organisation_type`, `meta.funder` pour les financeurs) ; clé primaire (`organisation_id`, `type_id`). Écrit par la fiche (`type_ids`), réuni par la fusion (`Merger::uniteTypes`).

### `organisation_das` — DAS d'une entreprise
`tenant_id` · `organisation_id` (FK, cascade) · `das_id` (FK `reference_items`) ; PK (`organisation_id`, `das_id`).

### `user_das` — DAS suivis par un utilisateur
`tenant_id` · `user_id` (FK, cascade) · `das_id` (FK `reference_items`) ; PK (`user_id`, `das_id`). Un utilisateur au rôle **Responsable DAS** ne voit que les fiches rattachées aux DAS de cette table. Géré depuis **Administration › Utilisateurs** (« DAS suivis »).

### `people` — personnes (particuliers et interlocuteurs)
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `civility` | string(5), null | `M.` ou `Mme` (CHECK) |
| `first_name`, `last_name` | string | Nom obligatoire, prénom facultatif |
| `email`, `email_secondary`, `phone`, `mobile` | string, null | Coordonnées (téléphones normalisés) |
| `address_line1`, `address_line2`, `postal_code`, `city`, `country` | | Adresse |
| `birth_date` | date, null | Date de naissance |
| `is_individual` | bool | Particulier (peut être aussi interlocuteur) |
| `project` | text, null | Projet de formation |
| `professional_situation_id`, `funding_mode_id`, `origin_id` | uuid, null | FK `reference_items` |
| `education_level_id` | uuid, null | FK `reference_items` (liste `education_level`, lot 2a) |
| `status` | string | `prospect`, `client`, `inactif` (CHECK) |
| `responsible_user_id` | uuid, null | FK `users` |
| `last_contact_at` | timestamptz, null | Dernier contact (base de la conservation RGPD) |
| `source_system`, `source_id` | string, null | Origine d'un import |
| `anonymised_at` | timestamptz, null | Date d'anonymisation (les données personnelles sont alors effacées) |
| `legal_basis` | string(30) | Base légale : `interet_legitime` (défaut), `consentement`, `contrat`, `obligation_legale` (CHECK) |
| `prospecting_opt_out_at` | timestamptz, null | Opposition à la prospection |
| `gdpr_kept_until`, `gdpr_kept_reason` | timestamptz / string(500), null | Conservation prolongée décidée dans l'écran RGPD, et son motif |
| `merged_into_id`, `merged_sources` | | Comme `organisations` |
| `search_vector`, `dup_email`, `dup_email_secondary`, `dup_postal_code`, `dup_city` | text, générées | Comme `organisations` |
| horodatages, `deleted_at` | | |

### `person_das` — DAS d'une personne
`tenant_id` · `person_id` (FK, cascade) · `das_id` ; PK (`person_id`, `das_id`).

### `fiche_trainings` — formations d'intérêt d'une entreprise ou d'une personne (0.4.2)
`id`, `tenant_id` · `organisation_id` (FK, cascade, null) · `person_id` (FK, cascade, null) — CHECK `fiche_trainings_one_fiche_check` : exactement l'un des deux · `training_id` (FK `trainings`, restrict ; lue par `Catalogue\Trainings` uniquement) · `created_by` (FK `users`, null à la suppression) · `created_at`, `updated_at`. Uniques (`organisation_id`, `training_id`) et (`person_id`, `training_id`), index `training_id`. 50 au plus par fiche (validation). Journalisée (`fiche_training`, identifiants seulement) ; chaque changement de la liste est aussi journalisé sur la fiche (`crm.organisation_trainings_changed` / `crm.person_trainings_changed`, `training_ids` avant/après), affiché dans l'historique « Formations d'intérêt modifiées ». Une formation présente ici ne peut pas être supprimée du catalogue (`TrainingUsage` « fiches »). Fusion : lignes déplacées sur la fiche conservée, dédoublonnées ; anonymisation d'une personne : lignes supprimées ; export RGPD : section `formations_d_interet`.

### `affiliations` — rattachement d'une personne à une entreprise
`id`, `tenant_id` · `person_id`, `organisation_id` (FK, cascade) · `job_title` · `decision_role_id` (FK `reference_items`, liste `decision_role`) · `work_email`, `work_phone` · `is_referent` (bool) · `started_on`, `ended_on` (dates ; un rattachement terminé est conservé comme ancien) · `created_by`, `updated_by` · horodatages, `deleted_at`. Index unique partiel (`tenant_id`, `person_id`, `organisation_id`) tant que le rattachement est actif et non supprimé : une personne n'a qu'un rattachement actif par entreprise.

### `notes` — notes de l'historique
`id`, `tenant_id` · `notable_type`, `notable_id` (fiche concernée : alias `organisation` ou `person`) · `body` (text) · `author_id` (FK `users`) · `created_at`, `updated_at` (timestamptz, µs), `deleted_at`.

### `attachments` — pièces jointes
`id`, `tenant_id` · `notable_type`, `notable_id` · `original_name`, `mime`, `size` (octets), `path` (chemin dans le stockage de l'organisme, hors dossier public) · `uploaded_by` · horodatages, `deleted_at` · `file_deleted_at` (fichier effacé du stockage par la purge de conservation : fiche en corbeille depuis plus de 12 mois ; la ligne reste, affichée « Fichier supprimé »). Le fichier est lu uniquement via l'application (contrôle des droits).

### `saved_filters` — filtres enregistrés
`id`, `tenant_id` · `user_id` (auteur, cascade) · `resource` (`organisations` ou `people`, CHECK) · `name` (100) · `filters` (jsonb, clés validées) · `columns` (jsonb, null : colonnes de liste et d'export) · `is_shared` (visible de tout l'organisme) · horodatages. Le journal ne garde pas les termes de recherche.

### `recent_views` — dernières fiches consultées
`id` (entier) · `tenant_id` · `user_id` (FK, cascade) · `subject_type` (alias `organisation` ou `person`), `subject_id` · `viewed_at` (timestamptz, µs). Unique (`user_id`, `subject_type`, `subject_id`) ; **20 lignes conservées par utilisateur**, 5 affichées sur l'**Accueil**. Aucun nom ni donnée de la fiche n'est copié : l'affichage relit la fiche, et ne montre pas celles qui sont supprimées, fusionnées ou hors du périmètre de l'utilisateur.

## CRM : pipeline et actions (lot 1c, RLS)

### `opportunities` — opportunités (affaires)
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `organisation_id`, `person_id` | uuid, null (FK, cascade) | Compte : **exactement un** des deux (CHECK `opportunities_account_check`) ; une personne n'est compte que si elle est **particulier** |
| `title` | string 200 | Objet (conservé après l'anonymisation d'un particulier : ne pas y mettre de nom) |
| `das_id`, `stage_id` | uuid (FK `reference_items`) | DAS (visibilité du Responsable DAS) et étape (`pipeline_stage`) |
| `probability` | smallint 0–100 (CHECK) | Probabilité ; suit l'étape, forcée à 100 / 0 sur gagné / perdu |
| `probability_overridden` | bool, défaut `false` | Probabilité saisie à la main depuis le dernier changement d'étape (remise à `false` à chaque changement) |
| `amount` | numeric(12,2), null (CHECK ≥ 0) | Montant estimé |
| `weighted_amount` | numeric(12,2), **générée stockée** | `round(amount × probability / 100, 2)` |
| `expected_close_on` | date, null | Clôture prévue |
| `priority_id`, `funding_mode_id` | uuid, null (FK `reference_items`) | Priorité, mode de financement |
| `loss_reason_id`, `loss_comment` | uuid / text, null | Motif (obligatoire pour perdu) et commentaire de perte (donnée personnelle possible : masqué au journal, effacé à l'anonymisation) |
| `won_on`, `lost_on` | date, null | Dates de gain / de perte (effacées si l'affaire est rouverte) |
| `responsible_user_id`, `created_by`, `updated_by` | uuid, null (FK `users`) | |
| `source_system`, `source_id` | string, null | Identifiant d'origine (import) ; unique partiel par organisme |
| `created_at`, `updated_at`, `deleted_at` | timestamp | Suppression logique |

Index : (`tenant_id`, `stage_id`), (`tenant_id`, `das_id`), (`tenant_id`, `responsible_user_id`), (`tenant_id`, `expected_close_on`), `organisation_id`, `person_id`.

### `opportunity_contacts` — interlocuteurs d'une opportunité
`tenant_id` · `opportunity_id` (FK, cascade) · `person_id` (FK, cascade) ; PK (`opportunity_id`, `person_id`). Un particulier est automatiquement son propre contact.

### `opportunity_trainings` — formations d'une opportunité
`id`, `tenant_id` · `opportunity_id` (FK, cascade) · `training_id` (FK `trainings`, restrict ; lue par `Catalogue\Trainings` uniquement) · `quantity` numeric(8,2), défaut 1 (CHECK > 0 ; saisie ≤ 9 999,99) · `position` smallint (ordre) · `created_at`, `updated_at`. Unique (`opportunity_id`, `training_id`), index `training_id`. Journalisée (`opportunity_training`). Une formation présente ici (opportunité supprimée comprise) ne peut pas être supprimée du catalogue (`TrainingUsage` « opportunities »). Montant proposé = Σ tarif (entreprise ou particulier selon le compte) × quantité, jamais enregistré.

### `opportunity_stage_changes` — historique des étapes
`id`, `tenant_id` · `opportunity_id` (FK, cascade) · `from_stage_id` (null à la création), `to_stage_id` (FK `reference_items`) · `loss_reason_id` (null ; motif donné lors de ce passage à perdu, gardé même si l'affaire est rouverte) · `changed_by` · `changed_at` (timestamptz, µs). Index (`opportunity_id`, `changed_at`). Alimente l'**Historique** des fiches.

### `activities` — actions (appels, rendez-vous, e-mails, relances)
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `type_id`, `result_id` | uuid (FK `reference_items` : `action_type`, `action_result`) | Type ; résultat (null) |
| `subject` | string 200 | Objet (« Appel – Hôtel du Lac » par défaut) ; masqué au journal, remis au libellé du type à l'anonymisation |
| `purpose` | text, null | Motif (≤ 2 000 caractères, validé par l'application) ; masqué au journal, effacé à l'anonymisation ; seul texte libre repris dans l'évènement Outlook |
| `report` | text, null | Compte rendu ; masqué au journal, effacé à l'anonymisation |
| `next_steps` | text, null | Suite à donner (≤ 2 000 caractères) ; masquée au journal, effacée à l'anonymisation ; jamais envoyée à Outlook ni au flux iCal |
| `location` | string 255, null | Lieu ; masqué au journal, effacé à l'anonymisation |
| `organisation_id`, `person_id`, `opportunity_id` | uuid, null (FK, cascade) | Au moins un (CHECK `activities_subject_check`) |
| `due_at` | timestamptz(6), null | Échéance (action à faire) |
| `done_at` | timestamptz(6), null | Réalisation ; au moins une des deux dates (CHECK `activities_date_check`) ; jamais dans le futur |
| `happened_at` | timestamptz(6), **générée stockée** | `coalesce(done_at, due_at)` : ordre et curseur de l'historique |
| `duration_minutes` | smallint, null (CHECK 1–1440) | Durée |
| `responsible_user_id`, `created_by` | uuid, null (FK `users`) | |
| `created_at`, `updated_at`, `deleted_at` | timestamptz(6) | Suppression logique |

Index : partiel « À faire » (`tenant_id`, `responsible_user_id`, `due_at`) `where done_at is null and deleted_at is null` ; (`tenant_id`, `happened_at`) ; `organisation_id`, `person_id`, `opportunity_id`. Une action faite met à jour `last_contact_at` des fiches concernées (supprimée ou avancée : recalcul).

## Catalogue de formations (lot 1d, RLS)

Module `Catalogue` : le Crm ne lit jamais ces tables directement (service `Catalogue\Trainings`, test d'architecture).

### `activity_collaborators` — collaborateurs d'une action
(`activity_id` cascade, `user_id` cascade) clé primaire, `tenant_id` + RLS. Utilisateurs actifs ayant `crm.activities.view` (`ActivityCollaborators`) ; personnes à qui l'action est aussi attribuée (« Attribuer à » multiple, le premier choisi = `responsible_user_id`) : elles la voient et la modifient (`CrmVisibility::activities`, `allows` : droits « Consulter » et « Modifier », jamais « Supprimer »), dans « À faire » et le planning ; jamais comptées dans `ActivityStats`.

### `trainings` — formations
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `code` | string(20), null | Code, en majuscules ; unique par organisme parmi les formations non supprimées (index unique partiel `trainings_code_unique`) |
| `title` | string(200) | Intitulé |
| `das_id` | uuid (FK `reference_items`) | DAS (`ReferenceUsage`) |
| `duration_hours`, `duration_days` | numeric(6,2) / numeric(5,2), null | Durées (CHECK ≥ 0) |
| `audience`, `prerequisites` | text, null | Public visé, prérequis |
| `description` | text, null | Présentation courte (0.7) |
| `objectives` | jsonb, null | Objectifs pédagogiques : liste ordonnée de textes (20 au plus, 500 caractères chacun) |
| `methods`, `evaluation`, `sanction`, `accessibility`, `access_terms`, `results` | text, null | Méthodes et moyens, évaluation, sanction, accessibilité, modalités et délais d'accès, indicateurs de résultats (texte libre) |
| `price_company`, `price_individual` | numeric(12,2), null | Tarifs HT par stagiaire, entreprise et particulier (CHECK ≥ 0) |
| `is_active` | bool, défaut `true` | Désactivée : plus proposée, reste sur les opportunités et devis existants |
| `source_system`, `source_id` | string(50) / string(255), null | Identifiant d'origine (import) ; unique partiel par organisme (`trainings_source_unique`) |
| `created_by`, `updated_by` | uuid, null (FK `users`) | |
| `created_at`, `updated_at`, `deleted_at` | timestamp | Suppression logique ; refusée si la formation est utilisée (`Catalogue\TrainingUsage` : opportunités, lignes de devis, supprimées comprises, formations d'intérêt des fiches) |

Index : (`tenant_id`, `das_id`), (`tenant_id`, `title`). Journalisée (`training`). Cible d'import `trainings` (« Catalogue de formations »).

### `training_funding_modes` — financements possibles d'une formation
`tenant_id` · `training_id` (FK, cascade) · `funding_mode_id` (FK `reference_items`, `ReferenceUsage`) ; PK (`training_id`, `funding_mode_id`), index `funding_mode_id`.

### `training_modules` — modules d'une formation (0.7)
`id`, `tenant_id` · `training_id` (FK, cascade) · `position` smallint (ordre, à partir de 0) · `title` string(200) · `duration_hours` numeric(6,2), null · `modality_id` (FK `reference_items`, null ; référentiel `training_modality`, `ReferenceUsage`) · `content` text, null · `created_at`, `updated_at`. Index (`training_id`, `position`), `modality_id`. 30 au plus par formation. Journalisée (`training_module`, sans le contenu). Lue par les autres modules par `Catalogue\Trainings::modules()`. La somme des durées est comparée à celle de la formation : écart signalé, jamais bloquant.

## CRM : devis (lot 1d, RLS)

### `quote_sequences` — compteurs de numérotation
`tenant_id` (FK `tenants`, cascade) · `prefix` string(10) · `year` smallint (année civile de Paris, CHECK 2000–2999) · `last_number` int (CHECK > 0) · `created_at`, `updated_at`. PK (`tenant_id`, `prefix`, `year`). Incrément atomique (`insert … on conflict do update … returning`) dans la transaction de création du devis : numéros sans trou ; un numéro annulé par une transaction en échec est réutilisé. L'année est celle du jour de création (heure de Paris), pas la date du devis.

### `quotes` — devis
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `number` | string(30) | `<préfixe>-<AAAA>-<NNNN>` ; unique (`tenant_id`, `number`) |
| `company_id` | uuid (FK `companies`) | Société émettrice (fixée à la création) |
| `opportunity_id` | uuid (FK `opportunities`) | Opportunité ; la visibilité d'un devis est celle de son opportunité (`CrmVisibility::quotes`) |
| `organisation_id`, `person_id` | uuid, null (FK) | Compte copié de l'opportunité : **exactement un** des deux (CHECK `quotes_account_check`) |
| `contact_person_id` | uuid, null (FK `people`) | Destinataire (interlocuteur ou particulier) |
| `status` | string(20), défaut `draft` | `draft`, `sent`, `reminded`, `accepted`, `refused`, `expired` (CHECK) |
| `issued_on`, `valid_until` | date | Date du devis et validité (CHECK `valid_until >= issued_on`) |
| `sent_at`, `reminded_at` | timestamptz, null | Envoi et dernière relance (dernier contact des fiches, taux de réussite) |
| `accepted_on`, `refused_on` | date, null | Réponse du client |
| `refusal_reason` | text, null | Motif du refus (donnée personnelle possible : masquée au journal) ; « Remplacé par N » pour une nouvelle version |
| `replaced_by_id` | uuid, null (FK `quotes`) | Nouvelle version qui remplace ce devis |
| `vat_exempt` | bool | Régime de la société, recopié à chaque enregistrement du brouillon, figé à l'envoi |
| `total_ht`, `total_vat`, `total_ttc` | numeric(12,2) | Totaux calculés en centimes entiers (CHECK ≥ 0 et `total_ttc = total_ht + total_vat`) |
| `recipient_snapshot` | jsonb, null | Destinataire figé à l'envoi (nom, adresse, « à l'attention de », e-mail) ; donnée personnelle (masquée au journal, effacée à l'anonymisation) |
| `signed_path` | string(500), null | Exemplaire signé renvoyé par le client (devis accepté), `tenants/<id>/quotes/<quote_id>/signe.<pdf\|jpg\|png>` (stockage privé, remplacé à chaque ajout) ; null après anonymisation (fichier supprimé). Ignoré par le journal |
| `signed_name` | string(255), null | Nom d'origine du fichier signé (donnée personnelle : masqué dans le journal) |
| `signed_at` | timestamptz, null | Ajout de l'exemplaire signé ; évènement de journal `quote.signed_added` (type, taille, SHA-256) |
| `pdf_path` | string, null | PDF archivé à l'envoi, `tenants/<id>/quotes/<quote_id>/<numéro>.pdf` (stockage privé) ; null après anonymisation (PDF supprimé, réponse 410) |
| `notes` | text, null | Mentions particulières (donnée personnelle possible) |
| `email_message` | jsonb, null | Dernier message : type, `to`, `cc`, objet, corps (pour **Renvoyer**) ; hors journal |
| `email_status` | string(10), null | `pending`, `sent`, `failed` (CHECK) ; hors journal |
| `email_sent_at` | timestamptz, null | Remise au serveur d'envoi |
| `email_error` | string(255), null | Raison fixe en français (code SMTP au plus) |
| `created_by`, `updated_by` | uuid, null (FK `users`) | |
| `created_at`, `updated_at`, `deleted_at` | timestamp | Suppression logique (brouillons seulement) |

Index : `opportunity_id`, `organisation_id`, `person_id`, `contact_person_id`, (`tenant_id`, `issued_on`), (`tenant_id`, `status`). Journalisé (`quote`) : les changements de statut alimentent l'**Historique** des fiches et de l'opportunité. Fusion : `organisation_id`, `person_id` et `contact_person_id` suivent la fiche conservée (le destinataire figé ne change pas).

### `quote_lines` — lignes d'un devis
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `quote_id` | uuid (FK, cascade) | |
| `position` | smallint | Ordre |
| `training_id` | uuid, null (FK `trainings`, restrict) | Formation du catalogue (ligne libre sinon) ; lue par `Catalogue\Trainings` |
| `das_id` | uuid, null (FK `reference_items`) | DAS de la formation (`ReferenceUsage`) |
| `label` | string(300) | Libellé |
| `description` | text, null | Description (donnée personnelle possible : masquée au journal) |
| `quantity` | numeric(8,2) | CHECK > 0 (saisie ≤ 9 999,99) |
| `unit` | string(20) | `stagiaire`, `jour`, `heure`, `forfait`, `session` (CHECK) |
| `unit_price` | numeric(12,2) | Prix unitaire HT (CHECK ≥ 0) |
| `discount_percent` | numeric(5,2), défaut 0 | Remise, 0–100 (CHECK) |
| `vat_rate` | numeric(4,2) | 20, 10, 5,5, 2,1 ou 0 (CHECK) ; 0 sur un devis exonéré |
| `total_ht` | numeric(12,2) | Quantité × prix × (1 − remise), arrondi au centime (demi supérieur) |
| `created_at`, `updated_at` | timestamp | |

Index : `quote_id`, `training_id`, `das_id`. Journalisée (`quote_line`). La TVA se calcule **par taux** sur la somme des lignes du même taux, pas ligne par ligne.

### `brochure_sendings` — plaquettes envoyées depuis une fiche
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `organisation_id` / `person_id` | uuid (FK, cascade) | Fiche d'où part l'envoi (exactement une des deux ; le compte de l'opportunité le cas échéant) |
| `opportunity_id` | uuid nullable (FK, set null) | Opportunité d'où part l'envoi |
| `recipient_person_id` | uuid (FK `people`) | Destinataire |
| `recipient_email`, `subject`, `message` | texte nullable | Copie figée de ce qui est parti (données personnelles : hors journal, effacées par l'anonymisation) |
| `email_status` | `pending` / `sent` / `failed` | État de l'envoi ; `email_error` : raison fixe en français, jamais le texte du serveur |
| `sent_at`, `created_by`, `created_at`, `updated_at` | | |

Journalisée (`brochure_sending`). `brochure_sending_versions` (`brochure_sending_id`, `brochure_version_id` FK restrict, `position`) garde les versions exactement envoyées.

### `quote_brochures` — plaquettes jointes à l'e-mail d'un devis
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `quote_id` | uuid (FK, cascade) | |
| `brochure_version_id` | uuid (FK `brochure_versions`, restrict) | Version jointe à l'envoi (une version envoyée ne s'efface jamais) ; relance et « Renvoyer » rejoignent exactement ces versions |
| `position` | smallint | Ordre des pièces jointes |
| `created_at`, `updated_at` | timestamp | |

Unique (`quote_id`, `brochure_version_id`). Journalisée (`quote_brochure`).

### `revenue_targets` — objectifs de chiffre d'affaires par DAS
| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `das_id` | uuid (FK `reference_items`) | DAS (`ReferenceUsage` : un DAS ayant un objectif ne se supprime pas) |
| `period_type` | string(10) | `month`, `quarter` ou `year` (CHECK) |
| `period_start` | date | Premier jour de la période |
| `amount` | numeric(12,2) | Objectif HT (CHECK ≥ 0) ; 0 ou vide = pas d'objectif (ligne supprimée) |
| `created_by`, `updated_by` | uuid, null | |
| `created_at`, `updated_at` | timestamp | |

Unique (`tenant_id`, `das_id`, `period_type`, `period_start`) : l'objectif d'un trimestre n'est pas la somme de ses mois. Journalisée (`revenue_target`).

Étape « devis envoyé » : clé `meta.quote_stage = true` sur une seule valeur `pipeline_stage` de `reference_items` par organisme.

## CRM : import (lot 1b, RLS)

### `import_profiles` — profils d'import
`id`, `tenant_id` · `name` (100, unique par organisme) · `target` (`organisations`, `people`, `affiliations`, `notes`, `trainings` ; CHECK, même liste pour `import_batches`) · `field_map` (jsonb : colonne du fichier → champ) · `value_map` (jsonb : valeurs du fichier → valeurs des listes) · `options` (jsonb : séparateur, encodage, stratégie de doublon) · `created_by` · horodatages. Un profil d'import Formevol est un simple enregistrement de cette table.

### `import_batches` — imports
| Colonne | Rôle |
|---|---|
| `id`, `tenant_id`, `user_id` | Import et auteur |
| `target`, `profile_id` | Type de données et profil utilisé |
| `original_name`, `stored_path` | Nom du fichier et chemin de stockage (purgés au bout de 30 jours : le nom devient `fichier-import.<ext>`, il peut nommer une personne ; masqué dans le journal) |
| `status` | `uploaded`, `simulated`, `running`, `done`, `failed`, `undoing`, `undone` (CHECK ; l'état transitoire de simulation figure aussi) |
| `options`, `field_map`, `value_map` | Réglages choisis dans l'assistant ; `options.resumes` : reprises automatiques par la surveillance, `options.failure` : raison d'un échec |
| `simulation` | Résultat de la simulation (jsonb) |
| `total`, `created`, `updated`, `skipped`, `errors` | Compteurs |
| `cursor_line` | Reprise de l'import après interruption |
| `report_path` | Rapport Excel |
| `started_at`, `finished_at`, `undone_at` | Dates |

### `import_rows` — lignes d'un import
`id`, `tenant_id`, `batch_id` (cascade) · `line` (numéro de ligne du fichier) · `source` (jsonb : valeurs brutes de la ligne, **effacées** avec le fichier ou à l'anonymisation de la personne) · `status` (`created`, `updated`, `skipped`, `error`) · `messages` (jsonb) · `subject_type`, `subject_id` (fiche concernée) · `created_subject` · `created_refs`, `touched_refs` (jsonb : objets créés / modifiés, index GIN `jsonb_path_ops`).

### `import_stamps` — traces par fiche
`id` (entier, l'ordre d'insertion fait foi), `tenant_id`, `batch_id` · `subject_type`, `subject_id` · `stamped_at` (date de mise à jour laissée par l'import : détecte une modification ultérieure) · `created` · `batch_undone` · `trashed_by_undo_at`. Colonnes simples et indexées : un `@>` sur du jsonb n'est pas leakproof et ne serait pas utilisé sous RLS. Sert à l'annulation d'un import et à la réimportation.

### `import_header_choices` — choix de colonnes mémorisés (0.7.1)
`id` (uuid v7), `tenant_id` · `target` (30, type d'import) · `header_key` (200, en-tête normalisé : sans accents, casse ni ponctuation) · `field` (60, champ choisi ; **null = « Ignorer »**) · `uses` (nombre de validations) · `last_used_at` (timestamptz). Unique (`tenant_id`, `target`, `header_key`). Écrit quand l'utilisateur valide l'étape « Colonnes », pour ses seuls choix délibérés (comparés à la proposition recalculée par le serveur) ; relu en premier pour proposer la correspondance du fichier suivant. Aucune valeur de cellule n'y est stockée, seulement des en-têtes et des codes de champ.

## CRM : RGPD (lot 1b, RLS)

### `consents` — consentements
`id`, `tenant_id` · `person_id` (FK, cascade) · `channel` (`email`, `sms`, `phone`, `prospecting`) · `granted` (bool) · `source` (facultatif) · `recorded_by` · `recorded_at` (µs). **Historique en ajout** : un nouveau choix s'ajoute, le dernier par canal fait foi.

### `gdpr_pending` — liste « À anonymiser »
`person_id` (PK, FK cascade) · `tenant_id` · `listed_at`. Une personne y est inscrite par le traitement de nuit quand sa durée de conservation est dépassée ; elle est anonymisée 30 jours après `listed_at` si elle y est toujours.

### `brochures`, `brochure_versions`, `brochure_trainings`, `brochure_das` — bibliothèque de plaquettes (0.5.2, Catalogue)
`brochures` : `id`, `tenant_id`, `title` (200) · `kind` (`brochure` plaquette, `positioning_test` test de positionnement, CHECK, défaut `brochure` ; un test n'est jamais proposé comme plaquette) · `current_version_id` (FK `brochure_versions`, null à la suppression) · `created_by`, `updated_by` (FK `users`) · `created_at`, `updated_at`, `deleted_at` (corbeille). Journalisée (`brochure`, titre seulement). `brochure_versions` : `id`, `tenant_id`, `brochure_id` (FK cascade), `path` (disque privé `tenants/<tenant_id>/brochures/<plaquette>/<version>.pdf`, nom généré), `original_name` (affichage), `size`, `sha256`, `uploaded_by`, `created_at` ; jamais modifiée ni effacée (une version envoyée reste lisible) ; l'ajout est journalisé (`brochure.version_added` : version, taille, SHA-256, jamais le nom). `brochure_trainings` (`brochure_id`, `training_id`, FK cascade ; la suppression d'une formation détache ses plaquettes) et `brochure_das` (`brochure_id`, `das_id` FK `reference_items`, déclaré à `ReferenceUsage`) : tables d'association, `tenant_id` + RLS. Lues par les autres modules par `Catalogue\Brochures` uniquement. Une version présente dans `quote_brochures` ou `brochure_sending_versions` reste téléchargeable même si sa plaquette est à la corbeille (`Brochures::registerSentCheck`, déclaré par le Crm, et par Admissions pour `positioning_sending_versions`).

## Sessions : sessions de formation (lot 2a, RLS)

### `training_sessions` — sessions de formation
Nommée ainsi pour ne pas se confondre avec la table `sessions` (sessions web). Module `Sessions` (`TrainingSession`, écrite par `SessionWriter`) ; il lit la formation par `Catalogue\Trainings` et l'entreprise par `Crm\Public\Fiches`, jamais par leurs tables.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `number` | string(20) | `S-<AAAA>-<NNNN>` ; unique (`tenant_id`, `number`) |
| `title` | string(150), null | Intitulé facultatif (« Commis de cuisine – entrée du 30 novembre 2026 ») ; sans intitulé, la session se lit « <formation> (S-…) » |
| `training_id` | uuid (FK `trainings`) | Formation du catalogue |
| `das_id` | uuid (FK `reference_items`, DAS) | DAS de la formation, recopié à chaque enregistrement (périmètre « Ses DAS ») |
| `kind` | string(10) | `intra` / `inter` / `individual` « Individuel » (CHECK) |
| `modality_id` | uuid (FK `reference_items`, liste `training_modality`) | Modalité : Présentiel, Distanciel (classe virtuelle), FOAD / e-learning, Mixte (paramétrable) ; obligatoire |
| `organisation_id` | uuid, null (FK `organisations`) | Entreprise d'une session intra (obligatoire en intra, vide en inter, facultative en individuel : CHECK) |
| `starts_on`, `ends_on` | date | Jours de Paris ; CHECK `ends_on >= starts_on` |
| `planned_hours` | numeric(6,2), null | Heures prévues = somme des `session_modules.planned_minutes` (0.8.0), recalculée sous verrou de la session à chaque écriture d'un module ; la saisie ne sert qu'au module unique d'une formation sans module |
| `location_kind` | string(10) | `address` (adresse obligatoire), `company` (dans nos locaux), `client` (chez le client), `remote` (distanciel) |
| `location_text` | string(500), null | Adresse, précision ou lien |
| `location_company_id` | uuid, null | Société dont les locaux accueillent la session (`company` seulement, CHECK) ; adresse imprimée par `SessionPlace::of` |
| `color` | varchar(7) nul | Couleur du planning (`SessionColors`), donnée à tour de rôle dans une palette de 12 à la création. |
| `capacity` | int, null | Places d'une session inter (≥ 1, vide en intra et en individuel : CHECK). Une session individuelle n'accueille qu'un stagiaire (inscription non annulée), sans liste d'attente |
| `responsible_user_id` | uuid, null (FK `users`) | Responsable (créateur par défaut) |
| `status` | string(20) | `planned`, `confirmed`, `running`, `done`, `cancelled` (CHECK). « En cours » et « Terminée » se calculent à la lecture d'après les dates tant que l'état posé est `planned` ou `confirmed` |
| `cancel_reason_id`, `cancelled_on` | uuid (FK `reference_items`, motifs d'annulation), date | Renseignés ensemble si et seulement si `status = cancelled` (CHECK) |
| `notes` | text, null | Journalisé sans valeur (`$auditPersonal`) |
| `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` | | Suppression logique (corbeille) |

Une formation utilisée par une session (corbeille comprise) ne se supprime pas du catalogue (`TrainingUsage` « sessions »).

### `session_modules` — modules d'une session (0.8.0)
Module `Sessions` (`Modules\SessionModule`, écrite par `Modules\SessionModules`). Copiés de `Catalogue\Trainings::modules()` à la création de la session (et au changement de formation), puis propres à la session ; la migration `2026_12_17_000002` a donné à chaque session existante un module `interval` (intitulé de la formation, heures et dates de la session).

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `training_session_id` | uuid (FK `training_sessions`, cascade) | Session |
| `position` | smallint | Ordre (1, 2…) ; un ajout va à la fin |
| `title` | string(200) | Intitulé |
| `planned_minutes` | int, null | Heures prévues en minutes entières (CHECK > 0 ; vide quand le catalogue n'en donnait pas). Conversion des heures décimales arrondie à la minute (demi supérieur, `brick/math`) |
| `modality_id` | uuid, null (FK `reference_items`, liste `training_modality`) | Modalité (`ReferenceUsage` : une valeur utilisée ne se supprime pas) |
| `dating_mode` | string(10) | `interval` (début, fin, heures ; pas de séances) ou `seances` (CHECK) ; ne change plus dès qu'il y a des séances ou des heures saisies (`SessionModule::hasActivity()`) |
| `starts_on`, `ends_on` | date, null | Dates d'un module en intervalle, dans celles de la session à l'enregistrement (CHECK `ends_on >= starts_on`) ; hors des dates après un changement de la session : signalé, pas déplacé |
| `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` | | Suppression logique ; journalisé (`session_module`) |

### `rooms` — salles de formation (0.8.0)
Module `Sessions` (`Resources\Room`, écrite par `Resources\Rooms`). `id`, `tenant_id` · `name` string(120) · `capacity` int, null (CHECK > 0, places) · `address` string(500), null · `active` bool (seules les salles actives sont proposées, `Rooms::active()`) · `created_by`, `updated_by`, horodatages, `deleted_at` (suppression logique, refusée pour une salle utilisée : `Rooms::registerUsage`). Journalisée (`room`).

### `closure_periods` — périodes de fermeture (0.8.0)
Module `Sessions` (`Resources\ClosurePeriod`, `Resources\ClosurePeriods`). `id`, `tenant_id` · `starts_on`, `ends_on` date (jours de Paris, bornes incluses, CHECK `ends_on >= starts_on`) · `label` string(120) · `created_by`, `updated_by`, horodatages. Suppression définitive. `ClosurePeriods::covering($date)` donne la fermeture d'un jour. Journalisée (`closure_period`).

### `trainers` — formateurs (0.8.0)
Module `Sessions` (`Trainers\Trainer`, écrite par `Trainers\Trainers`) ; la personne est lue par `Crm\Public\Fiches`, jamais par les tables du CRM. `id`, `tenant_id` · `person_id` (FK `people`) : une personne n'est formateur qu'une fois par organisme parmi les formateurs hors corbeille (index unique partiel `trainers_person_unique`) · `status` string(10) `employee` | `freelance` (CHECK) · `active` bool · `user_id` (FK `users`, null, unique par organisme) compte lié, à la main ou à l'acceptation d'une invitation (une seule fiche formateur avec l'e-mail) · `created_by`, `updated_by`, horodatages, `deleted_at` (suppression logique, refusée pour un formateur utilisé : `Trainers::registerUsage` ; le compte est délié). Journalisée (`trainer`). Une fusion de personnes repointe `person_id` (refusée si les deux sont formateurs).

### `trainer_unavailabilities` — indisponibilités des formateurs (0.8.0)
`id`, `tenant_id` · `trainer_id` (FK `trainers`, cascade) · `starts_at`, `ends_at` timestamptz (CHECK `ends_at > starts_at`, saisis en heure de Paris) · `reason` string(200), null (donnée personnelle : journalisée sans valeur, effacée par l'anonymisation de la personne) · `created_by`, horodatages ; index (`trainer_id`, `starts_at`). `Trainers::available($id, $from, $to)` : aucune indisponibilité ne chevauche [début, fin[.

### `trainer_das` — DAS des formateurs (0.8.0)
`id`, `tenant_id` · `trainer_id` (FK `trainers`, cascade) · `das_id` (FK `reference_items`, liste DAS ; `ReferenceUsage` : un DAS utilisé ne se supprime pas) · horodatages ; unique (`trainer_id`, `das_id`). Écrite par `Trainers::setDas` ; `Trainers::ofDas(?string)` filtre. Un formateur sans ligne = sans DAS, visible de tous. Journalisée (`trainer_das`), rien de personnel.

### `seances` — séances des modules (0.8.0)
Module `Sessions` (`Planning\Seance`, écrite par `Planning\Seances` et `Planning\SeanceGenerator`). `id`, `tenant_id` · `training_session_id` (FK `training_sessions`, cascade) · `session_module_id` (FK `session_modules`, cascade ; module en mode `seances`) · `date` date (jour de Paris) · `starts_at`, `ends_at` time sans fuseau (heure murale de Paris, CHECK `ends_at > starts_at`) · `break_minutes` int (CHECK ≥ 0) · `duration_minutes` int **générée** (`ends_at − starts_at − break_minutes`, CHECK > 0) · `trainer_id` (FK `trainers`, null) · `room_id` (FK `rooms`, null) · `place_text` string(300), null · `status` `planned` | `cancelled` (CHECK) · `cancel_reason` string(300), null (seulement annulée) · `note` string(1000), null · `created_by`, `updated_by`, horodatages, `deleted_at`. Index unique partiel `seances_module_slot_unique` (`session_module_id`, `date`, `starts_at`) hors corbeille ; index (`training_session_id`, `date`), (`trainer_id`, `date`), (`room_id`, `date`). Journalisée (`seance`) sans `note` ni `cancel_reason` en clair. Une séance rend son module « actif » (`SessionModule::hasActivity()`), sa salle et son formateur « utilisés ». Évènement `Planning\Events\SeanceChanged` (après commit) quand ses horaires changent ou qu'elle est annulée.

### `placement_periods` — périodes de stage (0.8.0)
`id`, `tenant_id` · `training_session_id` (FK, cascade) · `starts_on`, `ends_on` date (dates communes, dans celles de la session, CHECK) · `label` string(120) · `created_by`, `updated_by`, horodatages. Suppression définitive. Journalisée (`placement_period`).

### `placement_period_hosts` — stage d'un stagiaire (0.8.0)
`id`, `tenant_id` · `placement_period_id` (FK, cascade) · `enrolment_id` (FK `enrolments`, cascade), unique avec la période · `organisation_id` (FK `organisations`, null : entreprise d'accueil, lue par `Crm\Public\Fiches`, repointée à la fusion, gardée par l'annulation d'un import) · `starts_on`, `ends_on` date, null (dates propres, les deux ou aucune, CHECK) · `created_by`, `updated_by`, horodatages. `PlacementPeriods::datesFor()` : dates propres, sinon communes. Journalisée (`placement_period_host`).

### `attendances` — présences aux séances (0.8.0)
Module `Sessions` (`Attendance\Attendance`, écrite par `Attendance\Attendances` et l'écouteur de `SeanceChanged`). `id`, `tenant_id` · `seance_id` (FK `seances`, sans cascade) · `enrolment_id` (FK `enrolments`, sans cascade), unique avec la séance · `status` `present` | `absent` | `partial` | `excused` (CHECK) · `minutes` int (CHECK ≥ 0 ; `absent`/`excused` = 0, CHECK) : présent = durée de la séance, partiel 0 < m < durée, séance annulée = 0 · `reason` string(300), null (donnée personnelle, effacée par l'anonymisation) · `needs_review` bool (la séance a changé d'horaires depuis) · `recorded_by` (FK `users`, null) · `source` string(20), null (`null` = saisie à la main, `signing` = écrite par l'émargement en ligne : seules ces lignes sont recalculées à la validation d'une demi-journée) · horodatages. Journalisée (`attendance`) sans `reason` en clair ; historique du dossier.

### `monthly_hours` — heures du mois des modules en intervalle (0.8.0)
`Attendance\MonthlyHour`, écrite par `Attendance\MonthlyHours`. `id`, `tenant_id` · `enrolment_id` (FK `enrolments`) · `session_module_id` (FK `session_modules`, module `interval`) · `month` date (1er du mois de Paris, CHECK) · `minutes` int (CHECK ≥ 0, ≤ 44 640 par l'application) · `trainer_id` (FK `trainers`, null) · `recorded_by` (FK `users`, null) · horodatages. Unique (`enrolment_id`, `session_module_id`, `month`). Journalisée (`monthly_hour`) ; historique du dossier.

### `attendance_closures` — mois clôturés d'une session (0.8.0)
`Attendance\AttendanceClosure`, écrite par `Attendance\Closures`. `id`, `tenant_id` · `training_session_id` (FK `training_sessions`) · `month` date (1er du mois de Paris, CHECK), unique avec la session · `closed_at` timestamptz, null (non nul = clôturé) · `closed_by` (FK `users`, null) · `reopened_at` timestamptz, null · `reopened_by` (FK `users`, null) · `reopen_reason` string(300), null (motif de la dernière réouverture, donnée personnelle) · horodatages. Journalisée (`attendance_closure`) sans le motif en clair. Un mois clôturé refuse (409) présences, heures du mois et création, suppression, annulation ou changement d'horaires de ses séances. Service public `Sessions\Public\RealisedHours` (`forEnrolment`, `forSession`) : minutes réalisées (dans les dates d'entrée et de sortie du stagiaire) et prévues (somme des modules, null = 0), mois clôturés.

### `attendance_proofs` — justificatifs d'absence (0.14.4)

| Colonne | Type | Rôle |
|---|---|---|
| `seance_id` | uuid → `seances` | Séance concernée |
| `enrolment_id` | uuid → `enrolments` | Stagiaire concerné |
| `path` | string(500) | Fichier, zone privée `attendance_proofs` |
| `original_name` | string(255) | Nom neutre `justificatif-<date>.<ext>` |
| `mime`, `size_bytes` | string, entier | Type vérifié sur le contenu (PDF, PNG, JPEG), 10 Mo au plus |
| `uploaded_by` | uuid → `users`, null | Qui l'a déposé |

Lu et écrit par les gestionnaires de présences seulement (périmètre ≠ « Ses séances ») ; non journalisé ; supprimé par l'anonymisation du stagiaire.

### `attendance_sheet_scans` — feuilles d'émargement signées, scannées (0.8.0)
`Attendance\AttendanceSheetScan`, écrite par `Attendance\AttendanceScans`. `id`, `tenant_id` · `training_session_id` (FK `training_sessions`) · `seance_id` (FK `seances`, sans cascade, null = scan de toute la journée de la session) · `date` date (jour de Paris) · `path` string(300) (fichier privé `tenants/<tenant_id>/attendance_sheets/<session>/<date>/<uuid>.<pdf|png|jpg>`) · `original_name` string(200) (nom envoyé, jamais journalisé) · `mime` string(100) · `size_bytes` int (≤ 10 Mo) · `uploaded_by` (FK `users`, null) · horodatages. Journalisée (`attendance_sheet_scan`) sans le chemin ni le nom du fichier. Supprimée par un gestionnaire, ou par l'anonymisation du stagiaire d'une session individuelle (fichier compris) ; gardée sinon.

### `attendance_sign_slots` — demi-journées d'émargement en ligne
`Attendance\Signing\SignSlot`, écrite par `Signing\AttendanceSigning` et `Signing\SlotValidation`. `id`, `tenant_id` · `training_session_id` (FK `training_sessions`) · `date` date (jour de Paris) · `half` `morning` | `afternoon` (CHECK ; coupure à 13 h) · `status` `open` | `closed` | `validated` (CHECK) · `token_hash` char(64), null, unique (sha256 du lien partagé ; null une fois fermé ou validé) · `token_encrypted` text, null (le jeton chiffré par `APP_KEY`, pour réafficher le QR code au formateur) · `opened_at`, `opened_by` (FK `users`, null) · `validated_at`, `validated_by` (FK `users`, null ; null pour un connecteur) · `trainer_name` string(200) (nom du valideur, figé, donnée personnelle) · `validator_role` `trainer` | `manager` (CHECK) · `trainer_signature_path` string(300), `trainer_signature_sha256` (dessin du valideur, zone `attendance_signatures`) · `catch_up_token_hash` char(64), null, unique, `catch_up_opened_at`, `catch_up_opened_by`, `catch_up_expires_at` (rattrapage sur place, jusqu'à minuit) · horodatages. Unique (`tenant_id`, `training_session_id`, `date`, `half`). Journalisée (`attendance_sign_slot`) sans jeton ni chemin.

### `attendance_signatures` — signatures des stagiaires
`Attendance\Signing\AttendanceSignature`. `id`, `tenant_id` · `slot_id` (FK `attendance_sign_slots`) · `enrolment_id` (FK `enrolments`), unique avec le créneau · `drawing_path` string(300), null (PNG privé `tenants/<tenant_id>/attendance_signatures/<session>/<créneau>/<id>.png`) · `drawing_sha256` char(64), null · `signed_at` timestamptz (heure du serveur) · `source` string(40) (`online` = lien partagé, sinon la clé d'un connecteur) · `ip` string(45), `user_agent` string(300), null · horodatages. Journalisée (`attendance_signature`) sans dessin, adresse ni navigateur (`$auditPersonal`). L'anonymisation efface dessin, adresse et navigateur (fichier compris) et garde l'heure. Rattrapage (2026-11-21) : `kind` `on_time` (défaut) | `catch_up_onsite` | `catch_up_remote` (CHECK) · `confirmed_at`, `confirmed_by` (FK `users`) · `refused_at`, `refused_by`, `refusal_reason` string(300) (`$auditPersonal`) ; CHECK : une signature à l'heure n'est ni confirmée ni refusée, un rattrapage pas les deux.

### `attendance_catch_up_links` — liens de rattrapage à distance
`Attendance\Signing\CatchUpLink`, écrite par `Signing\RemoteCatchUp`. `id`, `tenant_id` · `slot_id` (FK `attendance_sign_slots`) · `enrolment_id` (FK `enrolments`) · `email` string(254), null (donnée personnelle, effacée par l'anonymisation) · `token_hash` char(64) unique (sha256 du lien) · `expires_at` (7 jours) · `used_at`, `revoked_at` · `code_hash` (HMAC), `code_expires_at`, `code_attempts`, `code_sent_at` · `email_status` (`pending` | `sent` | `failed`), `email_error_code` · `created_by` (FK `users`) · horodatages. Un seul lien actif (ni utilisé ni révoqué) par créneau et stagiaire (index unique partiel). Journalisée sans adresse ni empreintes.

### `attendance_signed_sheets` — feuilles d'émargement signées archivées
`Attendance\Signing\SignedSheet`, écrite par `Signing\SignedSheetPdf`. `id`, `tenant_id` · `training_session_id` (FK `training_sessions`) · `month` date (1er du mois de Paris) · `path` string(300) (PDF privé, zone `attendance_sheets`, `signed-<AAAA-MM>-<horodatage>.pdf`) · `pdf_sha256` char(64) · `generated_by` (FK `users`, null) · horodatages. Chaque génération est une nouvelle version, jamais écrasée ; servie depuis l'archive et vérifiée par sha256 (410 sinon). Supprimée (fichier compris) par l'anonymisation du stagiaire d'une session individuelle ; gardée sur une session collective (preuve collective).

### `session_tasks` et `session_notes` — tâches et notes d'une session (0.8.1)
`Notes\SessionTask` et `Notes\SessionNote`, écrites par `Notes\SessionNotes` (droit de modifier la session, session non annulée). Suppression logique, RLS. `session_tasks` : `id`, `tenant_id` · `training_session_id` (FK `training_sessions`) · `title` string(200) · `due_on` date null (jour de Paris) · `assignee_user_id` (FK `users`, null, utilisateur actif de l'organisme) · `done_at` timestamptz null, `done_by` (FK `users`, null) · `created_by` · horodatages. `session_notes` : `id`, `tenant_id` · `training_session_id` · `body` text (≤ 5 000) · `author_user_id` (FK `users`, null) · horodatages. Journalisées (`session_task`, `session_note`) sans l'intitulé ni le texte (`[donnée personnelle]`) ; non concernées par l'anonymisation ni l'export RGPD.

### `session_sequences` — compteurs de numérotation des sessions
`tenant_id` (FK `tenants`, cascade) · `year` smallint (année civile de Paris à la création, CHECK 2000–2999) · `last_number` int (CHECK > 0) · `created_at`, `updated_at`. PK (`tenant_id`, `year`). Même mécanique que `quote_sequences` (incrément atomique dans la transaction de création, numéro rendu en cas d'échec).

### `dossiers` — dossiers de formation
Module `Sessions` (`Dossier`, écrit par `DossierWriter`, états par `DossierTransitions`) ; contractant et contact lus par `Crm\Public\Fiches`, jamais par les tables du CRM.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `number` | string(20) | `D-<AAAA>-<NNNN>` ; unique (`tenant_id`, `number`) |
| `company_id` | uuid (FK `companies`) | Société émettrice (par défaut celle de l'organisme) |
| `account_organisation_id` / `account_person_id` | uuid, null (FK `organisations` / `people`) | Contractant : une entreprise **ou** un particulier (exactement un : CHECK) |
| `contact_person_id` | uuid, null (FK `people`) | Contact du dossier |
| `responsible_user_id` | uuid, null (FK `users`) | Responsable (créateur par défaut) |
| `quote_id` | uuid, null (FK `quotes`), **unique** | Devis accepté d'origine (un seul dossier par devis) |
| `opportunity_id` | uuid, null (FK `opportunities`) | Opportunité d'origine |
| `das_id` | uuid (FK `reference_items`, DAS) | DAS principal, obligatoire (périmètre « Ses DAS ») |
| `status` | string(20) | `created`, `convention_sent`, `convention_signed`, `convocations_sent`, `running`, `done`, `cancelled` ; `invoiced`, `settled` réservés au lot 3 (CHECK). « En cours » / « Réalisé » se calculent d'après les sessions des inscriptions tant qu'une étape antérieure est posée ; posés à la main, ils ne sont plus recalculés |
| `convention_sent_on`, `convention_signed_on`, `convocations_sent_on` | date, null | Jours des étapes manuelles (chacune pas avant la précédente) |
| `cancelled_reason_id`, `cancelled_on` | uuid (FK `reference_items`, motifs d'annulation), date | Renseignés ensemble si et seulement si `status = cancelled` (CHECK) ; annulation refusée après « Réalisé » |
| `abandon_mode`, `abandon_dedit_percent` | string(20), smallint, null | Surcharge de la règle d'abandon de l'organisme (`prorata` ; `prorata_dedit` avec 1 à 100 % : CHECK) ; vides : règle de l'organisme (`AbandonRule::forDossier`) |
| `notes` | text, null | Journalisé sans valeur (`$auditPersonal`) |
| `created_by`, `updated_by`, `created_at`, `updated_at`, `deleted_at` | | Suppression logique (corbeille) |

Prix du dossier = somme des `total_ht` de ses lignes (`Dossier::priceCents()`, centimes entiers).

### `dossier_lines` — lignes d'un dossier
`id`, `tenant_id` · `dossier_id` (FK, cascade) · `position` smallint · `kind` (`training` : formation du catalogue, `training_id` obligatoire, `pricing_mode` `per_trainee` ou `group` ; `fee` : frais sans formation ni mode de prix — CHECK) · `training_id` (FK `trainings`, null) · `das_id` (FK `reference_items`, null : DAS de la formation, ou du dossier pour des frais) · `label` string(255) · `quantity` numeric(8,2) > 0 · `unit` (`stagiaire`, `jour`, `heure`, `forfait`, `session`) · `unit_price` numeric(12,2) · `discount_percent` numeric(5,2) 0–100 · `total_ht` numeric(12,2) = arrondi au demi supérieur de quantité × prix × (1 − remise), en centimes entiers · `created_at`, `updated_at`. Mode de prix proposé d'après l'unité (« stagiaire » → par stagiaire, sinon de groupe). Une formation utilisée par une ligne (dossier à la corbeille compris) ne se supprime pas du catalogue.

### `dossier_sequences` — compteurs de numérotation des dossiers
Même forme et même mécanique que `session_sequences`.

### `enrolments` — inscriptions des stagiaires
Module `Sessions` (`Enrolment`, écrit par `Enrolments` et `PriceShares` seulement) ; le stagiaire est une personne du CRM, lue par `Crm\Public\Fiches` / `Trainees`.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `person_id` | uuid (FK `people`) | Stagiaire ; une seule inscription non annulée par session (index unique partiel `training_session_id, person_id where status <> 'cancelled'`) |
| `training_session_id` | uuid (FK `training_sessions`) | Session (sa formation est celle de la ligne ; une session intra ne prend que les dossiers de son entreprise) |
| `dossier_id`, `dossier_line_id` | uuid (FK `dossiers`, `dossier_lines`) | Dossier et ligne de formation ; une ligne avec des inscriptions non annulées ne se supprime pas et ne change pas de formation |
| `status` | string(20) | `enrolled` (Inscrit), `waitlisted` (En attente), `confirmed` (Confirmé), `abandoned` (Abandon), `cancelled` (Annulé) — CHECK. Une session inter complète (inscrits + confirmés ≥ places) met l'inscription suivante en attente ; une annulation ne promeut personne (promotion à la main) |
| `waitlist_position` | integer, null | Ordre d'arrivée sur la liste d'attente ; renseigné si et seulement si `waitlisted` (CHECK) |
| `entered_on`, `left_on` | date, null | Entrée (début de la session par défaut) et sortie ; sortie ≥ entrée (CHECK) |
| `abandon_reason_id` | uuid, null (FK `reference_items`, motifs d'abandon) | Obligatoire avec la date de sortie pour un abandon (CHECK) ; gardé si l'abandon est ensuite annulé |
| `planned_hours` | numeric(6,2), null | Heures prévues du stagiaire : celles de la session à l'inscription, modifiables (0 < h ≤ heures de la session) ; journalisées |
| `price_share` | numeric(12,2), null | Part d'une ligne « prix par stagiaire » (`PriceShares` : total réparti au centime entre inscrits, confirmés et abandons, reste aux premières inscriptions par `created_at, id`) ; null en prix de groupe, en attente ou annulée |
| `bpf_category_id` | uuid, null (FK `reference_items`, catégories BPF) | Catégorie de stagiaire (lot 3) |
| `adaptation_needs`, `notes` | text, null | Situation de handicap / besoins d'adaptation et notes : données personnelles, journalisées sans valeur (`$auditPersonal`) |
| `created_at`, `updated_at` | | |

Une session avec des inscriptions non annulées garde sa formation, son type et son entreprise et ne va pas à la corbeille. « En cours » / « Réalisé » d'un dossier se calculent d'après la première date de début et la dernière date de fin des sessions de ses inscriptions non annulées (sessions annulées ou à la corbeille exclues : `DossierSchedule`).

### `fundings` — financements d'un dossier
Module `Sessions` (`Funding`, écrit par `FundingWriter` seulement, sous le verrou du dossier) ; le financeur est lu par `Crm\Public\Fiches`. Droits : ceux du dossier (`sessions.dossiers.update` et son périmètre) ; affichés sur la page du dossier seulement, jamais sur une session.

| Colonne | Type | Rôle |
|---|---|---|
| `id`, `tenant_id` | uuid | |
| `dossier_id` | uuid (FK `dossiers`) | Dossier financé |
| `funder_organisation_id` / `funder_person_id` | uuid, null (FK `organisations` / `people`) | Financeur : une entreprise (OPCO, l'entreprise elle-même, France Travail…) **ou** une personne (stagiaire qui paie lui-même, CPF) — exactement un (CHECK) |
| `funding_type_id` | uuid (FK `reference_items`, « Types de financeur ») | Type de financement (référentiel ; une valeur utilisée ne se supprime pas) |
| `dossier_line_id` / `enrolment_id` | uuid, null (FK `dossier_lines` / `enrolments`) | Cible facultative : une ligne du dossier **ou** une inscription du dossier (pas les deux : CHECK ; une cible d'un autre dossier : 422). Une inscription visée relève d'une ligne au prix par stagiaire (sa part `price_share`), ni annulée ni en liste d'attente ; une ligne visée, directement ou par une de ses inscriptions, ne se supprime pas, et ne change pas de mode de prix si ses inscriptions sont visées ; une inscription visée annulée garde son financement, signalé « Inscription annulée » (« Financement à reporter ») |
| `amount` | numeric(12,2) ≥ 0 (CHECK) | Montant HT, saisi au format français, calculé en centimes entiers |
| `status` | string(20) | `to_request` (À demander, défaut), `requested` (Demandé), `agreed` (Accordé), `refused` (Refusé) — CHECK. Un financement refusé ne compte plus dans le financé ; aucun report automatique |
| `agreement_number` | string(100), null | N° de prise en charge |
| `agreed_on` | date, null | Date d'accord |
| `pays_directly` | boolean, défaut faux (0.9) | « Paie directement l'organisme » : le financeur reçoit sa propre facture pour sa part (financement accordé seulement). Refusé quand le financeur est le contractant ; verrouillé dès la première facture validée du dossier (`BillingGuards`) |
| `notes` | text, null | Journalisé sans valeur (`$auditPersonal`) |
| `created_at`, `updated_at` | | |

Reste à financer (`FundingBalance`, centimes entiers) = prix du dossier − somme des financements non refusés ; de même par ligne visée (son total HT, contre les financements de la ligne et de ses inscriptions) et par inscription visée (sa part). Négatif : « Financements supérieurs au prix ». La liste des dossiers affiche le reste à financer du dossier, lu pour toute la page en une requête (`FundingBalance::fundedOf`).

### Liens avec les fiches du CRM, historique, RGPD (lot 2a)

Le module ne lit aucune table du CRM ; il se déclare par `Crm\Public\FicheLinks` et `Crm\Public\FicheHistory` (`SessionsServiceProvider`) :

| Mécanisme | Colonnes concernées | Effet |
|---|---|---|
| Données liées (annulation d'un import) | `training_sessions.organisation_id`, `dossiers.account_organisation_id` / `account_person_id` / `contact_person_id`, `enrolments.person_id`, `fundings.funder_organisation_id` / `funder_person_id` (corbeille comprise) | La fiche nommée est conservée (« Liée à des données ajoutées depuis l'import, conservée. ») |
| Fusion de deux fiches | mêmes colonnes | Repointées vers la fiche conservée, dans la transaction de la fusion ; fusion refusée (rien n'est écrit) si les deux personnes ont chacune une inscription non annulée sur la même session |
| Corbeille d'une personne | `enrolments.person_id` | Autorisée : les inscriptions restent (badge « Fiche à la corbeille »), restauration possible ; une personne à la corbeille ne peut pas être inscrite |
| Anonymisation (`Sessions\Gdpr\EnrolmentAnonymisation`) | `enrolments.adaptation_needs`, `enrolments.notes` ; `attendances.reason` (présences et heures du mois gardées) ; justificatifs `attendance_proofs` supprimés ; scans `attendance_sheet_scans` et feuilles signées `attendance_signed_sheets` d'une session individuelle supprimés (gardés sur une session collective) ; `attendance_signatures.drawing_path`, `ip`, `user_agent` (dessins effacés, heure gardée) ; `fundings.notes` (financements qu'elle paie, qui visent ses inscriptions ou ses dossiers de particulier) ; `dossiers.notes` (ses dossiers de particulier) | Valeurs effacées ; inscriptions, dossiers, financements, présences et heures gardés (statistiques, BPF, facturation) |
| Export RGPD (`Sessions\Gdpr\PersonSessionsExport`) | inscriptions, dossiers dont elle est contractante ou contact, financements, présences, heures du mois | Sections `inscriptions`, `dossiers`, `financements`, `presences` et `heures_mensuelles` ; l'export exige aussi `sessions.dossiers.view` et `sessions.sessions.view` sur « Tout l'organisme » |

**Historique** : aucune table propre ; lu dans `audit_logs` (créations et changements d'état des `dossier`, `enrolment`, `funding`). Fiche d'une entreprise ou d'une personne : ses dossiers (contractant ; contact pour une personne), selon le périmètre de `sessions.dossiers.view` ; inscriptions d'une personne, selon `sessions.sessions.view`. Page d'un dossier : création et états, inscriptions (stagiaire nommé), financements ajoutés, modifiés, supprimés (200 derniers évènements).

**Permissions** : `sessions.dossiers.view|update|delete` et `sessions.sessions.view|update|delete`, à périmètre (`own` : responsable ou créateur ; `das` : DAS du dossier ou de la session).

## Finance : factures et avoirs (0.9, RLS)

Module `Finance` (`tms/app/Modules/Finance/`). Toutes les tables ont `tenant_id` et une politique RLS. Montants en **centimes entiers** (`bigint`, HT ; les lignes et totaux d'un avoir sont négatifs). Finance lit les dossiers par `Sessions\Public\Billing` et les fiches par `Crm\Public\Fiches` : aucune clé étrangère vers les tables de ces modules (les références `dossier_line_id`, `enrolment_id`, `funding_id` sont de simples uuid, car une facture reste quand le dossier évolue). Guide : `finance.md`.

### `billing_plans` — facturation d'un dossier
Une ligne par dossier (`dossier_id` unique, FK `dossiers`), créée au premier réglage ; sans ligne : fin de formation, au réalisé, règle d'absences de l'organisme.

| Colonne | Type | Rôle |
|---|---|---|
| `rhythm` | string(10) | `end`, `monthly`, `schedule` (CHECK) |
| `basis` | string(10) | `realised` (défaut), `planned` (CHECK) |
| `unjustified_absences` | string(20), null | `not_billed` / `bill_contractor` ; null = réglage de l'organisme (CHECK) |
| `schedule` | jsonb, null | `[{due_on, percent}]` trié par date, somme 100 ; non nul **si et seulement si** `rhythm = schedule` (CHECK) |
| `locked_at` | timestamptz, null | Posé à la première validation d'une facture du dossier : rythme, base, échéancier et « paie directement » ne changent plus |
| `updated_by`, horodatages | | |

### `finance_settings` — réglages de l'organisme
Une ligne par organisme (`tenant_id` unique), créée au premier changement : `unjustified_absences` (`not_billed` par défaut, `bill_contractor`; CHECK).

### `companies` — colonnes ajoutées (0.9)
`invoice_prefix` (string(10), défaut `F`), `credit_note_prefix` (défaut `AV`) : CHECK `^[A-Z0-9]{1,10}$` pour les deux et **différents** ; `payment_terms_days` (smallint, défaut 30, CHECK 0 à 60) ; `invoice_footer` (text, null) ; `bank_name` (string(100), null), `iban` (string(34), null, sans espaces, clé vérifiée par `App\Rules\Iban`), `bic` (string(11), null, 8 ou 11 caractères, exige l'IBAN) : imprimés sur les factures (« Règlement par virement »), jamais sur un avoir.

### `invoices` — factures et avoirs
| Colonne | Type | Rôle |
|---|---|---|
| `company_id` | uuid (FK `companies`) | Société émettrice |
| `kind` | string(12) | `invoice` / `credit_note` (CHECK) |
| `number` | string(30), null | `<préfixe>-<AAAA>-<NNNNN>` ; nul **exactement** tant que `status = draft` (CHECK) ; unique (`company_id`, `number`) |
| `status` | string(10) | `draft`, `validated`, `sent` (CHECK) |
| `origin` | string(10) | `dossier` (calculée depuis un dossier) / `free` (facture libre) ; CHECK `invoices_origin_check` |
| `dossier_id` | uuid, null (FK `dossiers`) | Dossier ; nul **exactement** pour une facture libre (CHECK `invoices_origin_dossier_check`) |
| `buyer_reference`, `service_code` | string(60), null | Facture libre : n° d'engagement et code service de l'acheteur (Chorus Pro), dans le contenu haché quand renseignés |
| `recipient_type`, `recipient_id` | string(12), uuid | `organisation` / `person` et fiche du destinataire |
| `funding_id` | uuid, null, **sans FK** | Financement d'un financeur payant directement ; nul = le contractant |
| `recipient_key` | string(40), colonne générée stockée | `coalesce(funding_id::text, 'contractor')` : « déjà facturé » par destinataire et un seul brouillon ouvert (index unique partiel `invoices_one_open_draft` sur `dossier_id`, `recipient_key`, `kind` où `status = 'draft'`) |
| `recipient_snapshot` | jsonb, null | Destinataire **figé** à la validation : identité, adresse, SIRET/SIREN, e-mail, n° de dossier, n° d'accord (`$auditPersonal`) |
| `issued_on`, `due_on`, `period_from`, `period_to` | date, null | Émission, échéance, période de formation |
| `cut_off` | string(10), null | Date de coupure du calcul (`2026-09` en mensuel, sinon un jour) |
| `rhythm`, `basis` | string(10) | Photo du plan au moment du calcul |
| `total_ht_cents`, `total_vat_cents`, `total_ttc_cents` | bigint | Totaux |
| `vat_mention` | text, null | Exonération et/ou « Indemnité hors champ de la TVA » |
| `corrected_invoice_id` | uuid, null (FK `invoices`) | Facture corrigée ; **obligatoire pour un avoir** (CHECK `invoices_credit_note_corrects_check`, `NOT VALID` : contrôlé sur toute ligne écrite, sans bloquer un ancien brouillon) et interdit pour une facture |
| `notes` | text, null | Notes internes (`$auditPersonal`) |
| `pdf_path`, `pdf_sha256` | string, char(64) | PDF archivé (`TenantStorage`, zone `invoices`) et son empreinte SHA-256 |
| `hash`, `previous_hash` | char(64), null | Empreinte du contenu canonique, chaînée à la pièce précédente de l'organisme |
| `sent_at`, `email_status`, `email_error_code` | | Dernier envoi (`pending`, `sent`, `failed`) |
| `created_by`, `updated_by`, `validated_by`, `validated_at` | | |

### `invoice_lines` — lignes
`invoice_id` (FK, cascade, index) · `position` · `kind` (`computed` / `free`, CHECK) · `component` (`training`, `dedit`, `absences`, `mention`; **nul exactement pour une ligne libre**, CHECK) · `label`, `description` (`$auditPersonal`) · `quantity` numeric(10,2) · `unit` · `unit_price_cents` · `vat_rate` numeric(4,2) · `total_ht_cents` · `computed_cents` (montant proposé par le calcul : l'écart avec le total montre une modification à la main) · `planned_minutes`, `realised_minutes` · `dossier_line_id`, `enrolment_id`, `funding_id` (uuid sans FK). Les lignes libres et les mentions ne comptent jamais dans le « déjà facturé » (`Ledger`). · `cotraitant_id` (uuid, null, **sans FK** : fiche entreprise du co-traitant qui a réalisé la ligne d'une facture libre ; dans le contenu haché quand renseigné)

### `cotraitant_payouts` — reversements aux co-traitants
Un virement du mandataire à un co-traitant pour ses lignes d'une facture : `invoice_id` (FK `invoices`), `cotraitant_id` (uuid, **sans FK**, fiche entreprise), `amount_cents` (> 0, CHECK), `paid_on`, `reference` (string(100), null), `notes` (text, null, `$auditPersonal`), `created_by`. Jamais écrit sur la facture figée ; le reste à reverser est calculé par `Finance\Payouts\CotraitantPayouts` (TTC des lignes co-traitées de la facture et de ses avoirs validés, moins les reversements), sous verrou de la facture.

### `invoice_sequences` — compteurs de numérotation
Unique (`tenant_id`, `company_id`, `kind`, `year`) : `last_number`. Année de Paris ; incrémenté sous verrou, dans la transaction de validation (un échec rend le numéro : ni trou ni doublon). Le compteur appartient à la société et au type, pas au préfixe.

### `invoice_journal` — journal inaltérable chaîné
Une ligne par pièce validée (`invoice_id` unique) : `number`, `kind`, `issued_on`, totaux HT/TVA/TTC, `hash`, `previous_hash`, `pdf_sha256`, `created_at`, et `position` (bigint, unique par organisme : 1, 2, … : l'ordre de la chaîne). **Append-only.**

### `invoice_sendings` — e-mails d'envoi
Une ligne par « Envoyer » / « Renvoyer » : `invoice_id` (FK), `email`, `subject`, `body`, `status` (`pending` → `sent` ou `failed`, CHECK), `error_code`, `error` (raison fixe en français, jamais le texte du serveur SMTP), `sent_by` (FK `users`), `sent_at`. Le seul changement d'une ligne est `pending` → `sent`/`failed`. L'adresse, l'objet et le message vivent ici, jamais sur la facture, figée.

### Déclencheurs d'immutabilité (migration `2027_01_13_000002_freeze_validated_invoices`)
- `invoices_immutable` (avant `UPDATE` / `DELETE`) : un brouillon se modifie et se supprime librement (sa validation est son dernier changement) ; une pièce validée ou envoyée ne peut changer que `status` (`validated` → `sent`, jamais en arrière), `sent_at`, `email_status`, `email_error_code` et `updated_at` ; toute autre colonne, et toute suppression, lève une exception.
- `invoice_lines_immutable` (avant `INSERT` / `UPDATE` / `DELETE`) : aucune ligne ne s'ajoute, ne change ni ne disparaît quand la facture n'est pas un brouillon ; la facture d'une ligne ne change jamais ; seule la cascade de suppression d'un brouillon est permise.
- `invoice_journal_immutable` : aucune modification ni suppression (comme `audit_logs`).
- Contrôle : `Integrity::verify` (commande `tms:finance:verify`, bouton « Vérifier l'intégrité ») recalcule `hash` = SHA-256(contenu canonique + `previous_hash`), recoupe chaque ligne du journal avec sa facture, vérifie la chaîne par `position` et détecte une pièce émise absente du journal ou un PDF remplacé (`pdf_sha256`).

### `payments` — règlements (0.9.1)
`company_id` (FK `companies`) · `payer_type` (`organisation` / `person`, CHECK), `payer_id` (uuid, sans FK : le destinataire des factures réglées) · `received_on` (date) · `amount_cents` (bigint, CHECK > 0) · `method_id` (FK `reference_items`, liste `payment_method`, `ReferenceUsage`) · `reference` (≤ 100), `notes` (`$auditPersonal`, effacées par l'anonymisation du payeur particulier) · `created_by`, `updated_by`, horodatages. Écrit par `Finance\Payments\Payments` (une transaction, verrous : règlement puis factures par id).

### `payment_allocations` — répartition d'un règlement
`payment_id` (FK, cascade) · `invoice_id` (FK `invoices`, index) · `amount_cents` (CHECK > 0) · unique (`payment_id`, `invoice_id`). Une facture (jamais un avoir) validée ou envoyée, du même destinataire et de la même société que le règlement, au plus son reste dû.

**Reste dû** (calculé, jamais stocké : `Finance\Payments\Balances`) = `total_ttc_cents` − |TTC des avoirs validés qui la corrigent| − Σ `payment_allocations.amount_cents`. Soldée si ≤ 0, en retard si > 0 et `due_on` < aujourd'hui (Paris).

### `invoice_reminder_pauses` — relances suspendues (0.9.1)
`invoice_id` (FK, unique) · `reason` (`$auditPersonal`) · `paused_by` · horodatages. « Reprendre » supprime la ligne.

### Colonnes ajoutées (0.9.1)
- `invoice_sendings.purpose` (`invoice` / `reminder`, CHECK, défaut `invoice`) et `reminder_level` (smallint 1 à 3, non nul **si et seulement si** `purpose = reminder`, CHECK) : une relance est un envoi de la facture.
- `finance_settings.reminder_days` (jsonb, défaut `[7, 30, 60]`) et `auto_reminders` (bool, défaut faux).

### Conservation légale et RGPD
Une facture ou un avoir validé est une **pièce comptable à conserver 10 ans** : le droit à l'effacement ne s'y applique pas. L'anonymisation d'une personne (`Finance\Invoices\InvoiceAnonymisation`, enregistrée dans `Crm\Public\FicheLinks`) **ne touche jamais** une pièce validée ou envoyée (le contenu haché et la chaîne restent intacts, même si une ligne nomme un stagiaire), et **supprime seulement les brouillons** adressés à la personne ou aux dossiers où elle est ou a été stagiaire. `invoice_sendings` (adresse, message) suit la pièce et n'est pas purgée.

## Admissions (candidats, apprenants, alternants)

Toutes les tables ont `tenant_id`, RLS et le trait `BelongsToTenant`. Le module ne lit les fiches du CRM, le catalogue et les dossiers que par leurs services publics.

### `candidatures` — candidatures
`id`, `tenant_id` · `number` (`C-<AAAA>-<NNNN>`, unique par organisme, `CandidatureNumbering`) · `person_id` (FK `people`) · `status` (`identified`, `positioning_sent`, `positioning_received`, `interview`, `admitted`, `rejected`, `withdrawn`, CHECK) · `pathway` (`alternance`, `trainee`, CHECK ; null = à définir ; `alternance` dès qu'un contrat existe) · `training_id` (FK `trainings`, null) · `rncp_code` · `target_session_id` (FK `training_sessions`, null à la suppression) · `das_id` (FK `reference_items`, périmètre « Ses DAS ») · `responsible_user_id`, `created_by` (périmètre « Ses fiches ») · `origin_id` (référentiel `contact_origin`) · `prescriber_organisation_id` (FK `organisations`) · `france_travail_id`, `last_diploma`, `legal_representative` (jsonb) · `rqth`, `adaptation_needs`, `interview_report` (**sensibles** : droit `admissions.sensitive.view`) · `positioning_sent_on`, `positioning_received_on`, `positioning_result` · `interview_at`, `interviewer_user_id` · `decision_reason_id` (référentiel `candidature_refusal_reason`, si `rejected`) · `dossier_id` (FK `dossiers`, unique partiel) · `custom_values` (jsonb, clé = id du champ personnalisé) · `anonymised_at` · horodatages, corbeille. `$auditPersonal` : représentant légal, RQTH, besoins, compte rendu, résultat du test, identifiant France Travail, dernier diplôme, valeurs personnalisées. Situation professionnelle et niveau de diplôme restent sur `people`.

### `candidature_sequences` — compteurs
Clé (`tenant_id`, `year`) : `last_number` (année de Paris, incrémenté dans la transaction de création).

### `candidature_files` — fichiers
`candidature_id` (FK, cascade) · `kind` (`positioning`, `contract`, `custom:<id>`) · `name` (`$auditPersonal`) · `size` · `path` (zone `admissions`) · `uploaded_by`. PDF, JPG ou PNG vérifiés par leur contenu, 20 Mo au plus.

### `alternance_contracts` — contrat d'alternance
`candidature_id` (FK unique, cascade) · `contract_type` (`apprenticeship`, `professionalisation`, CHECK) · `employer_organisation_id` (FK `organisations`) · `tutor_person_id` (FK `people`, null) · `starts_on` ≤ `ends_on` (CHECK) · `opco_organisation_id` (FK `organisations`, null).

### `online_tests`, `online_test_versions`, `online_test_das`, `online_test_trainings`, `online_test_attempts` — tests en ligne
`online_tests` : `title`, `draft_content` (jsonb : sections → questions, `OnlineTestContent`), `time_limit_minutes` (5–240, null = libre), `import_status` (`importing`, `failed`, null), `import_error` (motif fixe), `current_version_id` (version proposée), `created_by`, `updated_by`, corbeille. `online_test_versions` : `online_test_id`, `number` (unique par test), `content` (jsonb figé), `total_points`, `time_limit_minutes`, `published_by`, `created_at` ; jamais modifiée. `online_test_das` (`das_id` → `reference_items`, `ReferenceUsage`) et `online_test_trainings` (`training_id`, cascade) : DAS et formations servis. `online_test_attempts` : une par lien (`positioning_sending_id` unique), `candidature_id`, `online_test_version_id`, `started_at`, `deadline_at` (début + durée), `submitted_at`, `answers` (jsonb, id de question → réponse), `events` (jsonb : sorties de la page, collages et copies refusés), `auto_score`, `manual_scores` (jsonb, id → points), `score` (final, quand tout est noté), `max_score` ; réponses, traces et notes manuelles dans `$auditPersonal` ; réponses et traces effacées par l'anonymisation. `positioning_sendings.online_test_version_id` : le test en ligne du lien (null : tests PDF).

### `positioning_sendings`, `positioning_sending_versions` — envois de tests de positionnement
`positioning_sendings` : `candidature_id` (FK cascade) · `email`, `subject`, `message` (`$auditPersonal`, effacés par l'anonymisation ; le message garde `{lien}`) · `token_hash` (sha256 du lien, unique, sert à la recherche) · `token_encrypted` (le jeton chiffré par la clé de l'application : le lien affiché sur la candidature à qui peut la modifier ; effacé par l'anonymisation) · `expires_at` (envoi + 14 jours, fin de journée de Paris) · `revoked_at` (envoi plus récent, anonymisation) · `submitted_at` (copie déposée, une seule fois) · `email_status` (`pending`, `sent`, `failed`, `none` = lien généré sans e-mail, CHECK), `email_error` (motif fixe) · `sent_at` · `created_by`. `positioning_sending_versions` : (`positioning_sending_id` cascade, `brochure_version_id` restrict) + `position`. Les copies déposées sont des `candidature_files` de type `positioning` (`uploaded_by` null).

### `custom_field_definitions` — champs personnalisés
`entity` (`candidature` seulement aujourd'hui) · `label` · `type` (`text`, `textarea`, `number`, `date`, `boolean`, `choice`, `file`, `nir` (N° de sécurité sociale, vérifié, sensible), CHECK) · `options` (jsonb, choix) · `required_for_admission` · `position` · `hidden_at` (un champ utilisé se masque, ne se supprime pas).

### Catalogue
`trainings.rncp_code` (string(20), null) : code RNCP de la certification (`RNCP` + chiffres).

## Module Documents (0.10)

### `documents` — documents émis
`type` (`convention`, `contract` pour le dossier ; `convocation`, `completion_certificate`, `realisation_certificate` par stagiaire, `enrolment_id` renseigné, CHECK) · `dossier_id` · `enrolment_id` (null) · `company_id` · `number` (`DOC-<année>-<NNNNN>`, unique par organisme) · `recipient_snapshot` (jsonb : nom, adresse, contact, e-mail, `person_id` ; `$auditPersonal`, effacé par l'anonymisation) · `pdf_path` (zone `documents`) · `pdf_sha256` · `status` (`issued`, `sent`, `signature_pending`, `signed`, `cancelled`) · `issued_at`, `issued_by` · `sent_at`, `email_status`, `email_error_code` · `cancelled_at`, `cancelled_by`, `cancel_reason`. Déclencheur `documents_immutable` : jamais supprimé ; seuls l'état, l'envoi, l'annulation et le destinataire figé (anonymisation) changent.

### `document_sequences` — numérotation
(`tenant_id`, `year`) unique · `last_number`.

### `document_sendings` — e-mails des documents
`document_id` · `email`, `subject`, `body` (effacés par l'anonymisation) · `status` (`pending`, `sent`, `failed`) · `error` (motif fixe), `error_code` · `sent_at` · `sent_by`.

### `document_texts` — textes de l'organisme
`company_id` (null = organisme) · `type` · `block` · `body` · `updated_by` ; unique par (organisme, société, type, bloc). Sans ligne : texte d'origine (`DocumentTexts`).

`companies.signature_path` : image de signature et cachet (disque privé).

### `signature_requests` — signatures en ligne (socle)
`subject_type` (alias : `document`, `quote`) · `subject_id` · `title` · `pdf_path`, `pdf_sha256` (le PDF à signer, jamais modifié) · `signer_name`, `signer_email`, `signer_person_id` (personnels, effacés par l'anonymisation) · `token_hash` (sha256 du lien, unique) · `expires_at` (30 jours, fin de journée de Paris) · `status` (`pending`, `signed`, `cancelled`) · `code_hash` (HMAC du code), `code_expires_at`, `code_attempts`, `code_sent_at` · `signed_at`, `signed_ip`, `signed_user_agent` · `signed_pdf_path`, `signed_pdf_sha256` (document signé + page de preuve) · `source_path`, `source_sha256` (HTML figé du document, `<pdf>.source.json`, rendu à nouveau avec le dessin ; vide pour un document émis avant la 0.10.1) · `drawing_path`, `drawing_sha256` (signature dessinée, PNG ; effacée par l'anonymisation) · `reminders_sent` (relances automatiques envoyées), `last_sent_at` (dernière relance ; nouveau lien à chaque relance) · `created_by`.

Permissions : `documents.view`, `documents.manage` (à périmètre, celui du dossier).

## Module Qualité

### `quality_indicators` — suivi d'un indicateur Qualiopi
`referential_version` (`2026`) · `number` (1–33) · unique (`tenant_id`, `referential_version`, `number`) · `applicable` · `not_applicable_reason` (obligatoire si non applicable, CHECK) · `status` (`todo`, `in_progress`, `ready`, `to_review`, CHECK) · `responsible_user_id` (FK `users`, null) · `review_on` · `notes` · `updated_by`. Ligne créée à la première modification ; sans ligne : applicable, à faire. Le texte des indicateurs est dans le code (`QualiopiReferential`), jamais en base.

### `quality_complaints` — réclamations (indicateur 31)

`received_on` (date), `channel_id` (→ `reference_items`, liste `complaint_channel`), `complainant` (string(200), personnel), `subject` (string(200)), `description` (text, null, personnel), `status` (`open` | `processing` | `closed`, CHECK), `response` (text, null, personnel), `responded_on` (date, null), `created_by`. CHECK : une réclamation close a sa réponse et sa date.

### `quality_actions` — actions d'amélioration (indicateur 32)

`title` (string(200)), `description` (text, null), `source_id` (→ `reference_items`, liste `improvement_source`), `complaint_id` (→ `quality_complaints`, null), `responsible_user_id` (→ `users`, null), `due_on`, `status` (`planned` | `in_progress` | `done` | `abandoned`, CHECK), `done_on`, `result` (CHECK : une action réalisée a sa date et son résultat), `created_by`.

### `quality_proofs` — preuves
`referential_version` · `number` · `kind` (`file`, `link`, `note`, CHECK) · `title` · `url` (http/https) · `note` · `path` (zone `quality` de `TenantStorage`), `original_name`, `size` · `created_by`. Supprimée par qui tient le dossier (fichier effacé).

Permissions : `qualite.view`, `qualite.manage` (sans périmètre ; défauts : administrateur et direction).

## Module Questionnaires (0.11)

### `survey_templates` — modèles de questionnaires
`name` · `kind` (`hot`, `cold`, CHECK) · `audience` (`trainee`, `client`, CHECK) · `questions` (jsonb : `id`, `type` `rating|choice|text`, `label`, `required`, `options`) · `active` · `is_default` (un seul par organisme, type et destinataires : index unique partiel hors corbeille) · `created_by`, `updated_by` · suppression logique. Créés au premier usage (`DefaultTemplates`).

### `survey_campaigns` — questionnaire d'une session
`training_session_id` · `template_id` (null si modèle supprimé) · `kind`, `audience` · unique (`tenant_id`, `training_session_id`, `kind`, `audience`) · `title` · `questions` (copie figée) · `qr_token_hash` (sha256 du lien QR, à chaud stagiaires seulement), `qr_token_encrypted` (jeton chiffré, pour réafficher le QR) · `opened_at` · `closes_on` (lancement + 30 jours, Paris) · `launched_by` (null = automatique).

### `survey_invitations` — destinataires
`campaign_id` · `enrolment_id` (stagiaire ; null pour un contact entreprise) · `person_id` · unique (`campaign_id`, `person_id`) · `email` (effacé par l'anonymisation) · `token_hash` (sha256 du lien personnel, unique ; le jeton en clair ne vit que dans la charge chiffrée du job) · `email_status` (`pending`, `sent`, `failed`, `none` = sans adresse) · `email_error` (motif fixe) · `sent_at` · `answered_at`, `answered_via` (`email`, `qr`). Non journalisée.

### `survey_responses` — réponses
`invitation_id` (unique) · `answers` (jsonb : id de question → note, choix ou texte ; textes effacés par l'anonymisation) · `score` (moyenne des notes × 100, 100–500, null sans note) · `submitted_at`. Non journalisée.

Permissions : `questionnaires.view`, `questionnaires.manage` (sans périmètre). Lancer et lire le bloc d'une session suivent les droits de la session.

## Permissions CRM (catalogue global `permissions`)

CRM : `crm.fiches.view|update|delete`, `crm.opportunities.view|update|delete`, `crm.quotes.view|update|delete`, `crm.activities.view|update|delete`, `crm.activities.team`, `crm.revenue.view`, `crm.revenue.manage`, `crm.merge`, `crm.import`, `crm.export`, `crm.gdpr` ; catalogue : `catalogue.view`, `catalogue.manage`, `catalogue.delete`. Les anciens `crm.view|create|update|delete` sont supprimés par la migration 0.5.0 (convertis organisme par organisme). La matrice par rôle est dans `admin.md`. `role_permission_defaults_applied` (`tenant_id`, `role`, `permission`) mémorise les droits par défaut déjà accordés à chaque organisme, pour qu'une mise à jour n'ajoute que les nouveaux droits sans rétablir ceux qu'un administrateur a retirés. `role_permission_scopes` (`tenant_id`, `role_id`, `permission`, `scope` = `own`/`das`/`all`/`trainer`) : périmètre d'un rôle pour un droit à périmètre ; sans ligne, le périmètre vaut `all` ; `trainer` (« Ses séances », 0.8.0) n'est accepté que pour `sessions.seances.view` et `sessions.attendance.manage`.

Finance (0.9) : `finance.invoices.view`, `finance.invoices.manage`, `finance.invoices.validate` (tous à périmètre, celui du dossier ; défauts dans `admin.md` § 10).

Admissions : `admissions.candidatures.view|update|delete` (à périmètre), `admissions.sensitive.view` (défauts dans `admin.md` § 19).

### `follow_up_reminders` — suivis traités (lot 1e)

`kind` (`trainer` : suivi formateur d'une inscription ; `reactivation` : réactivation d'un dossier) · `subject_id`
(inscription ou dossier) · `months` · `activity_id` (action créée) · `outcome` (`created`, `skipped`) · `reason`
(`rachat_en_cours`, `aucun_destinataire`, `fiche_indisponible`) · `processed_at`. Unique (`kind`, `subject_id`, `months`) :
une échéance n'est traitée qu'une fois. Aucune donnée personnelle.

Colonnes ajoutées (lot 1e) : `organisations` et `people` : `future_needs` (texte, personnel pour une personne),
`future_needs_updated_at`, `future_needs_updated_by` ; `reference_items.meta.reached` (résultats d'action « joint »).

### `automation_sends` — envois automatiques (2026-11-22)

`kind` (`convocation` : sujet = inscription, occurrence `1` ; `day_before` : sujet = inscription ou formateur,
occurrence = date du lendemain ; `signature_reminder` : sujet = demande de signature, occurrence = numéro de relance ;
`certificate` : sujet = inscription, occurrence `completion` ou `realisation`) ·
`subject_id` · `occurrence` · `sent_at`. Unique (`kind`, `subject_id`, `occurrence`) : un envoi automatique ne part
qu'une fois, même si la tâche est relancée. Aucune donnée personnelle.

