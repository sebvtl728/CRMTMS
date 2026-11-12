# Dictionnaire de données — lot 1a (socle)

Identifiants : UUID v7 (`uuid`). Dates : `timestamp` (UTC en base ; affichage en heure de Paris). Suppression logique : colonne `deleted_at` (les lignes supprimées restent en base).

## Isolation multi-organismes (RLS)

Toute table métier porte `tenant_id uuid not null` (clé étrangère vers `tenants`), le trait `BelongsToTenant` côté application, et une politique **PostgreSQL Row-Level Security** `tenant_isolation` créée par `Rls::enable('<table>')` :

```sql
tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid   -- using ET with check
```

`ENABLE` et `FORCE ROW LEVEL SECURITY` s'appliquent, y compris au propriétaire. L'application se connecte avec `tms_app` (non propriétaire, `NOBYPASSRLS`) ; la requête positionne `app.tenant_id` avant toute lecture. Sans organisme courant, aucune ligne n'est visible ni écrivable. Seules les migrations utilisent `tms_owner`. Un test d'architecture échoue si une table métier n'a pas `tenant_id` + RLS ; les tables hors périmètre sont listées dans `config/tms.php` (`tenancy.unscoped_tables`) : `tenants`, `tenant_domains`, `migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `permissions`.

## Plateforme (hors RLS)

### `tenants` — organismes
| Colonne | Type | Rôle |
|---|---|---|
| `id` | uuid, PK | Identifiant de l'organisme |
| `name` | string | Nom de l'organisme |
| `slug` | string, unique | Identifiant court (`TMS_DEFAULT_TENANT` en mode `single`) |
| `status` | enum `active` / `suspended` | Un organisme suspendu est inaccessible (503) |
| `settings` | jsonb, défaut `{}` | Paramètres : `security.force_2fa` (bool), `security.idle_timeout_minutes` (15–480, défaut 120) |
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
| `event` | string | `created`, `updated`, `deleted`, `restored`, `auth.login`, `auth.failed`, `auth.logout`, `auth.2fa_enabled`, `auth.2fa_disabled`, `access.role_changed`, `users.invitation_sent`, `settings.security_changed`, `export.run` |
| `auditable_type`, `auditable_id` | string, null | Objet concerné |
| `old_values`, `new_values` | jsonb, null | Valeurs avant / après (secrets : « [masqué] ») |
| `ip` | inet, null | Adresse IP |
| `user_agent` | text, null | Navigateur |
| `created_at` | timestamptz(6) | Date de l'évènement |

Un déclencheur (`audit_logs_immutable`) refuse tout `UPDATE` et `DELETE`, même pour le propriétaire de la table. Index sur (`tenant_id`, `created_at` / `event` / `user_id` / objet).
