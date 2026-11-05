# API v1

Contrat public et stable pour l'application mobile (décision 8 du plan). Une application se branche sur n'importe quelle
instance de Kintai par son adresse (`https://exemple.org`) : elle appelle d'abord `GET /api/v1/ping` pour reconnaître
l'instance, puis `POST /api/v1/auth/login` pour obtenir un jeton.

Cette page décrit le socle et les endpoints du compte (tranche 7a), puis les ressources métier (tranche 7b : utilisateurs, taux,
magasins, membres, types de shift, shifts, disponibilités, journal et exports) à partir de « Ressources métier ».

## Principes

- **Préfixe** : toutes les URL commencent par `/api/v1`. Les routes sont nommées `api.v1.*` (`routes/api.php`).
- **Sans état** : aucune session, aucun cookie, aucun jeton CSRF. La session de l'interface web ne donne aucun accès à l'API.
- **Format** : JSON en entrée (`Content-Type: application/json`) et en sortie, même sans en-tête `Accept`.
- **Dates** : ISO 8601 en UTC (`2026-11-05T09:30:00Z`). Les jours sont au format `YYYY-MM-DD`.
- **Réponses** : une ressource est dans `{"data": {...}}`, une liste dans `{"data": [...]}` (avec `links` et `meta` si elle est
  paginée). Exceptions : `ping`, `auth/login` et `me/data-export`, décrits plus bas. Une action sans résultat répond `204`.
- **Pagination** : `?page=1&per_page=20` (`per_page` de 1 à 100, 20 par défaut). `meta` donne `current_page`, `last_page`,
  `per_page` et `total`.
- **Langue** : les messages d'erreur et les textes (notifications…) sont dans la langue de l'en-tête `Accept-Language`
  (`fr`, `en` ou `ja`), sinon dans celle de l'utilisateur du jeton, sinon dans celle de l'instance.

## Authentification

Authentification par jeton Bearer (Laravel Sanctum) : `Authorization: Bearer <token>`.

- Le jeton est renvoyé en clair **une seule fois**, à la connexion. La base ne garde que son empreinte SHA-256.
- Durée de vie : 90 jours au plus (`expires_in_days`, de 1 à 90). Un jeton expiré est refusé (`401 unauthenticated`).
- Un jeton est supprimé : à la déconnexion, à sa révocation, **au changement de mot de passe du compte** (tous les jetons),
  à la désactivation ou à l'anonymisation du compte. Un compte désactivé reçoit `401` et perd ses jetons.
- Les connexions, déconnexions et révocations sont inscrites au journal d'activité (`auth.api_login`, `auth.api_logout`,
  `auth.api_token_revoked`), les connexions refusées en `auth.login_failed`. Aucun mot de passe ni jeton n'y figure.
- Un mot de passe de moins de 8 caractères doit d'abord être changé sur le site : la connexion répond `403 password_change_required`.

## Format d'erreur

Toute erreur de `/api/*` a la forme :

```json
{ "error": "Message traduit", "code": "code_stable" }
```

Une erreur de validation (`422`) ajoute `errors` (champ => liste de messages) :

```json
{ "error": "The login field is required.", "code": "validation_failed", "errors": { "login": ["The login field is required."] } }
```

| Statut | `code` | Cas |
|---|---|---|
| 400… (autre 4xx) | `bad_request` | Requête invalide (corps illisible…) |
| 401 | `unauthenticated` | Jeton absent, invalide, expiré, révoqué ; compte désactivé |
| 401 | `invalid_credentials` | Connexion refusée (même message pour tous les refus) |
| 401 | `two_factor_required` | Compte avec double authentification : `code` ou `recovery_code` manquant |
| 401 | `invalid_two_factor_code` | Code ou code de récupération faux |
| 403 | `forbidden` | Droit manquant |
| 403 | `password_change_required` | Mot de passe trop court, à changer sur le site |
| 404 | `not_found` | Route ou ressource inconnue, ou ressource d'un autre utilisateur (notifications, jetons) |
| 405 | `method_not_allowed` | Méthode non permise (en-tête `Allow`) |
| 409 | `shift_warnings` | Avertissements sur un shift (`warnings`) : renvoyer avec `force: true` pour passer |
| 422 | `validation_failed` | Données invalides ou règle métier (`errors`) |
| 429 | `too_many_requests` | Trop de requêtes (en-tête `Retry-After`, en secondes) |
| 503 | `maintenance` | Mode maintenance activé par l'Owner (en-tête `Retry-After`) ; `GET /api/v1/ping` et les jetons d'Owner restent servis |
| 500 | `server_error` | Erreur du serveur, sans détail (le mode debug ajoute `exception` et `detail`) |

Les clients se fient à `code`, jamais au texte de `error`. Un code nouveau peut apparaître sur un endpoint ; ceux du tableau ne changent pas.

## Limites

- **300 requêtes par minute et par jeton** (par adresse IP pour une requête sans jeton valide), réglable par l'Owner (écran « Réglages de l'instance », 0 = sans limite), sinon `kintai.api.rate_limit` / `KINTAI_API_RATE_LIMIT`.
  Les requêtes refusées pour jeton invalide ne sont pas comptées par jeton.
- **Connexion** : les limiteurs de la connexion web : 5 tentatives par minute pour un même identifiant + code magasin + IP, et 20 par minute par IP.

## Versionnement

`/api/v1` est stable : on y ajoute des champs et des endpoints, on n'en retire ni n'en renomme pas. Un changement incompatible
ouvrira `/api/v2`. `GET /api/v1/ping` annonce `api_version` ("1") et la `version` de l'application.

## Droits d'accès aux données d'un utilisateur

Les endpoints `/users/{user}/...` valent pour soi-même (`{user}` = son identifiant). Un gérant peut aussi les utiliser pour un
employé qu'il gère : il doit avoir la permission indiquée (`employees.view` pour lire, `employees.update` pour écrire) sur un
magasin de cet employé, et seul un Owner agit sur un Owner. Sans ce droit : `403 forbidden` (avant même de chercher le
compte : un employé ne peut pas deviner quels comptes existent).

## Découverte

