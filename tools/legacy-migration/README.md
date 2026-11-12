# Migration depuis l'ancienne version (Core)

Outil autonome, **définitif et à sens unique** : il lit une base de l'ancienne version de Kintai (branche `develop`, schéma
courant) et écrit dans le nouveau schéma du Core. Il n'utilise aucune classe de `app/` (seulement PDO) et ne fait jamais
partie de l'application ni de l'archive de release (`tools/` est exclu par `tools/release/ReleaseBuilder.php`). La
conception du passage ancien vers nouveau est dans `docs/SCHEMA-CIBLE.md`.

Les premières tranches reprennent le **Core**, puis les données des bundles **timeclock, timeoff, shift-swap et shift-claim** (tranche 2a), des rapports (tranche 2b), des **fiches de paie** (tranche 2c), des **photos de magasin** (2d), de la **messagerie et de l'annuaire** (2g). Les autres bundles feront l'objet de tranches ultérieures.

## Avant de commencer

1. **Sauvegardez** l'ancienne instance (base et `storage/`) ; l'outil ne la modifie pas (base ouverte en lecture seule,
   aucun fichier de la source écrit), mais une sauvegarde reste de bonne pratique.
2. Préparez la cible : un nouveau projet dont `.env` pointe vers une base **vide**, schéma migré :

   ```
   php artisan migrate --force
   ```

   N'exécutez pas l'assistant d'installation (il créerait un Owner) : la cible doit n'avoir **aucun utilisateur** (ni
   magasin, type de quart ou quart). Le seeder des rôles peut avoir été lancé ; sinon les rôles viennent de la source.
   L'import **n'est jamais fusionné** : relancé sur une cible non vide, il est refusé. Pour recommencer, recréez une base
   vide (`php artisan migrate:fresh`) ; les avatars déjà copiés sont à supprimer (`storage/app/private/avatars/`).

## Utilisation

```
php tools/legacy-migration/migrate.php --source=D:\chemin\Kintai [--target-env=.env] [--timezone=Asia/Tokyo] [--dry-run]
php tools/legacy-migration/migrate.php --source-db=fichier.sqlite --source-uploads=dossier/uploads --timezone=Asia/Tokyo
```

| Option | Rôle |
|---|---|
| `--source=DOSSIER` | dossier de l'ancienne instance : sa base est lue dans `config/database.local.php`, ses avatars dans `storage/uploads/avatars/`. |
| `--source-db=FICHIER` | base SQLite seule (au lieu de `--source`) ; `--source-uploads` donne le dossier `uploads` qui contient `avatars/`. |
| `--target-env=FICHIER` | `.env` du nouveau projet (défaut : celui de la racine). Pilotes : `sqlite`, `mysql`, `mariadb`. |
| `--timezone=ZONE` | fuseau de l'ancienne instance. Défaut : son réglage `app_timezone`, sinon l'option est obligatoire. |
| `--dry-run` | fait tout, annule, et affiche le rapport : rien n'est écrit ni copié. Conseillé avant le vrai passage. |

