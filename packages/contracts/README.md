# kintai/contracts

Le contrat entre le Core de Kintai et ses bundles. Un bundle exige `"kintai/contracts": "^1.0"` ; Composer refuse un
bundle incompatible à l'installation, et le Core refuse de l'activer (écran « Bundles ») si la version installée ne
satisfait pas la contrainte déclarée par son provider (`requiredContract()`).

Ce package ne dépend jamais de l'application (`App\`) : seulement de PHP et des composants `illuminate/*` qu'il utilise
(`support`, `contracts`, `container`, `database`) et de Carbon. Un test (`tests/Unit/Contracts`) le vérifie.

Version courante : `Kintai\Contracts\Contract::VERSION` (1.3.0).

## Points d'extension

Deux sens. Le bundle *donne* au Core par des registres, remplis dans `BundleServiceProvider::bootBundle()` par les
aides de la classe de base (qui ne s'appliquent que si le bundle est activé). Le bundle *demande* au Core par des
services injectés (interfaces du contrat) et par les événements que le Core émet.

| Besoin | Interface (registre ou service) | Objets valeur | Aide `BundleServiceProvider` |
|---|---|---|---|
| Menu | `Navigation\NavigationRegistry` | `NavigationItem` | `navigation()` |
| Widgets de tableau de bord | `Dashboard\DashboardWidgetRegistry` | `DashboardWidget`, `WidgetSize` | `widgets()` |
| Pages des réglages personnels | `Profile\ProfileSectionRegistry` | `ProfileSection` | `profileSection()` |
| Demandes en attente | `Requests\PendingRequestRegistry` | `PendingRequestProvider` | `pendingRequests()` |
| Fonctionnalités par magasin | `Stores\StoreFeatureRegistry` | | `storeFeature()` |
| Permissions | `Authorization\PermissionRegistry` | | `permissions()` |
| Types de notification | `Notifications\NotificationTypeRegistry` | `NotificationType` | `notificationTypes()` |
| Journal d'activité | `Activity\ActivityActionRegistry` (libellés), `Activity\ActivityRecorder` (écriture) | | `activityAction()` |
| Exports | `Exports\ExportDatasetRegistry` | `ExportDataset`, `ExportColumn`, `ExportScope` | `exportDatasets()` |
| Réglages de l'instance | `Settings\Settings` | `SettingDefinition` | `settings()` |
| Alertes système de l'Owner | `System\SystemAlertRegistry` | `SystemAlert` | `systemAlert()` |
| Congés (garde-fou du planning, iCal) | `Planning\TimeOffChecker`, `Planning\TimeOffProvider` (le bundle remplace la liaison par défaut du Core) | `TimeOffPeriod` | |
| Remplacer un shift | `Shifts\ShiftReplacer` | | |
| Échanger deux shifts (depuis 1.3) | `Shifts\ShiftExchanger` (service du Core, atomique, garde-fous des deux affectations) ; `Shifts\ShiftWarnings` (exception d'avertissements à confirmer) | | |
| Collègues d'un utilisateur et coordonnées partagées (depuis 1.1) | `Stores\ColleagueDirectory` (service du Core) | `Colleague` | |
| Envoyer une notification (depuis 1.1) | `Notifications\Notifier` (service du Core) | | |
| Événements du Core | `Events\*` (ne portent que des identifiants) | | `listen()` |

Événements : `EmployeeCreated`, `EmployeeUpdated`, `EmployeePasswordReset`, `EmployeeRatesUpdated`,
`PasswordChanged`, `ProfileUpdated`, `UserAnonymized`, `RoleSaved`, `RoleDeleted`, `RoleHoldersChanged`, `StoreCreated`,
`StoreUpdated`, `StoreDeleted`, `StoreMemberAdded`, `StoreMemberUpdated`, `StoreMemberRemoved`, `ShiftTypeCreated`,
`ShiftTypeUpdated`, `ShiftTypeDeleted`, `ShiftCreated`, `ShiftUpdated`, `ShiftDeleted`, `ShiftRestored`,
`ShiftReplaced`.

L'utilisateur est toujours désigné par `Illuminate\Contracts\Auth\Authenticatable` ou par un identifiant ; le contrat ne
connaît aucun modèle du Core. Un bundle lit les modèles du Core (`App\Models\...`) directement s'il en a besoin : le
contrat ne le promet pas, ces modèles évoluent avec le Core.

## Droits par défaut des rôles (depuis 1.2)

`permissions($category, $actions, $defaults)` accepte en troisième argument les actions accordées par défaut aux rôles du
seeder, par exemple `['manager' => ['view', 'create', 'manage'], 'employee' => ['view']]` (seuls `manager` et `employee`
sont admis, et seulement des actions déclarées). Le Core les applique à la **première** activation du bundle par
l'Owner : jamais au rôle Owner ni aux rôles personnalisés, sans modifier une permission déjà présente, avec une ligne de
journal `bundle.permissions_granted`. Les slugs déjà initialisés sont mémorisés (réglage `bundles.initialized`) : désactiver
puis réactiver ne remet pas des droits que l'Owner a retirés. `BundleServiceProvider::defaultPermissions()` les expose.

## API d'un bundle (depuis 1.2)

Un bundle qui a un `routes/api.php` (et qui est activé) l'expose sous `/api/v1/<slug>`, routes nommées `api.v1.<slug>.*`.
Les routes passent par la pile de l'API du Core (groupe `BundleServiceProvider::API_MIDDLEWARE`) : langue de la requête
(`Accept-Language`), mode maintenance, limiteur `api`, jeton Sanctum obligatoire et compte actif. Les erreurs ont le
format de l'API du Core `{"error", "code"}` : validation (422 `validation_failed`), refus (`AuthorizationException`, 403
`forbidden`), introuvable (404 `not_found`), et `Api\ApiException` pour un code propre au bundle
(`throw new ApiException(409, 'already_decided', __('...'))`). `Api\ApiController` est une base de contrôleur
(`actor($request)`, `perPage($request)`) sans dépendance à `App\` ; les listes se paginent et se rendent avec une `JsonResource`
comme celles du Core. Voir « API des bundles » dans `docs/API-V1.md`.

## Activation

`BundleServiceProvider` (voir sa documentation) charge les migrations toujours, et les vues, traductions, routes et
enregistrements seulement si l'Owner a activé le bundle. L'activation passe par `Bundle\BundleActivation`, que le Core
implémente (`App\Bundles\BundleManager`) ; hors du Core, un bundle est toujours activé.

## Versions (semver)

- **Majeure** (2.0.0) : tout ce qui casse un bundle existant. Retirer ou renommer une interface, une classe, une
  constante, une méthode ou une propriété publique d'un événement ; changer une signature ou un type de retour ; ajouter
  une méthode à une interface que les bundles *implémentent* (`ExportDataset`, `TimeOffChecker`, `TimeOffProvider`) ;
  retirer une clé du comportement documenté d'un registre.
- **Mineure** (1.1.0) : ajout compatible. Nouvelle classe, nouvel événement, nouvelle aide dans
  `BundleServiceProvider`, nouveau paramètre optionnel en fin de signature, nouvelle méthode d'une interface que seul le
  Core implémente (les registres).
- **Correctif** (1.0.1) : sans effet sur l'API.

Le Core et chaque bundle déclarent la contrainte qu'ils exigent ; un bundle écrit pour `^1.0` fonctionne avec tout 1.x.