### `GET /api/v1/ping`

Public. Aucune donnée sensible.

```json
{
  "name": "Kintai",
  "version": "0.1.0",
  "api_version": "1",
  "features": ["ical", "two_factor", "push_fcm", "push_web", "shifts"],
  "locale": "fr",
  "timezone": "Asia/Tokyo"
}
```

`features` : `ical` (flux iCal), `two_factor` (double authentification), `push_fcm` (push mobile configuré), `push_web` (Web Push
configuré), puis les clés des fonctionnalités de magasin enregistrées (`shifts`, et celles des bundles installés).
`locale` est la langue de l'instance, `timezone` son fuseau (affichage des heures de shift).

## Compte et jetons

### `POST /api/v1/auth/login`

Public. Limité (voir Limites).

| Champ | Règle |
|---|---|
| `login` | requis : e-mail, ou code employé avec `store_code` |
| `store_code` | facultatif : code du magasin (pour la connexion par code employé) |
| `password` | requis |
| `device_name` | requis, 255 caractères au plus : nom du jeton (ex. « Pixel 9 ») |
| `expires_in_days` | facultatif, entier de 1 à 90, 90 par défaut |
| `code` | code à 6 chiffres, si le compte a la double authentification |
| `recovery_code` | à la place de `code` : un code de récupération (il est consommé) |

Réponse `200` : le seul endpoint où `user` n'est pas dans `data`.

```json
{
  "token": "12|aBcD...",
  "expires_at": "2027-02-03T09:30:00Z",
  "user": { "id": 7, "name": "Jane Doe", "email": "jane@example.com", "...": "..." }
}
```

Erreurs : `422 validation_failed`, `401 invalid_credentials` / `two_factor_required` / `invalid_two_factor_code`,
`403 password_change_required`, `429 too_many_requests`.

### `POST /api/v1/auth/logout`

Droit : jeton valide. Révoque le jeton de la requête. `204`.

### `GET /api/v1/auth/me`

Droit : jeton valide. Le compte, ses rôles, ses magasins et ses **permissions effectives**, pour afficher ou masquer des actions
(le serveur reste juge à chaque requête).

```json
{ "data": {
  "user": { "id": 7, "name": "Jane Doe", "first_name": "Jane", "last_name": "Doe", "furigana_first_name": null,
            "furigana_last_name": null, "color": "#3B82F6", "has_avatar": false, "email": "jane@example.com",
            "employee_code": "E001", "locale": null, "is_active": true, "must_change_password": false,
            "two_factor_enabled": false, "created_at": "2026-01-10T08:00:00Z" },
  "is_owner": false,
  "is_manager": true,
  "roles": [ { "id": 2, "name": "Manager", "slug": "manager", "is_system": false, "is_manager": true, "store_id": 3 } ],
  "permissions": { "global": [] },
  "stores": [ { "id": 3, "name": "Alpha", "code": "ALPHA", "timezone": "Europe/Paris", "currency": "EUR", "locale": null,
                "is_active": true, "week_start_day": 1, "address_street": null, "address_city": null,
                "address_postal": null, "address_country": null, "phone": null, "email": null,
                "created_at": "2026-01-01T00:00:00Z",
                "membership": { "staff_code": null, "hire_date": "2026-01-10", "contract_type": null, "is_active": true },
                "permissions": ["employees.view", "employees.update", "shifts.view"] } ]
} }
```

- `permissions.global` : permissions valables sur **tous** les magasins (un Owner les a toutes).
- `stores[].permissions` : permissions effectives sur ce magasin. `stores` liste les magasins dont l'utilisateur est membre ou
  sur lesquels un rôle lui est attribué ; `membership` n'est présent que pour une appartenance.
- `roles[].store_id` : magasin de l'attribution, `null` pour un rôle global.
- Les permissions sont les clés `catégorie.action` du registre (`employees.view`, `shifts.update`, `payroll.export`…).

### `GET /api/v1/auth/tokens`

Droit : jeton valide. Les jetons de l'utilisateur, jamais leur valeur.

```json
{ "data": [ { "id": 12, "name": "Pixel 9", "created_at": "...", "last_used_at": "...", "expires_at": "...", "current": true } ] }
```

### `DELETE /api/v1/auth/tokens/{id}`