Le fichier `config/database.local.php` est lu **comme des données** (analyse par jetons, jamais exécuté) ; un fichier qui
contient autre chose que des littéraux est refusé. Le fichier SQLite déclaré doit se trouver dans le dossier de
l'instance, sinon utilisez `--source-db`. La source MySQL/MariaDB est ouverte en lecture seule (`SET SESSION TRANSACTION
READ ONLY`).

Tout se fait dans **une seule transaction** côté cible : au moindre échec, la base est inchangée et les avatars déjà
copiés sont supprimés. Code de sortie 0 en cas de réussite, 1 en cas de refus ou d'échec, 2 pour une option inconnue.
Le rapport final donne, par table, les lignes lues, importées et ignorées, puis la liste des avertissements.

## Version source prise en charge

**Une seule** : le schéma actuel de la branche `develop` (migrations de `database/migrations/php/`). L'outil vérifie la
présence de toutes les tables et colonnes qu'il lit ; sinon il refuse avec la liste de ce qui manque. Une instance plus
ancienne doit d'abord être mise à jour (`php scripts/db-migrate.php` dans l'ancien dépôt).

## Ce qui est repris

| Source | Cible | Remarques |
|---|---|---|
| `users` | `users`, `employee_profiles` | identifiants conservés ; `display_name` devient `name`, `language` vide devient `locale` null ; données RH (genre, naissance, adresse, téléphones, garant...) dans `employee_profiles` ; `deleted_at` conservé (comptes anonymisés). Couleur invalide remplacée par la couleur par défaut. |
| `users.password_hash` | `users.password` | empreinte bcrypt reprise telle quelle (voir plus bas). |
| `users.avatar_path` | `users.avatar_path` | fichier copié vers `storage/app/private/avatars/user_<id>_<aléa>.<ext>` (disque `local` de l'application, c'est là que `StoreAvatar` écrit). |
| `roles`, `role_permissions` | `roles`, `role_permissions` | rapprochés par `slug` : un rôle déjà présent dans la cible (Owner, Manager, Employé du seeder) est réutilisé, un rôle non système voit ses permissions **remplacées** par celles de la source ; les autres sont créés. `scope = global` devient `all_stores = 1`. Seules les permissions du Core sont reprises (`employees.*`, `shifts.*`, `stores.*`, `payroll.*`) ; les autres clés (bundles) sont ignorées avec un avertissement. |
| `role_assignments` | `role_user` | `scope_type = global` : `store_id` null ; `store` : `store_id = scope_id`. Portée inconnue, magasin absent ou doublon : ignoré. |
| `stores` | `stores` | `low_staff_hour_*` = -1 devient null ; `week_start_day` 0 devient 7 ; devise en majuscules sur 3 lettres. |
| `store_user`, `store_features` | `store_user`, `store_features` | `hire_date` illisible devient null. |
| `shift_types`, `shift_type_stores`, `user_shift_type_rates` | `shift_types`, `shift_type_store`, `shift_type_user` | un type sans horaires valables est ignoré. |
| `shifts` | `shifts` | heures locales de l'instance converties en UTC (`starts_at`, `ends_at`) ; `shift_date` conservé ; passage minuit géré ; `is_open = 1` devient `user_id` null ; `open_shift_note` fusionné dans `notes` ; quarts supprimés (`deleted_at`) conservés en corbeille ; `ical_uid` généré (nouvel UUID par quart), `ical_sequence` conservé. Un quart sans début et fin valables, ou dont le magasin ou l'employé manque, est ignoré. |
| `availabilities` | `availabilities` | `day_of_week` 0 (dimanche pour l'API) devient 7 ; les autres jours (1 à 7) sont conservés. |
| `app_settings` | `app_settings` | sous-titre, message de connexion, e-mail de support, URL publique, mode sombre, langue, sauvegarde automatique et nombre de sauvegardes gardées ; plus `instance.timezone` (le fuseau utilisé pour la conversion). |

### Tranche 2a : pointage, congés, échanges et reprises de quarts

Les tables de ces bundles sont **facultatives** : absente de la source (bundle jamais installé), l'étape est sautée avec une
note ; présente, elle doit avoir toutes les colonnes lues (`Source::OPTIONAL`), sinon la source est refusée avec la liste
de ce qui manque. Absente de la **cible** (bundle non activé ou non migré), l'étape est sautée avec une note et les lignes
sont comptées comme ignorées : activez les bundles et migrez avant l'import, puis recommencez sur une base vide. Tout est
dans la même transaction (`--dry-run` compris) ; les compteurs apparaissent par table dans le rapport. Identifiants
conservés ; ligne orpheline (employé, magasin ou quart absent après l'import du Core, ou quart non repris) ignorée ;
référence facultative orpheline (`processed_by`, `resolved_by`, `approved_by_id`, quart du pointage) mise à null.

| Source | Cible | Remarques |
|---|---|---|
| `timeclocks` | `timeclocks` | `clock_in_time` / `clock_out_time` (heures locales) convertis en UTC (`clock_in_at`, `clock_out_at`) ; `shift_date` conservé (à défaut, jour local de l'entrée) ; durée reprise, sinon calculée ; entrée illisible : ignoré ; sortie illisible ou antérieure à l'entrée : repris comme pointage ouvert (avertissement). |
| `timeoff_requests` | `timeoff_requests` | dates `Y-m-d` ; fin avant début ou date illisible : ignoré ; type inconnu de la cible devient `other` ; statut `rejected`/`denied` devient `refused`, `canceled`/`withdrawn` devient `cancelled` ; autre statut : ignoré. |
| `shift_claims` | `shift_claims` | `claimed_at` disparaît (`created_at` repris, à défaut `claimed_at`) ; une seule candidature par quart et employé (la première, les doublons sont ignorés) ; `refused`/`denied` devient `rejected`, `cancelled` devient `withdrawn` ; autre statut : ignoré. |
| `shift_swap_requests` | `shift_swap_requests` | la cible exige un collègue **et** un quart cible : une demande sans l'un des deux est ignorée ; `requester_shift_sequence` / `target_shift_sequence` reprennent l'`ical_sequence` importé de chaque quart ; `approved` devient `accepted` (échange appliqué), `accepted` (réponse du collègue seule) devient `pending` avec `accepted_at` conservé ; `applied_*_shift_id` null. |

**Limites** : `shift_swap_requests.accepted_by_id` n'existe plus dans la cible (valeur perdue, comptée dans une note) ;
`shift_claim_offers` et `timeclock_overtime_alerts` n'ont aucun équivalent dans l'ancienne version, il n'y a rien à
reprendre (les alertes d'heures supplémentaires se recalculent). Les statuts ou types inconnus de la cible sont ramenés à
une valeur valable ou la ligne est ignorée, jamais devinés.

### Tranche 2b : rapports d'embauche, de démission et journaliers

Mêmes règles que la tranche 2a (tables facultatives côté source **et** côté cible, étape sautée avec une note, `Source::OPTIONAL`
pour les colonnes lues, identifiants conservés, une seule transaction, `--dry-run` respecté, compteurs par table). Employé ou
magasin absent de la source : la référence facultative (`user_id`, `created_by`, `author_id`, `validated_by`) est mise à null,
seul un magasin absent fait ignorer la ligne. Ces étapes passent après le Core et la tranche 2a.

| Source | Cible | Remarques |
|---|---|---|
| `hiring_reports` | `hiring_reports` | `furigana_last_name` / `furigana_first_name` repris tels quels (le champ combiné `furigana` n'existe plus dans le schéma actuel de l'ancienne version, il a été scindé puis supprimé) ; `birth_date` / `hire_date` illisibles deviennent null. |
| `resignation_reports` | `resignation_reports` | identique ; `deactivated_at` (nouveau dans la cible) reste null : le rapport reprend son rôle d'archive, il ne désactive aucun compte. |
| `daily_reports` | `daily_reports` | `submitted_at`, `validated_at`, `mail_sent_at` et horodatages convertis en UTC ; montants illisibles repris comme 0 (avertissement agrégé) ; statuts `draft`, `submitted`, `validated` (`approved` devient `validated`), tout autre : ligne ignorée ; un seul rapport par magasin et jour (le plus petit `id`, les doublons sont ignorés) ; date illisible : ignoré. |
| `stores.daily_report_settings` (JSON) | `daily_report_settings` | une ligne par magasin ayant un réglage : `auto_send_on_validate`, `auto_validate_time`, `reminder_time`, `no_report_time`, `show_columns` (colonnes connues de la cible), `cumulative_mode`, `mail_recipient_ids` (employés repris, 20 au plus). Valeur illisible : valeur par défaut de la cible ; JSON illisible ou sans clé connue : ligne non créée (valeurs par défaut). |

**Limites** : les rapports journaliers **supprimés** (`deleted_at` renseigné) ne sont pas repris, la cible n'a pas de corbeille
(compté dans une note). Colonnes sans équivalent, donc perdues : `daily_reports.data` (JSON des saisies détaillées),
`is_finalized` / `finalized_by` / `finalized_at` et `pdf_path` / `pdf_generated_at` (colonnes déjà supprimées du schéma actuel
de l'ancienne version ; le PDF est régénéré à la demande, aucun PDF n'est repris). Dans les réglages, les clés inconnues de la
cible (par exemple les anciens `can_submit_roles`, remplacés par les rôles) sont ignorées et comptées dans une note ; les clés
reprises sont supposées porter le même nom que les colonnes de la cible (seule `cumulative_mode` est confirmée par le journal de
l'ancienne version) : une clé écrite autrement est perdue, d'où des valeurs par défaut, à vérifier après l'import.

Horodatages `created_at`/`updated_at`/`deleted_at` : convertis du fuseau de l'instance vers UTC, comme tous ceux de la cible.

### Tranche 2c : fiches de paie (bundle salary-report)

Mêmes règles que les tranches précédentes (tables facultatives côté source **et** côté cible, étape sautée avec une note,
`Source::OPTIONAL`, identifiants conservés, une transaction, `--dry-run`, compteurs par table). Les trois étapes sont
indépendantes : l'absence de `store_deduction_settings` n'empêche pas la reprise de `salary_reports`, et inversement. **Ce sont
des données de paie : rien n'est recalculé, deviné ni « corrigé »** ; une valeur qui ne se convertit pas de façon certaine est
reprise telle quelle, écartée (avec avertissement) ou fait ignorer la ligne.

| Source | Cible | Remarques |
|---|---|---|
| `salary_reports` | `salary_reports` | montants (`total_payment` ... `staff_avg_hourly_wage`, `bank_transfer_salary` compris) repris au centime, sans recalcul ; absents ou vides : 0 ; `target_month` doit être `AAAA-MM` valide, sinon fiche ignorée ; un montant ou un compteur non numérique (ou un compteur négatif) fait ignorer la fiche. |
| `salary_reports.user_id` | `salary_reports.user_id` | vide = fiche globale du magasin. Employé renseigné mais absent de la source : fiche **ignorée** (elle ne devient jamais une fiche globale). |
| `salary_reports.person_in_charge` (texte) | `person_in_charge_id` | repris seulement si **un seul** utilisateur importé porte exactement ce nom d'affichage ; sinon null, avertissement agrégé (nom inconnu / nom ambigu). Le cas échéant, la cible ne vérifie pas que cet utilisateur est responsable du magasin. |
| `salary_reports.employee_work_hours` (texte) | `employee_hours` (JSON) | repris seulement si c'est un JSON valide `[{"user_id": n, "minutes": n}]` (employés importés, minutes entières positives). L'ancienne version stockait du **texte libre** (une ligne « Nom: 7.5h » par employé) : ce texte est donc perdu dans la pratique (null, avertissement agrégé), la cible stocke des minutes par employé et rien n'est reconstruit à partir de noms. |
| `salary_reports.created_by` | `created_by` | absent de la source : null. |
| (aucune) | `deduction_lines` | nouveau dans la cible, laissé à null. |
| `store_deduction_settings` | `salary_report_settings` | une ligne par magasin ; `enabled` devient `deductions_enabled` ; les cinq taux sont repris tels quels ; magasin absent : ignoré ; un taux illisible fait ignorer la ligne (valeurs par défaut de la cible). |
| `store_user.subject_to_deductions` | `salary_report_members` | une ligne (`subject_to_deductions` = 1) par employé soumis aux retenues ; « non soumis » = absence de ligne ; employé ou magasin absent : ignoré. Étape sautée si la colonne n'existe pas. |

**Doublons** : un seul rapport par (magasin, mois, employé ou « global ») : le plus petit `id` est gardé, les autres sont
ignorés avec un avertissement (la contrainte unique de la cible ne protège pas le cas global, `user_id` étant nul).
**Valeurs perdues** (sans équivalent dans la cible) : `salary_reports.store_name` et `employee_name` (texte dénormalisé, relu depuis le
magasin et l'employé), `person_in_charge` et `employee_work_hours` dans les cas ci-dessus. `salary_report_deductions`
(retenues personnalisées) est nouveau dans la cible : rien à reprendre.

### Tranches 2d et 2g : photos de magasin, messagerie et annuaire

Mêmes règles que les tranches précédentes (tables facultatives côté source **et** côté cible, étape sautée avec une note,
`Source::OPTIONAL`, identifiants conservés, une transaction, `--dry-run`, compteurs par table). La messagerie (trois tables) et
les photos (deux tables) sont des étapes « tout ou rien » : si l'une des tables manque côté source ou côté cible, tout le
bundle est sauté avec une note. Ces tables peuvent ne plus exister dans le schéma actuel de l'ancienne version (créées par un
bundle externe) : leur absence n'est pas une erreur.

| Source | Cible | Remarques |
|---|---|---|
| `users.bio`, `skills`, `languages_spoken`, `hobbies`, `show_in_directory` | `team_directory_profiles` | colonnes **facultatives** de `users` (`Source::DIRECTORY_COLUMNS`), lues seulement si elles existent. Une ligne n'est créée que si un champ est rempli ou si `show_in_directory` vaut 0 (sans ligne, l'employé est visible ; colonne absente ou vide = visible). Un champ trop long pour la cible (`bio` 65535, `skills` et `hobbies` 500, `languages_spoken` 255 caractères) est **ignoré avec un avertissement**, jamais tronqué ; une ligne visible dont plus aucun champ ne reste n'est pas créée. |
| `message_threads` | `message_threads` | `subject` vide ou null devient `''` (la cible l'accepte ; rien n'est inventé, le fil s'affichera sans titre) ; sujet de plus de 255 caractères : fil ignoré ; magasin ou créateur absent : ignoré ; dates converties en UTC. La colonne `type` n'existe plus dans la cible, non reprise. Un fil sans participant ni message est repris tel quel (règle la plus simple) ; le créateur n'est pas ajouté aux participants. |
| `thread_participants` | `thread_participants` | fil ou employé absent : ignoré ; doublon (fil, employé) : le plus petit `id` est gardé ; `is_read` et `last_read_at` repris ; `hidden_at` (nouveau) reste null. |
| `thread_messages` | `thread_messages` | fil ou expéditeur absent : ignoré ; texte vide ou date illisible : ignoré ; `sent_at` = `created_at` converti en UTC ; `erased_at` (nouveau) null. |
| `store_photo_submissions` | `store_photo_submissions` | magasin absent : ignoré ; soumission supprimée (`deleted_at`) : ignorée ; soumission dont aucune image n'est reprise : ignorée ; `created_by` absent de la source : null ; `archived_at` null. Non repris : `week_label`, `image_count`, `retention_days`, `compressed`, `compressed_at`, `mail_sent_at` (la cible n'a ni libellé de semaine, ni rétention par soumission, ni envoi de courriel). |
| `store_photo_images` (+ fichier `filepath`) | `store_photo_images` | `filepath` est lu **relatif au dossier `uploads` de la source** (`--source-uploads`, ou `storage/uploads/` de l'instance), avec ou sans le préfixe `uploads/`, avec les mêmes protections que les avatars (chemin absolu, `..`, lien symbolique ou sortie du dossier refusés). Le fichier est copié **tel quel** (jamais re-encodé : l'outil n'utilise aucune classe de l'application) vers `storage/app/private/store-photos/<32 hexadécimaux>.jpg` ou `.png`, le dossier privé du bundle. Seuls JPEG et PNG sont repris (type lu dans le contenu) ; fichier absent, illisible, de plus de 10 Mo ou de plus de 40 millions de pixels, GIF ou WebP : image ignorée avec avertissement (jamais de ligne sans fichier). `filename` est conservé pour l'affichage ; `mime_type`, `filesize`, `width` et `height` sont relus du fichier copié, pas des colonnes de l'ancienne version. En simulation (`--dry-run`) aucun fichier n'est copié. |

Les fichiers copiés sont supprimés si l'import échoue ; pour recommencer sur une base vide, supprimez aussi
`storage/app/private/store-photos/`. Les images gardent leurs métadonnées d'origine (EXIF).

**Rien à reprendre** : les bundles `notebook` et `planning-check` n'ont aucune table dans le dépôt Kintai de l'ancienne version
(à reconfirmer dans les dépôts externes).

### Tranche 2e : calendrier Google (bundle google-calendar)

Mêmes règles que les tranches précédentes (tables facultatives côté source **et** côté cible, étape « tout ou rien » sur les
trois tables, sautée avec une note, `Source::OPTIONAL`, identifiants conservés, une transaction, `--dry-run`, compteurs par
table, avertissement plutôt que troncature).

**Jetons : migrés.** Le `refresh_token` de l'ancienne version est chiffré par son `SecretBox` : `enc1:` + base64(IV 12 octets +
tag 16 octets + chiffré), AES-256-GCM, clé = `sha256('kintai-secretbox-v1|'.<instance_secret>)` où `instance_secret` est la ligne
du même nom de `app_settings` (32 caractères au moins). L'outil réimplémente ce déchiffrement (`src/SecretBox.php`), puis
**rechiffre le jeton avec l'`APP_KEY` du `.env` de la cible** au format du cast `encrypted` de Laravel (`src/AppEncrypter.php`,
AES-256-CBC, le chiffrement du projet). Une valeur sans préfixe est un jeton en clair ; elle est chiffrée de la même façon. Si
la source contient des liaisons et que le `.env` de la cible n'a pas d'`APP_KEY`, l'import est refusé (rien n'est écrit).
**Le jeton et le secret ne sont jamais affichés** (ni rapport, ni simulation, ni message d'erreur).

| Source | Cible | Remarques |
|---|---|---|
| `calendar_connections` | `google_calendar_connections` | seulement `provider = google`. Ignorées : employé absent, doublon (même employé : le plus petit `id`), **jeton illisible** (altéré, chiffré avec un autre secret, secret d'instance absent ou trop court, préfixe `encN:` inconnu, vide) avec un avertissement : l'employé devra reconnecter son agenda. Statut `active` ou `revoked` repris, tout autre statut devient `revoked` (avertissement agrégé). Adresse, `calendar_id` ou nom de plus de 255 caractères : liaison ignorée. `last_sync_at`, `created_at` et `updated_at` convertis en UTC. |
| `calendar_connections.options` (JSON) | `name_mode`, `store_id`, `reconciled_at` | `calendar_name_mode = store` avec un `store_id` repris : mode `store` ; magasin absent (avertissement agrégé) ou autre valeur : `instance` (défaut). `reconciled_at` (horodatage Unix) devient une date UTC. |
| `calendar_event_links` | `google_calendar_event_links` | seulement les liens d'une liaison reprise ; `source_id` devient `source_key` en chaîne : `shift` = id du quart, `timeoff` = `timeoff-<id>` (identifiant stable que lit le bundle, les ids des congés étant conservés par la tranche 2a). Autre type de source, identifiant invalide, doublon (liaison, type, source), empreinte qui n'est pas du SHA-256 hexadécimal ou identifiant d'événement de plus de 255 caractères : ligne ignorée avec avertissement. Un lien dont le quart ou le congé n'a pas été repris est gardé : la réconciliation du bundle supprimera l'événement Google orphelin. |
| `calendar_sync_queue` | `google_calendar_sync_queue` | mêmes règles de liaison, de type et de clé ; `attempts` illisible : 0 ; dates converties en UTC. |

`options` : la structure lue dans le code de l'ancienne version (`CalendarSyncService`) est `calendar_name_mode`, `store_id` et
`reconciled_at` ; les autres clés éventuelles sont ignorées. Les liaisons non reprises ne sont pas supprimées côté Google : les
événements déjà créés restent dans l'agenda de l'employé (à supprimer à la main s'il ne veut pas de doublons après sa
reconnexion).

### Tranche 2f : import de planning (bundle planning-import)

Mêmes règles que les tranches précédentes (tables facultatives côté source **et** côté cible, étape sautée avec une note,
`Source::OPTIONAL`, une transaction, `--dry-run`, compteurs par table, avertissement plutôt que troncature ou correction
silencieuse). Seule la table `store_import_settings` compte : la colonne `stores.excel_import_settings` a été supprimée de
l'ancienne version.

| Source | Cible | Remarques |
|---|---|---|
| `store_import_settings` | `store_import_settings` (clé : `store_id`) | une ligne par magasin (doublon : le plus petit `id`) ; magasin absent : ignoré. Les huit entiers (`col_start`, `col_end`, `base_hour`, `minutes_per_col`, `block_size`, `shift_rows`, `auto_pause_after_minutes`, `auto_pause_minutes`) doivent être compris entre 0 et 65535 (colonnes `unsignedSmallInteger`), `sheet_filter_pattern` non vide, de 255 caractères au plus et valable comme expression régulière PCRE telle que la lit le bundle (entourée de `~`, option `u`, comme `GridSettings::regex`). **Réglage invalide : non repris**, avec un avertissement ; les valeurs par défaut du bundle s'appliquent (rien n'est inventé ni corrigé). Dates converties en UTC. |
| `import_aliases` | `import_aliases` | colonne du nom : `staff_name`, ou `alias_name` dans une base ancienne (avant la migration `2026_07_08_000001`) ; si les deux existent, `staff_name` est préféré (`alias_name` en repli quand il est vide) ; l'une des deux est exigée. Nom mis en minuscules (la cible les stocke ainsi ; avertissement agrégé avec le nombre de noms modifiés) ; deux noms identiques après mise en minuscules dans un même magasin : le plus petit `id` est gardé, l'autre ignoré avec avertissement. Magasin ou employé absent, nom vide ou de plus de 255 caractères : ignoré. `updated_at` = `created_at` (la source n'a pas d'`updated_at`). |

### Mots de passe et `must_change_password`

L'ancienne version vérifiait `password_hash` (bcrypt) avec `password_verify` ; le pilote bcrypt de Laravel (défaut du projet)
vérifie le même format, donc **personne n'a à changer de mot de passe pour se connecter** (la suite de tests le prouve par
`Hash::check` et par une vraie connexion). À la première connexion, Laravel recalcule l'empreinte au coût configuré.

La règle des 8 caractères ne peut pas être appliquée aux anciens comptes : la longueur d'un mot de passe haché est
inconnaissable. L'outil pose `must_change_password` quand l'empreinte correspond à un mot de passe faible connu
(`Importer::WEAK_PASSWORDS` : `0000`, mot de passe attribué par l'ancien code aux comptes créés ou réinitialisés par un
gérant, `1234` et `12345678`). **Limite** : un autre mot de passe court choisi par l'utilisateur n'est pas détecté ; le
rappel se déclenche alors à la connexion, comme dans le nouveau projet (mot de passe saisi de moins de 8 caractères). Une
empreinte absente ou illisible devient un mot de passe inutilisable, avec rappel et avertissement : à réinitialiser.

## Ce qui n'est pas repris

- **Jetons iCal** : l'ancienne version les stockait en clair, la nouvelle ne garde qu'une empreinte. Aucun n'est repris (le
  rapport donne leur nombre) : chaque employé génère un nouveau lien depuis son profil, les anciens abonnements
  d'agenda cessent de fonctionner.
- Sessions, jetons « rester connecté », jetons d'API (`api_tokens`), jetons de réinitialisation, jetons de cron, abonnements
  Web Push et jetons d'appareil : à recréer. Les appareils mobiles doivent se reconnecter.
- Notifications, préférences de notification, journal d'activité (`activity_log`), `shift_deletion_log`, préférences et
  dispositions de tableau de bord, préférences de navigation : non repris dans cette tranche.
- `shifts.estimated_salary`, `users.last_login_at`, autres colonnes abandonnées (voir `docs/SCHEMA-CIBLE.md`).
- Carnet et contrôle de planning : pas de table dans l'ancien dépôt, rien à reprendre.
- Réglages `app_settings` hors de la liste ci-dessus (mascotte, couleurs, réglages de bundles...).

## Tests

```
php artisan test --compact --testsuite=LegacyMigration
```

La suite construit une base source 100 % synthétique (jamais de donnée réelle versionnée) et la migre vers le schéma
migré du projet (bundles timeclock, timeoff, shift-swap et shift-claim activés pour `LegacyBundlesMigrationTest`, hiring-report, resignation-report et daily-report pour `LegacyReportsMigrationTest`, salary-report pour `LegacySalaryMigrationTest`, google-calendar pour `LegacyGoogleCalendarMigrationTest`, store-photos, messaging et team-directory pour `LegacyStorePhotosMigrationTest`, `LegacyMessagingMigrationTest` et `LegacyDirectoryMigrationTest`) : mappings, mots de passe, rôles et portées, magasins et affectations, refus d'une cible non vide, d'un
schéma inconnu, source jamais modifiée, avatars manquants ou hors dossier, retour arrière complet.
