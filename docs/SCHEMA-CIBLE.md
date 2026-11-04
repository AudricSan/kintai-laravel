# Schéma cible du corps (phase 2)

Conçu le 04/11/2026 à partir du schéma final de `Kintai` (branche `develop`, ~100 migrations relues) et des décisions
du plan. Les tables sortent du corps quand leur fonctionnalité part dans un bundle. Le passage des anciennes données
se fait dans l'outil de migration (phase 7), jamais dans l'application.

Conventions : identifiants `id()` (bigint), clés étrangères `foreignId()->constrained()`, booléens `boolean`, heures
`time`, dates `date`, horodatages `timestamp`, données structurées `json`, casts Eloquent pour tout.

## Ce qui change par rapport à l'ancien schéma

| Ancien | Cible | Raison |
|---|---|---|
| `users.password_hash`, `remember_tokens` | `users.password`, `users.remember_token` | Standard Laravel |
| `sessions` supprimée, empreinte de mot de passe en session | Table `sessions` (pilote `database`) | Révoquer les autres sessions (`Auth::logoutOtherDevices`) |
| `api_tokens` | `personal_access_tokens` (Sanctum, `expires_at`) | Standard ; base de l'API mobile |
| `password_reset_tokens` maison | Table standard Laravel | Standard |
| `cron_tokens` | Supprimée : `schedule:run`, et route signée si le cron par URL est gardé | Standard |
| `notifications` maison | Table `notifications` de Laravel (uuid, `data` json) | Notifications Laravel |
| `web_push_subscriptions` | `push_subscriptions` du canal `webpush` | Package standard |
| `role_assignments.scope_type` + `scope_id` | `role_user.store_id` nullable (null = global) | Vraie clé étrangère |
| `role_permissions.scope` (chaîne) | `role_permissions.all_stores` (booléen) | Sens explicite |
| `shifts.start_time/end_time` (chaînes), `cross_midnight`, `duration_minutes`, `starts_at/ends_at` (chaînes) | `starts_at`, `ends_at` (horodatages) + `shift_date` | Calculs exacts (changement d'heure) ; passage minuit et durée calculés |
| `shifts.is_open` + `user_id` nullable (0 ou null selon l'écran) | Shift ouvert = `user_id` null | Une seule vérité (anomalie n°2) |
| `shifts.open_shift_note` + `notes` | `notes` | Doublon |
| `shift_deletion_log` + suppression définitive | `SoftDeletes` + élagage après 30 jours | Le flux iCal lit les shifts supprimés récents |
| `user_dashboard_prefs` + `user_dashboard_layouts` + `dashboard_role_layouts` | `dashboard_layouts` | Trois mécanismes pour un seul besoin |
| `activity_log` (journal métier + journal d'accès HTTP) | `activity_log` (métier) ; accès HTTP dans les logs Laravel | Séparer audit et technique |
| `availabilities.day_of_week` 0-6 dans l'API, 1-7 dans le garde-fou | ISO 1 (lundi) à 7 | Anomalie n°6 |
| Type de dashboard `admin` | `manager` | Nom réel |

**Colonnes abandonnées** (mortes dans `src/`) : `users.last_login_at` (écrite, jamais lue), `store_user.hired_by`,
`stores.daily_report_settings`, `stores.type`, `shifts.estimated_salary`, `user_nav_prefs.section_order`.

**Colonnes qui partent dans des bundles** : `store_user.subject_to_deductions` et les réglages de déduction →
`salary-report` ; `store_import_settings`, `import_aliases` → `planning-import` ; `calendar_*` → `google-calendar` ;
annuaire (`bio`, `skills`, `languages_spoken`, `hobbies`, `show_in_directory`, `share_*`) → `team-directory`.

## Tables

### Identité
**users** : `name` (nom affiché), `first_name`, `last_name` (non nullables), `furigana_first_name`, `furigana_last_name`, `email`
unique, `employee_code` unique nullable, `password`, `remember_token`, `locale` nullable (null = langue de
l'instance), `color` (défaut `#3B82F6`), `avatar_path` nullable, `is_active` (défaut true), `must_change_password` (défaut false ; rappel posé à la connexion si le mot de passe a moins de 8 caractères, levé par tout changement de mot de passe), `quiet_hours_start`,
`quiet_hours_end` (`time` nullable), `share_email`, `share_phone`, `share_mobile_phone` (booléens, défaut false :
coordonnées que l'employé rend visibles à son équipe), colonnes de double authentification du kit, timestamps,
`deleted_at`.
`SoftDeletes` : `deleted_at` posé à l'anonymisation ; les comptes anonymisés disparaissent des listes, l'historique
reste rattaché.

**employee_profiles** (1-1 avec `users`, données RH sensibles à part) : `user_id` unique, `gender`, `birth_date`,
`address`, `postal_code`, `phone`, `mobile_phone`, `tax_classification`, `education`, `guarantor_name`,
`guarantor_phone`. Vidée à l'anonymisation.

**Trois niveaux de visibilité** (validé le 04/11/2026) :
1. Données RH (`employee_profiles`, sauf téléphones partagés) : la personne elle-même et `employees.view` sur son
   magasin, jamais les collègues.
2. Coordonnées partageables (e-mail, téléphone, mobile) : choix de l'employé (`share_*`), règle du corps.
3. Profil d'équipe (bio, compétences, langues, loisirs, présence dans l'annuaire) : table du bundle `team-directory`.

Un bundle ne lit jamais ces tables directement : le contrat lui fournit les membres d'un magasin avec les seules
coordonnées partagées (policy du corps).

**passkeys**, **sessions**, **password_reset_tokens**, **personal_access_tokens** : tables standard.

### Rôles et droits
Stockage maison branché sur le Gate de Laravel (validé le 04/11/2026, sans `spatie/laravel-permission`) : usage
standard `$user->can('shifts.update', $store)`, `@can`, `can:`.

**roles** : `name`, `slug` unique, `description`, `color`, `is_system` (Owner, non modifiable), `is_manager`
(navigation gérant), timestamps.

**role_permissions** : `role_id` (cascade), `permission` (clé du catalogue, déclarée en code par le corps et les
bundles), `all_stores` (booléen : ignore la portée magasin), clé primaire (`role_id`, `permission`).

**role_user** : `id`, `user_id` (cascade), `role_id` (cascade), `store_id` nullable (cascade ; null = global),
`created_at`. Unicité (`user_id`, `role_id`, `store_id`) garantie en code (un `NULL` n'est pas unique en SQL).

Données obligatoires (seeder) : rôles `owner` (système), `manager` (`is_manager`, 14 permissions :
`employees.view/create/update/delete`, `shifts.view/create/update/delete/import`, `stores.view/update`,
`payroll.view/generate/export`), `employee` (aucune permission). Un bundle accorde ses permissions au rôle `manager` à
son installation.

### Magasins
**stores** : `name`, `code` unique, `timezone`, `currency` (3 lettres, défaut `EUR`), `currency_symbol_style`
(défaut `kanji`), `locale`, `address_street`, `address_city`, `address_postal`, `address_country`, `phone`, `email`,
`is_active`, `week_start_day` (ISO, défaut 1), `low_staff_hour_start`, `low_staff_hour_end` (nullable : -1 devient
null), `min_staff_per_day`, `min_shift_minutes` (120), `max_shift_minutes` (480), timestamps, `deleted_at`.

**store_user** : `store_id`, `user_id` (cascade), `staff_code`, `hire_date` (`date`), `contract_type`, `is_active`,
timestamps ; unicité (`store_id`, `user_id`) et (`store_id`, `staff_code`).

**store_features** : `store_id` (cascade), `feature`, `enabled` ; clé primaire (`store_id`, `feature`) ; sans ligne,
la fonctionnalité est active.

### Planning
**shift_types** : `name`, `code`, `starts_at`, `ends_at` (`time`), `hourly_rate` (decimal 10,2), `color`,
`sort_order`, `is_active`, timestamps.

**shift_type_store** : (`shift_type_id`, `store_id`), cascade des deux côtés.

**shift_type_user** (taux personnels) : `user_id`, `shift_type_id` (cascade), `hourly_rate`, timestamps ; unicité
(`user_id`, `shift_type_id`).

**shifts** : `store_id` (cascade), `user_id` nullable (null = shift ouvert ; `set null` si l'utilisateur disparaît),
`shift_type_id` nullable (type dominant, `set null`), `shift_date` (`date`, jour de rattachement), `starts_at`,
`ends_at` (horodatages stockés en UTC, saisis et affichés dans le fuseau de l'instance (`kintai.timezone`) ; `shift_date` est le jour local de rattachement), `pause_minutes`, `notes`, `hourly_rate_override`,
`net_minutes_override`, `ical_uid` (unique), `ical_sequence`, `replaced_by_id` nullable (`shifts`, `set null`),
`created_by` nullable (`set null`), timestamps, `deleted_at`. Index (`store_id`, `shift_date`), (`user_id`,
`shift_date`), (`user_id`, `starts_at`, `ends_at`). Durée, passage minuit et minutes nettes sont des attributs calculés.

**Remplacement** (validé le 04/11/2026) : quand un shift en remplace un autre, l'ancien part à la corbeille avec
`replaced_by_id`, et le nouveau fait foi : l'ancien ne compte plus dans les conflits, ne peut pas être restauré tant
que le remplaçant existe, et, si le remplaçant est créé à cette occasion, il reprend son `ical_uid` avec une séquence + 1 (le calendrier affiche une
modification, pas une annulation). Si le remplaçant existe déjà (donc déjà publié dans les flux), personne ne change
d'`ical_uid` : l'ancien, supprimé, est publié comme annulé et le remplaçant n'est pas modifié. Cas : résolution d'un conflit par une option prédéfinie (« garder le plus récent »…), échange entre
employés (`shift-swap`), reprise d'un shift ouvert (`shift-claim`). Le corps fournit l'opération `replace` ; les
bundles l'appellent par le contrat.

**availabilities** : `user_id`, `store_id` (cascade), `day_of_week` (1-7), `starts_at`, `ends_at` (`time` nullable),
`is_available`, timestamps ; index (`user_id`, `store_id`).

### Communication
**notifications** (Laravel), **notification_preferences** (`user_id` cascade, `type`, `channel`, `enabled` ; unicité
des trois), **push_subscriptions** (canal Web Push), **device_push_tokens** (`user_id` cascade, `token` unique,
`platform`, `last_used_at`).

**activity_log** : `user_id` nullable, `store_id` nullable, `action`, `subject_type` + `subject_id` (morphe
nullable), `properties` (json), `ip_address`, `user_agent`, `created_at` ; index (`action`, `created_at`),
(`user_id`, `created_at`), (`store_id`, `created_at`), (`subject_type`, `subject_id`).

**ical_tokens** : `user_id`, `store_id` (cascade), `token` unique, timestamps ; unicité (`user_id`, `store_id`).

### Tableau de bord, navigation, réglages
**dashboard_layouts** : `user_id` nullable (cascade), `role_id` nullable (cascade ; disposition imposée par rôle),
`dashboard` (`manager` ou `employee`), `name`, `layout` (json : widgets et positions), `is_active`, `position`,
timestamps.

**user_nav_prefs** : `user_id` unique (cascade), `hidden_items` (json), `bottom_nav_items` (json), timestamps.

**app_settings** : `key` (clé primaire), `value` (json), `updated_at`.

## Ordre de construction

La phase 2 crée les migrations, modèles (relations, casts, `$fillable`), factories et seeder pour **identité, rôles,
magasins et planning**. Les tables de communication, du tableau de bord et des réglages sont créées avec leur tranche
de la phase 4.