Droit : jeton valide ; seulement ses propres jetons (`404` pour celui d'un autre). `204`.

## Notifications

Strictement personnelles (celles de l'utilisateur du jeton) : la notification d'un autre utilisateur est `404`.
Titre et texte sont traduits dans la langue de la requête.

| Méthode et URL | Description |
|---|---|
| `GET /notifications` | Liste paginée, plus récentes d'abord. `?unread=1` pour les non lues, `per_page`. Ajoute `unread_count`. |
| `GET /notifications/{id}` | Une notification. |
| `POST /notifications/{id}/read` | La marque lue ; renvoie la notification. |
| `POST /notifications/read-all` | Marque tout lu. `204`. |
| `DELETE /notifications/{id}` | La supprime. `204`. |

```json
{ "id": "9f1c...", "type": "shift_assigned", "title": "New shift assigned", "body": "A shift was added for you on ...",
  "icon": "calendar-days", "link": "/my-shifts/day?date=2026-11-02", "reference_type": "shift", "reference_id": 5,
  "read": false, "read_at": null, "created_at": "2026-11-01T09:00:00Z" }
```

`link` est un chemin de l'interface web (ou `null`), `reference_type` / `reference_id` désignent l'objet concerné (ou `null`).

## Endpoints d'un utilisateur : `/users/{user}/...`

Droits : voir « Droits d'accès aux données d'un utilisateur ». La colonne Droit donne la permission du gérant.

### Préférences de notification

`GET /users/{user}/notification-preferences` (`employees.update`, comme l'ancienne version) et `PUT` (`employees.update`).

```json
{ "data": { "user_id": 7,
  "preferences": [ { "type": "shift_assigned", "category": "planning", "label": "New shift assigned", "push": true, "mail": true } ],
  "quiet_hours": { "enabled": false, "start": "22:00", "end": "07:00" } } }
```

`PUT` : `preferences` = `{ "<type>": { "push": bool, "mail": bool } }` (seuls les types et canaux cités changent ; un type inconnu
est refusé en `422`), `quiet_hours` = `{ "enabled": bool, "start": "HH:MM", "end": "HH:MM" }` (heures de l'instance ; absent =
inchangé ; début et fin doivent différer). Renvoie la même forme que `GET`.

### Jetons push mobiles (FCM)

| Méthode et URL | Droit | Corps |
|---|---|---|
| `POST /users/{user}/push-tokens` | `employees.update` | `token` (255 caractères au plus), `platform` (`ios` ou `android`) |
| `DELETE /users/{user}/push-tokens` | `employees.update` | `token` |

`POST` renvoie `201` à l'enregistrement, `200` si l'appareil l'était déjà, avec `{ "data": { "id", "platform", "last_used_at", "created_at" } }`
(jamais le jeton). Un appareil partagé qui change de compte passe au nouveau compte. `DELETE` renvoie `204` ; il ne supprime que les jetons de `{user}`.

### Liens iCal

Un lien par employé et par magasin. Le jeton en clair n'existe que dans la réponse de création ou de régénération.

| Méthode et URL | Droit | Description |
|---|---|---|
| `GET /users/{user}/ical-tokens` | `employees.view` | Liens existants (sans valeur) |
| `POST /users/{user}/ical-tokens` | `employees.update` | Crée le lien : corps `store_id`. `201`. `422` s'il existe déjà (utiliser `regenerate`), ou si l'employé n'est pas membre du magasin. |
| `POST /users/{user}/ical-tokens/{store}/regenerate` | `employees.update` | Remplace le lien (l'ancien cesse de fonctionner). `200`. |
| `GET /users/{user}/ical-tokens/{store}` | `employees.view` | Le lien d'un magasin (sans valeur), `404` s'il n'existe pas |
| `DELETE /users/{user}/ical-tokens/{store}` | `employees.update` | Supprime le lien. `204` |

```json
{ "data": { "store_id": 3, "store_name": "Alpha", "created_at": "...", "updated_at": "...",
            "token": "64 caractères hexadécimaux", "url": "https://exemple.org/ical/<token>/3/shifts.ics" } }
```

`token` et `url` ne sont présents qu'à la création et à la régénération.

### Préférences du tableau de bord

`GET /users/{user}/dashboard-prefs` (`employees.view`) et `PUT` (`employees.update`). Paramètre `dashboard` : `manager` ou `employee`
(par défaut `manager` pour un gérant ou un Owner, sinon `employee`).

```json
{ "data": { "user_id": 7, "dashboard": "employee", "source": "default", "layout": null,
  "widgets": [ { "widget": "my-month", "label": "My month", "size": "md" } ],
  "available": [ { "widget": "my-month", "label": "My month", "size": "md", "resizable": true, "default": true } ] } }
```

- `widgets` : la disposition **affichée**, dans l'ordre, après application de la source : disposition personnelle active
  (`source: "personal"`, `layout` = `{id, name}`), sinon celle du premier rôle qui en a une (`"role"`), sinon les widgets par défaut (`"default"`).
  Les widgets inconnus ou non autorisés pour l'utilisateur en sont retirés.
- `available` : les widgets que l'utilisateur peut placer ; `size` est la taille par défaut (`sm`, `md`, `lg`, `full`), `resizable`
  dit si `PUT` peut en choisir une autre.
- `PUT` : `widgets` = liste ordonnée de `{ "widget": "<clé>", "size": "<taille>" }` (`size` facultatif). Crée la disposition personnelle
  de l'utilisateur ou met à jour celle qui est active (`name` facultatif, 100 caractères au plus). Les widgets inconnus ou d'un
  autre tableau de bord sont écartés ; une disposition vide est refusée (`422`). `{ "reset": true }` revient à la disposition du
  rôle ou par défaut (les dispositions personnelles sont conservées, inactives). Renvoie la même forme que `GET`.

### Préférences de navigation

`GET /users/{user}/nav-prefs` (`employees.view`) et `PUT` (`employees.update`).

```json
{ "data": { "user_id": 7, "hidden": ["exports"], "bottom": null, "effective_bottom": ["dashboard", "my-shifts"],
  "items": [ { "key": "dashboard", "label": "Dashboard", "hideable": true } ] } }
```

- `hidden` : clés des entrées de menu masquées ; `bottom` : clés choisies pour la barre mobile du bas, `null` pour la barre par
  défaut ; `effective_bottom` : la barre réellement affichée ; `items` : les entrées du menu de l'utilisateur.
- `PUT` : `hidden` (liste de clés) et `bottom` (2 à 4 clés, `[]` ou `null` pour la barre par défaut). Une clé absente de la requête
  garde sa valeur. Les clés inconnues, non autorisées ou non masquables sont ignorées ; une barre de moins de 2 ou de plus de
  4 entrées est refusée (`422`). Renvoie la même forme que `GET`.

## Exports personnels

Toujours les données de l'utilisateur du jeton : aucun identifiant d'employé n'est lu.

### `GET /api/v1/me/schedule-export`

Droit : jeton valide, et être membre du magasin (`404` sinon).

| Paramètre | Règle |
|---|---|
| `store` | requis : identifiant du magasin |
| `format` | `json` (défaut) ou `ics` |
| `from`, `to` | `YYYY-MM-DD` ; aujourd'hui et +90 jours par défaut, 366 jours au plus |

`json` : `{ "store": {"id","name","timezone"}, "from", "to", "count", "shifts": [ {"date","start_time","end_time","ends_next_day","break_minutes","net_minutes","shift_type","employee","store","notes"} ] }`.
`ics` : un calendrier iCalendar (`text/calendar`). Chaque export est journalisé (`export.schedule`).

### `GET /api/v1/me/data-export`

Droit : jeton valide. Export RGPD des données personnelles : `generated_at`, `identity`, `profile`, `memberships`, `roles`, `shifts`,
`availabilities`, `notification_preferences` (JSON, sans enveloppe `data`). Jamais de mot de passe, de secret 2FA, de jeton ni de code
de récupération. Journalisé (`export.personal_data`).

# Ressources métier (tranche 7b)

Les endpoints ci-dessous réutilisent les actions de l'interface web (`app/Actions/*`) : droits, plafond de rôle, protection des
Owners, unicités et avertissements de shift sont **les mêmes** que sur le site, et les notifications et le journal d'activité sont
produits par les mêmes écouteurs (l'acteur du journal est l'utilisateur du jeton).

Conventions communes :

- Un droit manquant répond `403 forbidden`. Une règle métier répond `422 validation_failed` (`errors`, champ => messages ; une
  règle sans champ précis utilise la clé `shift`, `delete`, `store`…). Une ressource **hors du périmètre** de l'acteur (autre
  magasin que son rôle ne voit pas) répond `404 not_found`, comme une ressource inconnue. Sans le droit de lecture du tout, `403`.
- Les listes sont paginées (`page`, `per_page`). Les booléens de requête s'écrivent `1` ou `0`.
- `PUT` accepte un enregistrement **partiel** : une clé absente garde sa valeur actuelle (`PATCH` est équivalent).
- Les taux horaires sont des chaînes à deux décimales (`"1250.50"`) ; seul `cost` (coût d'un shift) est un nombre.

## Utilisateurs (employés)

Représentation : `id`, `name`, `first_name`, `last_name`, `furigana_first_name`, `furigana_last_name`, `email`,
`employee_code`, `color`, `locale`, `is_active`, `is_owner`, `must_change_password`, `has_avatar`, `created_at`, `stores`
(`id`, `name`, `code`, `staff_code`, `hire_date`, `contract_type`, `is_active`), puis, sur les endpoints à un seul utilisateur,
`roles` (`id`, `name`, `slug`, `is_system`, `is_manager`, `store_id` ; `null` = rôle global) et `profile` (données RH : `gender`,
`birth_date`, `address`, `postal_code`, `phone`, `mobile_phone`, `tax_classification`, `education`, `guarantor_name`, `guarantor_phone`).
Jamais de mot de passe, de secret 2FA ni de jeton.

| Méthode et URL | Droit |
|---|---|
| `GET /users` | `employees.view`. Sans restriction : tous les comptes ; sinon, les membres des magasins de l'acteur. Filtres : `search` (nom, e-mail, code), `store_id`, `is_active` |
| `GET /users/{user}` | soi-même, ou `employees.view` sur un magasin de l'employé (un Owner n'est vu que par un Owner) |
| `POST /users` | `employees.create` (magasin dans le périmètre) |
| `PUT /users/{user}` | `employees.update` (même règle d'accès) |
| `DELETE /users/{user}` | `employees.delete` ; **anonymise** le compte (l'historique reste rattaché) ; `204` |
| `POST /users/{user}/reset-password` | `employees.update` |

Ici, comme pour tous les endpoints `/users/{user}/...`, un utilisateur hors de portée de l'acteur répond `403` (et non `404`) avant
toute recherche du compte.

- `POST /users` : `first_name`, `last_name`, `furigana_first_name`, `furigana_last_name` (requis), `name`, `email`, `employee_code`
  (majuscules, `A-Z 0-9 _ -`, unique), `color` (`#RRGGBB`), `password`, `store_id` (requis si l'acteur est restreint), `role_id`
  (défaut : `employee`), `is_owner` (Owner seulement). Réponse `201` avec `data`. Sans `password`, le compte reçoit le mot de passe
  provisoire et `data.temporary_password` (`"0000"`) est ajouté **à cette réponse seulement** (le compte devra le changer).
  Erreurs `422` : code ou e-mail déjà pris, rôle au-dessus des droits de l'acteur (`role_id`), magasin hors périmètre (`store_id`).
- `PUT /users/{user}` : mêmes champs d'identité, plus `is_active`, `is_owner`, les champs RH du `profile` à plat et
  `store_roles` (objet `{ "<id magasin>": <id rôle> }`). Erreurs `422` : désactiver ou supprimer le dernier Owner actif ou soi-même,
  rôle au-dessus des droits, changer ses propres rôles quand on n'est pas Owner. `403` : toucher à la case Owner sans l'être.
- `POST /users/{user}/reset-password` : sans corps, mot de passe provisoire (`200`, `{"data": {"temporary_password": "0000",
  "must_change_password": true}}`, renvoyé cette seule fois). Avec `password` (règles de mot de passe du site) et `must_change`
  (vrai par défaut) : mot de passe choisi, rien n'est renvoyé (`204`). Dans les deux cas, les jetons de l'employé sont révoqués.

### Taux horaires personnels : `/users/{user}/rates`

Un taux est le taux horaire d'un employé pour un type de shift ; **son identifiant est celui du type de shift**.

```json
{ "data": { "id": 4, "shift_type_id": 4, "shift_type": { "id": 4, "name": "Night", "code": "NIGHT" },
  "hourly_rate": "1250.50", "default_hourly_rate": "1000.00" } }
```

| Méthode et URL | Droit | Corps |
|---|---|---|
| `GET /users/{user}/rates`, `GET /users/{user}/rates/{rate}` | soi-même, ou `payroll.view` sur un magasin de l'employé | |
| `POST /users/{user}/rates` | `employees.update` | `shift_type_id`, `hourly_rate` (`201`) |
| `PUT /users/{user}/rates/{rate}` | `employees.update` | `hourly_rate` |
| `DELETE /users/{user}/rates/{rate}` | `employees.update` | `204` |

`422` : taux déjà défini (`POST`, champ `shift_type_id`), type de shift non rattaché à un magasin commun à l'employé et à l'acteur
(clé `rates.<id>`), taux négatif, non numérique ou à plus de deux décimales. `404` : taux inexistant.

## Magasins

Représentation : `id`, `name`, `code`, `timezone`, `currency`, `currency_symbol_style`, `locale`, `is_active`, `week_start_day`,
adresse (`address_street`, `address_city`, `address_postal`, `address_country`), `phone`, `email`, `low_staff_hour_start`,
`low_staff_hour_end`, `min_staff_per_day`, `min_shift_minutes`, `max_shift_minutes`, `created_at` et `features` (objet clé de
fonctionnalité => activée).

| Méthode et URL | Droit |
|---|---|
| `GET /stores` | `stores.view` ; les magasins du périmètre ; filtre `is_active` |
| `GET /stores/{store}` | `stores.view` sur ce magasin (`404` hors périmètre) |
| `POST /stores` | `stores.create` **sans restriction de magasin** ; `201` |
| `PUT /stores/{store}` | `stores.update` sur ce magasin |
| `DELETE /stores/{store}` | `stores.delete` sans restriction ; suppression douce, le code est libéré ; `204` |

`POST` : `code` (`A-Z 0-9 _ -`, 2 à 20, unique parmi les magasins actifs), `name`, `timezone` (défaut : celui de l'application),
`currency` (`EUR`, `USD`, `JPY`, `GBP`, `CHF`, `KRW` ; défaut `EUR`), `currency_symbol_style` (`kanji` ou `international`), `locale`,
coordonnées, `is_active` (vrai), `week_start_day` (1 à 7, défaut 1), `low_staff_hour_start` / `low_staff_hour_end` (0 à 23),
`min_staff_per_day` (0), `min_shift_minutes` (120), `max_shift_minutes` (480, au moins égal au minimum) et `features` (objet partiel
clé => booléen ; toutes actives par défaut). `422` : code déjà pris, fuseau inconnu, bornes de durée incohérentes.

### Membres : `/stores/{store}/members`

```json
{ "data": { "id": 7, "name": "Sato Hana", "first_name": "Hana", "last_name": "Sato", "email": "hana@example.com",
  "employee_code": "HS01", "color": "#3B82F6", "store_id": 1, "staff_code": "S-2", "hire_date": "2026-01-05",
  "contract_type": "part-time", "is_active": true, "account_is_active": true,
  "roles": [ { "id": 3, "name": "Employee", "slug": "employee" } ] } }
```

| Méthode et URL | Droit |
|---|---|
| `GET /stores/{store}/members`, `GET /stores/{store}/members/{user}` | `employees.view` sur le magasin (`404` si le magasin est hors périmètre). Filtres : `is_active`, `search` |
| `POST /stores/{store}/members` | `employees.update` sur le magasin ; `201` |
| `PUT /stores/{store}/members/{user}` | `employees.update` |
| `DELETE /stores/{store}/members/{user}` | `employees.update` ; retire l'appartenance et les rôles dans ce magasin, les shifts restent ; `204` |

`POST` : `user_id` (requis, compte actif), `role_id` (défaut `employee`), `staff_code` (unique dans le magasin), `hire_date`,
`contract_type`. `PUT` : `staff_code`, `hire_date`, `contract_type`, `is_active` et `role_id` (changement de rôle). `422` : déjà membre,
compte inactif, code déjà pris, rôle au-dessus des droits de l'acteur, modifier ou retirer **soi-même** quand on n'est pas Owner.
`403` : ajouter un Owner sans l'être.

## Types de shift

Représentation : `id`, `name`, `code`, `starts_at` et `ends_at` (heures locales `"H:i"` ; une fin avant le début passe minuit),
`hourly_rate`, `color`, `sort_order`, `is_active`, `store_ids`.

| Méthode et URL | Droit |
|---|---|
| `GET /shift-types` | `shifts.view` ; les types d'au moins un magasin visible. Filtres : `store_id`, `is_active` |
| `GET /shift-types/{type}` | `shifts.view` (`404` si aucun de ses magasins n'est visible) |
| `POST /shift-types` | `shifts.update` ; `201` |
| `PUT /shift-types/{type}` | `shifts.update` sur **tous** les magasins du type |
| `DELETE /shift-types/{type}` | idem ; `204` |
| `POST /shift-types/{type}/stores/{store}` | `shifts.update` sur le magasin ; rattache le type (sans effet s'il l'est déjà) ; `200` avec le type |
| `DELETE /shift-types/{type}/stores/{store}` | idem ; détache le type (sans effet s'il ne l'est pas) |

`POST` / `PUT` : `code` (`A-Z 0-9 _ -`), `name`, `starts_at`, `ends_at` (différent du début), `hourly_rate`, `color` (`#RRGGBB`),
`sort_order`, `is_active`, `store_ids` (au moins un, dans le périmètre de l'acteur). `422` : plage nulle, magasin hors périmètre,
suppression d'un type utilisé par un shift, même supprimé (clé `delete` : désactivez-le), détacher le dernier magasin (clé `store`).
`403` : modifier un type partagé avec un magasin que l'acteur ne gère pas.

## Shifts

Représentation :

```json
{ "data": {
  "id": 12, "store_id": 1, "user_id": 7, "is_open": false, "shift_type_id": 4,
  "starts_at": "2026-11-02T00:00:00Z", "ends_at": "2026-11-02T08:00:00Z",
  "local": { "date": "2026-11-02", "start": "09:00", "end": "17:00", "crosses_midnight": false },
  "pause_minutes": 60, "duration_minutes": 480, "net_minutes": 420, "notes": null,
  "replaced_by_id": null, "is_deleted": false, "deleted_at": null,
  "created_by": 2, "created_at": "...", "updated_at": "...",
  "store": { "id": 1, "name": "Alpha", "currency": "JPY" },
  "user": { "id": 7, "name": "Sato Hana", "color": "#3B82F6" },
  "shift_type": { "id": 4, "name": "Day", "code": "DAY", "color": "#3B82F6" },
  "hourly_rate_override": null, "net_minutes_override": null, "cost": 7000 } }
```

**Heures.** En **entrée**, un shift se décrit en heures **locales de l'instance** (le fuseau de `GET /ping`) : `shift_date`
(`YYYY-MM-DD`, jour de rattachement), `start_time` et `end_time` (`HH:MM`). Une fin non postérieure au début passe minuit
(`22:00` -> `06:00`) ; début et fin égaux sont refusés. En **sortie**, `starts_at` / `ends_at` sont des instants ISO en UTC et
`local` redonne les mêmes heures dans le fuseau de l'instance (`date`, `start`, `end`, `crosses_midnight`).

**Coût.** `cost` (nombre, dans la devise du magasin), `hourly_rate_override` et `net_minutes_override` ne sont présents qu'avec
`payroll.view` sur le magasin du shift ; un employé ne voit jamais le coût de ses shifts. Les surcharges ne se posent qu'avec
`payroll.generate`.

**Périmètre.** Avec `shifts.view`, on voit les shifts des magasins où l'on a ce droit, plus ses propres shifts. **Sans `shifts.view`**
(un employé), on ne voit que ses propres shifts (hors corbeille) : `GET /shifts` (défaut, ou `user_id` = soi-même) et
`GET /shifts/{shift}` ; `user_id` d'un autre ou `open=1` répondent `403`, le shift d'un autre `404`. Toute écriture exige le droit
de l'action sur le magasin du shift (`403`) et un shift dans le périmètre `shifts.view` (`404`).

**Avertissements.** Les avertissements du garde-fou (chevauchement d'un même employé `overlap`, employé indisponible
`unavailable`, durée hors bornes du magasin `too_short` / `too_long`) interrompent `POST /shifts`, `PUT /shifts/{shift}`,
`/move` et `/replace` avec **`409 shift_warnings`** tant que `"force": true` n'est pas envoyé ; rien n'est enregistré :

```json
{ "error": "This shift raises warnings. ...", "code": "shift_warnings",
  "warnings": [ { "kind": "overlap", "message": "...", "conflicting_shift_id": 9 } ] }
```

`kind` : `overlap`, `unavailable`, `too_short`, `too_long` (liste ouverte : un bundle peut en ajouter). Avec `"force": true`
l'opération passe. Un congé approuvé (`time_off`, bundle de congés) **bloque** : `422` sur `user_id`, même avec `force`.

| Méthode et URL | Droit | Réponse |
|---|---|---|
| `GET /shifts` | voir Périmètre | `200`, liste paginée |
| `GET /shifts/{shift}` | voir Périmètre | `200` |
| `POST /shifts` | `shifts.create` sur le magasin | `201` |
| `PUT /shifts/{shift}` | `shifts.update` (sur le magasin, et sur le nouveau s'il change) | `200` |
| `DELETE /shifts/{shift}` | `shifts.delete` ; envoie à la corbeille | `204` ; `422` si déjà supprimé |
| `POST /shifts/{shift}/move` | `shifts.update` | `200` |
| `POST /shifts/{shift}/restore` | `shifts.delete` | `200` ; `422` si non supprimé ou si son remplaçant est actif |
| `POST /shifts/{shift}/replace` | `shifts.update` | `201` avec le remplaçant |
| `POST /shifts/bulk-delete` | `shifts.delete` sur chaque shift | `200` `{"data": {"deleted": 2}}` |
| `GET /shifts/conflicts` | `shifts.view` | `200` |
| `POST /shifts/conflicts/resolve` | `shifts.update` sur les deux shifts | `200`, ou `204` |

- `GET /shifts` : filtres `store_id`, `user_id`, `from` et `to` (jours locaux `YYYY-MM-DD`, sur `shift_date`), `open=1` (shifts
  ouverts), `trashed` (`with` : corbeille incluse ; `only` : corbeille seule ; réservé aux détenteurs de `shifts.view`). Trié par jour
  puis heure de début. `422` : date invalide ou `to` avant `from`.
- `POST` / `PUT` : `store_id`, `user_id` (`null` = shift ouvert), `shift_date`, `start_time`, `end_time`, `pause_minutes`
  (0 à 1440), `notes` (2000 caractères), `hourly_rate_override`, `net_minutes_override`, `force`. À la modification, une clé absente
  garde sa valeur. `422` : champ invalide, magasin inconnu, employé qui n'est pas membre actif du magasin (`user_id`), congé.
- `POST .../move` : `shift_date`, `start_time`, `end_time`, `user_id` (`null` = shift ouvert), `force` ; le reste du shift est conservé.
- `POST .../replace` : saisie du **remplaçant** (mêmes champs que `POST /shifts`). Les champs absents, `user_id` compris, reprennent ceux de l'ancien
  shift ; seul un `user_id: null` explicite donne un shift ouvert. L'ancien shift part à la corbeille (`replaced_by_id`) et le
  remplaçant reprend son identifiant de calendrier iCal. `422` si l'ancien shift est supprimé.
- `POST /shifts/bulk-delete` : `ids` (1 à 500 entiers). **Tout ou rien** : un seul shift interdit répond `403` sans rien supprimer ;
  les identifiants inconnus ou déjà supprimés sont ignorés.
- `GET /shifts/conflicts` : `store_id`, `from`, `to` (défaut : le mois en cours ; 93 jours au plus, sinon `422`). Réponse :
  `{"data": {"overlaps": [ {"overlap_minutes", "keep_id", "first": <shift>, "second": <shift>} ], "too_short": [<shift>], "too_long": [<shift>]}}`
  (`keep_id` : le plus récent, celui que garde la résolution).
- `POST /shifts/conflicts/resolve` : `first_id`, `second_id`. Garde le plus récent, remplace l'autre : `200` avec le shift gardé.
  `204` si l'un des deux a déjà disparu. `422` (clé `shift`) si les deux shifts ne se chevauchent pas.

Chaque opération produit les mêmes notifications (attribution, modification, suppression, réassignation, remplacement,
restauration) et les mêmes entrées de journal (`shift.created`, `shift.updated`, `shift.deleted`, `shift.restored`, `shift.replaced`)
que sur le site.

## Disponibilités

Une disponibilité est une ligne par jour ISO de la semaine (`day_of_week` : 1 = lundi ... 7 = dimanche), par employé et par
magasin : `id`, `user_id`, `store_id`, `day_of_week`, `is_available`, `start`, `end` (`"H:i"` ou `null`). Information
seulement : le planning prévient (`unavailable`), il ne bloque pas.

### `GET /api/v1/availabilities`

Droit : on lit les siennes ; avec `shifts.view`, celles des magasins visibles en plus. Filtres `user_id` (sans `shifts.view`,
un autre employé répond `403`) et `store_id`. Liste paginée, triée par employé, magasin, jour.

### `PUT /api/v1/users/{user}/availabilities/{store}`

Remplace la **semaine entière** de l'employé pour ce magasin. **Soi-même seulement** (`{user}` = l'utilisateur du jeton), membre du
magasin : sinon `403`, même pour un Owner. `404` si le magasin n'existe pas.

```json
{ "days": { "1": { "available": true, "start": "09:00", "end": "17:00" },
            "2": { "available": true },
            "7": { "available": false } } }
```

Les jours absents deviennent indisponibles ; une plage (`start` + `end`, différents) n'a de sens que pour un jour disponible.
Réponse `200` : les sept lignes. `422` : jour hors 1 à 7, début sans fin, début égal à la fin.

Écart avec l'ancienne API : elle exposait un CRUD par identifiant (`POST|PUT|DELETE /availabilities/{id}`) ; le modèle actuel
remplace toujours la semaine entière, d'où ce seul `PUT` par magasin. Ces anciennes URL n'existent pas.

## Journal d'activité

### `GET /api/v1/activity`

Droit : `stores.view`. Un gérant restreint ne voit que les entrées de ses magasins (jamais celles qui n'ont pas de magasin :
connexions, employés, rôles) ; un Owner ou un droit sans restriction voit tout. Filtres : `action` (clé, ex. `shift.created`),
`actor` (identifiant d'utilisateur), `store` (identifiant), `from` et `to` (`YYYY-MM-DD`, jours locaux). Plus récentes d'abord, paginé.

```json
{ "data": [ { "id": 31, "action": "shift.created", "label": "Shift created",
  "actor": { "id": 2, "name": "Manager" }, "store": { "id": 1, "name": "Alpha" },
  "subject": { "type": "Shift", "id": 12 }, "target": "Shift #12",
  "details": [ { "label": "Employee", "value": "Sato Hana" } ],
  "properties": { "employee": "Sato Hana" }, "ip_address": "203.0.113.5", "created_at": "2026-11-02T01:00:00Z" } ] }
```

`label`, `target` et `details` sont traduits (langue de la requête) ; `action` et `properties` sont stables. Aucun secret n'y figure
(filtrés à l'écriture).

## Exports

Les jeux de données de la page « Exports » du site, avec les mêmes droits (par jeu de données et par magasin) ; chaque
téléchargement est journalisé (`export.downloaded`).

### `GET /api/v1/exports`

Les jeux que l'utilisateur peut exporter : `{"data": [ {"key", "label", "formats", "has_period", "per_employee", "store_ids",
"self_service"} ]}`. `store_ids` : magasins où il peut exporter les données des autres ; `self_service` : il peut exporter ses propres
données (`target=me`).

### `GET /api/v1/exports/{dataset}`

Téléchargement d'un fichier. `format` (requis : `csv`, `xlsx`, `pdf` ou `ics`, parmi les `formats` du jeu), `target` (`me` : mes
données ; sinon les magasins), `stores[]` (défaut : tous les magasins permis), `users[]`, `from` et `to` (`YYYY-MM-DD`, pour les jeux
à période, 366 jours au plus). `403` : périmètre non permis ; `404` : jeu inconnu ; `422` : format non disponible, période invalide,
export trop gros pour ce format (clé `export`). Il n'y a pas de format JSON ici : pour ses propres données, voir `me/data-export`.

# API des bundles

Un bundle activé peut exposer sa propre API sous `/api/v1/<slug>` (routes nommées `api.v1.<slug>.*`). Elle suit toutes les
règles de cette API : jeton « Bearer » obligatoire, `Accept-Language`, mode maintenance (503 `maintenance`), limiteur
(`429 too_many_requests`), compte désactivé (`401`), format d'erreur `{error, code}` (`validation_failed`, `forbidden`, `not_found`,
`method_not_allowed`…), listes paginées (`data`, `links`, `meta`, `per_page` de 1 à 100). Un bundle désactivé n'a aucune route :
`404 not_found`. Les bundles activés sont annoncés par `GET /api/v1/ping` (`bundle:<slug>`), les fonctionnalités de magasin
par leur clé. Les droits sont ceux des écrans du bundle (portée magasin comprise).

Différence avec l'ancienne version : les routes d'un bundle sont toutes sous `/api/v1/<slug>/...` (par exemple l'ancien
`/api/v1/notebook-entries` devient `/api/v1/notebook/entries`).

## Carnet (`notebook`)

Droits : `notebook.view` pour lire, `notebook.create` pour publier, l'auteur ou `notebook.manage` pour modifier et supprimer,
`notebook.manage` pour épingler. Une note hors du périmètre de l'appelant (magasin sans le droit, fonctionnalité `notebook`
désactivée sur le magasin) est introuvable (404). Les notes de toute l'organisation (`store_id` nul) sont visibles de tout lecteur.

Une note : `id`, `content`, `pinned`, `store_id` (nul = toute l'organisation), `store_name`, `author` (`id`, `name`), `expires_on`
(`YYYY-MM-DD`, dernier jour affiché, nul = sans expiration), `expired`, `created_at`, `updated_at` (ISO 8601), `can_edit`, `can_pin`.

### `GET /api/v1/notebook/entries`
Notes visibles, épinglées d'abord puis les plus récentes. Filtres : `store_id`, `pinned` (booléen), `include_expired` (booléen,
défaut faux). `403` sans `notebook.view`.

### `GET /api/v1/notebook/entries/{id}`
Une note. `404` si elle n'est pas visible.

### `POST /api/v1/notebook/entries`
Corps : `content` (requis, 5000 caractères au plus), `store_id` (nul ou absent = toute l'organisation, qui exige `notebook.create`
sans restriction de magasin), `expires_on` (`YYYY-MM-DD`). `201` avec la note ; `403` hors périmètre ; `422` validation. Les lecteurs
de la note reçoivent la notification `notebook_entry_created`.

### `PUT /api/v1/notebook/entries/{id}`
Change `content` et/ou `expires_on` (une clé absente garde sa valeur) ; le magasin, l'auteur et l'épinglage ne changent pas.

### `DELETE /api/v1/notebook/entries/{id}`
`204`.

### `POST /api/v1/notebook/entries/{id}/pin`
Épingle la note, ou la désépingle si elle l'est. Exige `notebook.manage` sur le magasin de la note (sans restriction de magasin
pour une note de toute l'organisation).

## Congés (`timeoff`)

Chacun voit et dépose ses propres demandes (membre actif d'un magasin où la fonctionnalité `timeoff` est activée), et peut annuler
une demande en attente. Les gérants voient et traitent celles de leurs magasins : `timeoff.view` (lire), `timeoff.create` (saisir un
congé pour un employé, approuvé d'emblée), `timeoff.update` (corriger), `timeoff.approve` (approuver, refuser), `timeoff.delete`
(supprimer). Une demande hors du périmètre de l'appelant est introuvable (404). Les congés approuvés bloquent les shifts de l'employé
sur ces jours (avertissement bloquant, voir Shifts) et sont publiés dans son flux iCal.

Une demande : `id`, `user` (`id`, `name`), `store` (`id`, `name`), `type` (`vacation`, `sick`, `personal`, `unpaid`, `other`), `status`
(`pending`, `approved`, `refused`, `cancelled`), `start_date`, `end_date` (`YYYY-MM-DD`, bornes incluses), `reason`, `admin_note`,
`processed_by`, `processed_at`, `created_at`, `can_cancel`, `can_decide`.

### `GET /api/v1/timeoff/requests`
Les siennes et celles des magasins où l'on a `timeoff.view`, plus récentes d'abord. Filtres : `store_id`, `user_id`, `status`, `type`,
`from`, `to` (`YYYY-MM-DD` : demandes qui touchent la période). `422` pour une valeur invalide.

### `GET /api/v1/timeoff/requests/{id}`
### `POST /api/v1/timeoff/requests`
Corps : `store_id`, `type`, `start_date` (requis), `end_date` (défaut : `start_date`, pas avant le début), `reason`, `user_id`.
Sans `user_id` (ou le sien) : demande `pending` pour soi, les approbateurs du magasin sont prévenus ; `422` si l'on n'est pas membre du
magasin. Avec le `user_id` d'un autre employé : exige `timeoff.create` sur le magasin, congé `approved` d'emblée (`403`, `404` employé inconnu,
`422` employé hors du magasin). Les champs `status`, `processed_by`, `id` du corps sont ignorés.

### `PUT /api/v1/timeoff/requests/{id}`
Exige `timeoff.update`. Change `type`, `start_date`, `end_date`, `reason`, `admin_note` (une clé absente garde sa valeur) ; jamais l'employé,
le magasin ni le statut.

### `POST /api/v1/timeoff/requests/{id}/approve` et `/refuse`
Exige `timeoff.approve`. Corps facultatif : `admin_note`. `422` (clé `request`) si la demande n'est plus en attente. L'employé est notifié.

### `POST /api/v1/timeoff/requests/{id}/cancel`
L'employé annule sa demande en attente (`422` sinon, `404` si ce n'est pas la sienne).

### `DELETE /api/v1/timeoff/requests/{id}`
Exige `timeoff.delete`, même pour un congé approuvé. `204`.

Différence avec l'ancienne version : `/api/v1/timeoff-requests` devient `/api/v1/timeoff/requests`, et le statut se change par `approve`,
`refuse` ou `cancel` (plus par un `PUT` avec `status`).

## Pointage (`timeclock`)

Chacun pointe et lit ses propres pointages (membre actif d'un magasin où la fonctionnalité `timeclock` est activée). Les gérants lisent
ceux de leurs magasins avec `timeclock.view`, les corrigent avec `timeclock.update` et les suppriment avec `timeclock.delete`. Un
pointage hors du périmètre de l'appelant est introuvable (404).

Un pointage : `id`, `user` (`id`, `name`), `store` (`id`, `name`), `shift_id`, `date` (jour local de l'entrée), `clock_in_at`,
`clock_out_at` (ISO 8601, nul tant que l'employé est en poste), `duration_minutes`, `late_minutes` (retard à l'entrée sur le shift
rattaché, nul sous le seuil de `timeclock.late_threshold_minutes`, 5 par défaut), `running`, `notes`, `can_edit`, `can_delete`.

### `POST /api/v1/timeclock/clock-in`
Corps facultatif : `store_id` (défaut : le premier magasin de l'employé), `client_time` (ISO 8601, heure réelle d'un geste fait hors
ligne ; crue de 24 h dans le passé à 2 min dans le futur, sinon l'heure du serveur est utilisée). `201` avec le pointage. `409
already_clocked_in` si un pointage est déjà en cours ; `422` si l'employé n'est pas membre du magasin choisi (ou d'aucun magasin).

### `POST /api/v1/timeclock/clock-out`
Corps facultatif : `client_time`. Ferme le pointage en cours et calcule sa durée. `404 not_clocked_in` s'il n'y en a pas. Une sortie
antérieure à l'entrée est remplacée par l'heure du serveur.

### `GET /api/v1/timeclock/active`
Le pointage en cours de l'appelant : `{"data": {...}}`, ou `{"data": null}`.

### `GET /api/v1/timeclock/entries`
Les siens et ceux des magasins où l'on a `timeclock.view`, plus récents d'abord. Filtres : `store_id`, `user_id`, `from`, `to`
(`YYYY-MM-DD`, jour de l'entrée), `active` (booléen : en cours ou terminés).

### `GET /api/v1/timeclock/entries/{id}`
### `PUT /api/v1/timeclock/entries/{id}`
Exige `timeclock.update`. Change `clock_in_at`, `clock_out_at` (nul = remet le pointage en cours, refusé si l'employé en a déjà un),
`notes` ; une clé absente garde sa valeur. `422` si la sortie précède l'entrée.

### `DELETE /api/v1/timeclock/entries/{id}`
Exige `timeclock.delete`. `204`.

Différence avec l'ancienne version : `/api/v1/timeclocks/clock-in` devient `/api/v1/timeclock/clock-in` (idem `clock-out`), et la
liste `/api/v1/timeclocks` devient `/api/v1/timeclock/entries`.
