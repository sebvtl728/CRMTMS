# Dictionnaire de données — lots 1a (socle) et 1b (fiches, import, RGPD)

Identifiants : UUID v7 (`uuid`). Dates : `timestamp` (UTC en base ; affichage en heure de Paris). Suppression logique : colonne `deleted_at` (les lignes supprimées restent en base).

## Isolation multi-organismes (RLS)

Toute table métier porte `tenant_id uuid not null` (clé étrangère vers `tenants`), le trait `BelongsToTenant` côté application, et une politique **PostgreSQL Row-Level Security** `tenant_isolation` créée par `Rls::enable('<table>')` :

```sql
tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid   -- using ET with check
```

`ENABLE` et `FORCE ROW LEVEL SECURITY` s'appliquent, y compris au propriétaire. L'application se connecte avec `tms_app` (non propriétaire, `NOBYPASSRLS`) ; la requête positionne `app.tenant_id` avant toute lecture. Sans organisme courant, aucune ligne n'est visible ni écrivable. Seules les migrations utilisent `tms_owner`. Un test d'architecture échoue si une table métier n'a pas `tenant_id` + RLS ; les tables hors périmètre sont listées dans `config/tms.php` (`tenancy.unscoped_tables`) : `tenants`, `tenant_domains`, `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `permissions`. Les tables du lot 1b (section « CRM » ci-dessous) sont toutes soumises à la RLS.

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
| `created_at`, `updated_at` | timestamp | |

### `password_reset_tokens` — réinitialisation de mot de passe
PK (`tenant_id`, `email`) · `token` (haché) · `created_at`. `tenant_id` est renseigné par défaut depuis `app.tenant_id`.

### `invitations` — invitations
`id`, `tenant_id` · `email`, `name` · `role` (rôle proposé) · `token_hash` (char 64, unique : sha256 du jeton, jamais le jeton) · `expires_at` (+24 h) · `accepted_at` (null tant qu'en attente) · `user_id` (FK users, null) compte créé · `invited_by` (FK users, null) · horodatages ; index (`tenant_id`, `email`).

### `external_identities` — identités externes (SSO, préparées)
`id`, `tenant_id` · `user_id` (FK, cascade) · `provider` · `subject` · horodatages ; unique (`provider`, `subject`).

### `roles` — rôles de l'organisme
`id`, `tenant_id` · `name` (`admin_organisme`, `direction`, `commercial`, `charge_relations`, `assistant`, `responsable_das`) · `guard_name` · horodatages ; unique (`tenant_id`, `name`, `guard_name`).

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
| `created_at`, `updated_at`, `deleted_at` | timestamp | Suppression logique |

## Référentiels (RLS)

### `reference_items` — valeurs des listes paramétrables
`id`, `tenant_id` · `list` (liste : `das`, `pipeline_stage`, `action_type`, `action_result`, `contact_origin`, `loss_reason`, `establishment_type`, `sector`, `company_size`, `priority`, `funding_mode`, `professional_situation`, `legal_form_org`, `decision_role`) · `code` (slug, CHECK `^[a-z0-9]+(-[a-z0-9]+)*$`) · `label` · `position` (ordre) · `is_system` (non modifiable / supprimable) · `is_active` · `meta` (jsonb : `probability`, `outcome`, `color` selon la liste) · horodatages · `deleted_at`. Unique (`tenant_id`, `list`, `code`) — le code d'une valeur supprimée reste réservé.

## Connecteurs (RLS)

### `connector_configs` — configuration d'un connecteur
`id`, `tenant_id` · `connector_key` (ex. `smtp`) · `company_id` (FK companies, null = tout l'organisme) · `enabled` · `settings` (jsonb, paramètres non secrets) · `secrets` (text, tableau **chiffré** `APP_KEY`) · horodatages. Unique (`tenant_id`, `connector_key`, `company_id`) avec `NULLS NOT DISTINCT`.

### `connector_runs` — journal d'exécution
`id`, `tenant_id` · `connector_config_id` (FK, cascade) · `direction` (`in` / `out`) · `status` (`pending` / `success` / `failed`) · `summary` · `error` (sans secret) · `payload_ref` · `started_at`, `finished_at` · horodatages ; index (`tenant_id`, `started_at`).

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

## CRM : import (lot 1b, RLS)

### `import_profiles` — profils d'import
`id`, `tenant_id` · `name` (100, unique par organisme) · `target` (`organisations`, `people`, `affiliations`, `notes`) · `field_map` (jsonb : colonne du fichier → champ) · `value_map` (jsonb : valeurs du fichier → valeurs des listes) · `options` (jsonb : séparateur, encodage, stratégie de doublon) · `created_by` · horodatages. Un profil d'import Formevol est un simple enregistrement de cette table.

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

## CRM : RGPD (lot 1b, RLS)

### `consents` — consentements
`id`, `tenant_id` · `person_id` (FK, cascade) · `channel` (`email`, `sms`, `phone`, `prospecting`) · `granted` (bool) · `source` (facultatif) · `recorded_by` · `recorded_at` (µs). **Historique en ajout** : un nouveau choix s'ajoute, le dernier par canal fait foi.

### `gdpr_pending` — liste « À anonymiser »
`person_id` (PK, FK cascade) · `tenant_id` · `listed_at`. Une personne y est inscrite par le traitement de nuit quand sa durée de conservation est dépassée ; elle est anonymisée 30 jours après `listed_at` si elle y est toujours.

## Permissions CRM (catalogue global `permissions`)

`crm.view`, `crm.create`, `crm.update`, `crm.delete`, `crm.merge`, `crm.import`, `crm.export`, `crm.gdpr`. La matrice par rôle est dans `admin.md`. `role_permission_defaults_applied` (`tenant_id`, `role`, `permission`) mémorise les droits par défaut déjà accordés à chaque organisme, pour qu'une mise à jour n'ajoute que les nouveaux droits sans rétablir ceux qu'un administrateur a retirés.
