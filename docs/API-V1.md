# API v1

Contrat public et stable pour l'application mobile (décision 8 du plan). Une application se branche sur n'importe quelle
instance de Kintai par son adresse (`https://exemple.org`) : elle appelle d'abord `GET /api/v1/ping` pour reconnaître
l'instance, puis `POST /api/v1/auth/login` pour obtenir un jeton.

Cette page décrit le socle et les endpoints du compte (tranche 7a). Les endpoints des utilisateurs, taux, magasins, membres,
types de shift, shifts, disponibilités et du journal sont ajoutés par la tranche 7b.

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
| 422 | `validation_failed` | Données invalides (`errors`) |
| 429 | `too_many_requests` | Trop de requêtes (en-tête `Retry-After`, en secondes) |
| 500 | `server_error` | Erreur du serveur, sans détail (le mode debug ajoute `exception` et `detail`) |

Les clients se fient à `code`, jamais au texte de `error`. Un code nouveau peut apparaître sur un endpoint ; ceux du tableau ne changent pas.

## Limites

- **300 requêtes par minute et par jeton** (par adresse IP pour une requête sans jeton valide), `kintai.api.rate_limit` / `KINTAI_API_RATE_LIMIT`.
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
