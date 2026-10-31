<!-- Audit statique étendu — Kintai / Laravel — 31 octobre 2026 -->

AUDIT TECHNIQUE & SÉCURITÉ

# Kintai — Migration Laravel
et revue de sécurité

Rapport statique étendu • 31 octobre 2026

Dépôt : AudricSan/kintai-laravel • branche main

Révision examinée : c35fa0c129e2f45dc9a0832990f194115e495e6f

# 0. Résumé exécutif

Le dépôt utilise déjà Laravel comme socle réel : Application, container, providers, routing, middleware, sessions, CSRF, authentification de session, Eloquent, migrations, Blade, réponses Symfony/Laravel et commandes Artisan sont présents. La migration du framework n’est donc pas à recommencer.

En revanche, la couche d’accès aux données conserve une API historique importante : de nombreux modèles Eloquent exposent encore des méthodes statiques findBy…/findAll()/put()/remove() et renvoient des tableaux. Cette compatibilité maintient les contrôleurs et services dépendants d’un ancien contrat de données. C’est le chantier de convergence Laravel le plus important.

Plusieurs extensions maison sont justifiées par les besoins de Kintai — bundles dynamiques, contrats d’extension, RBAC, journal d’audit, import Excel, calendrier, exports. Elles ne doivent pas être supprimées simplement parce qu’elles ne font pas partie du framework.

La revue statique a également relevé des risques de sécurité à traiter ou à valider avant production : absence de protection CSRF sur l’installateur public, politique de mot de passe faible non bloquante sur le web, jetons iCal placés dans le chemin journalisé, chaîne d’approvisionnement des bundles, extraction d’archives, URL publique initiale dérivée de HTTP_HOST, et durcissement des sessions en HTTPS.

| Domaine | Évaluation | Conclusion |

| --- | --- | --- |

| Socle Laravel | En place | Laravel 13.x est déclaré dans Composer et réellement utilisé. |

| Convergence des modèles | À terminer | API DAO/tableaux encore largement utilisée au-dessus d’Eloquent. |

| Compatibilité historique | À rationaliser | Capsule, autoloader dynamique et chargement de routes custom ; certaines fonctions sont des exigences produit. |

| Sécurité applicative | Plusieurs actions nécessaires | Au moins deux sujets prioritaires sont confirmés dans le code consulté. |

| Tests automatisés | Bonne base déclarée | La CI prévoit PHPUnit, PHPStan, composer audit et plusieurs smoke tests ; leur résultat réel n’a pas été exécuté dans cet audit. |

| Validation finale | Non démontrée | Pas d’exécution locale de la suite, pas de test dynamique ni de test d’intrusion dans cette revue. |


# 1. Périmètre, méthode et limites

Périmètre de référence : branche main du dépôt public AudricSan/kintai-laravel, révision c35fa0c129e2f45dc9a0832990f194115e495e6f, consultée le 31/10/2026.

Inventaire Git de cette révision : 1 673 fichiers au total, dont 1 193 fichiers PHP, 381 fichiers PHP sous app/, 332 fichiers PHP sous bundles/, 342 fichiers PHP sous tests/, 60 contrôleurs sous app/Http/Controllers/ et 4 migrations Core.

Méthode : inspection de l’arborescence, de Composer, du bootstrap Laravel, des routes, des configurations, d’un ensemble représentatif de modèles, services, middlewares, contrôleurs sensibles, installateur, sauvegardes, bundles et workflow CI. Les constats sont séparés entre faits directement visibles, risques conditionnels et contrôles non effectués.

Limite importante : il s’agit d’un audit statique étendu, pas d’une certification exhaustive ligne par ligne des 1 193 fichiers PHP. Le dépôt complet n’a pas été cloné et exécuté dans un environnement isolé ; les 332 fichiers PHP des bundles n’ont pas tous été audités individuellement. Aucune commande PHPUnit/PHPStan/Composer Audit n’a été lancée sur le commit examiné, aucun test navigateur, fuzzing, DAST ou pentest n’a été effectué. Le rapport ne prétend donc pas démontrer l’absence de vulnérabilité.

Les chemins cités sont des points d’ancrage vérifiables dans le dépôt. Une observation sur un fichier ne prouve pas automatiquement que tous ses appelants sont vulnérables ; les points nécessitant une analyse de flux sont marqués comme tels.

# 2. État de la migration Laravel

## 2.1 Ce qui est déjà Laravel

composer.json déclare PHP >=8.3 et laravel/framework ^13.19 ; l’autoload PSR-4 App\ → app/ est en place.

bootstrap/app.php construit Illuminate\Foundation\Application via Application::configure(), configure les routes, middlewares et exceptions.

bootstrap/providers.php enregistre des Service Providers Laravel.

routes/web.php, routes/api.php et routes/console.php constituent les points de déclaration des routes.

Les middlewares s’appuient sur Illuminate\Http\Request, le pipeline Laravel et les réponses Symfony.

config/auth.php utilise le guard session Laravel et un provider Eloquent App\Models\User.

Les modèles consultés étendent Illuminate\Database\Eloquent\Model ou Authenticatable.

Les migrations Core utilisent Schema et Blueprint ; les vues applicatives sont majoritairement Blade.

Les commandes et le planificateur sont intégrés à Artisan.

## 2.2 Couche de modèles : principal chantier restant

Les modèles User, Shift, Store, ApiToken, Availability, Role, RoleAssignment, StoreUser, ShiftType et Notification ont été inspectés. Ils étendent bien Eloquent, mais plusieurs conservent une interface de type DAO historique : méthodes statiques de recherche et d’écriture, tableaux en entrée/sortie, conversion toArray() systématique et méthodes put()/remove().

| Fichiers / groupes | Constat observé | Travail cible |

| --- | --- | --- |

| app/Models/User.php | findById/findByEmail/findAll/put/remove retournent ou acceptent des arrays ; $guarded = []. | Migrer progressivement les appelants vers User Eloquent, scopes, relations, casts, policies/services dédiés et attributs explicitement assignables. |

| app/Models/Shift.php | API statique de recherche et CRUD en arrays ; logique de notification dans put/remove. | Remplacer l’API tableau par Eloquent/Query Builder ; déplacer orchestration métier vers ShiftService ; conserver l’événement de changement. |

| app/Models/Store.php | API find*/put/remove en arrays ; fonctions de fonctionnalités et paramètres mêlées au modèle. | Créer relations StoreUser/ShiftType/StoreFeature ; services dédiés pour les règles métier. |

| ApiToken, Availability, Role, RoleAssignment, StoreUser, ShiftType, Notification | Même style de façade statique visible dans les fichiers consultés. | Inventorier tous les consommateurs et migrer par domaine, sans conversion globale brutale. |


Règle de migration : ne pas remplacer mécaniquement tous les arrays par des objets. Certaines lectures massives (ex. Shift::findByStoreBetween() qui utilise toBase()) peuvent être intentionnellement optimisées. Le critère doit être la clarté des contrats, la sécurité des données assignables et la cohérence métier.

## 2.3 Mass assignment

Les modèles inspectés déclarent fréquemment protected $guarded = []. Cela désactive la protection mass-assignment d’Eloquent. Plusieurs contrôleurs construisent actuellement des tableaux explicitement à partir des champs attendus, ce qui réduit le risque sur ces chemins précis, mais la garantie dépend de chaque appelant. Un nouvel endpoint qui transmettrait directement un payload utilisateur à Model::create()/fill() pourrait exposer des colonnes sensibles.

Action : remplacer $guarded = [] par $fillable explicite ou utiliser des DTO/command objects et des méthodes de mutation contrôlées.

Action : rechercher tous les appels Model::put(), fill(), create(), updateOrCreate() et vérifier la provenance de chaque champ.

Action : ajouter des tests de mass-assignment pour password_hash, is_active, deleted_at, remember_token, rôles, permissions, store_id et champs d’audit.

## 2.4 Compatibilité et bootstrap personnalisés

| Fichier | Mécanisme | Décision recommandée |

| --- | --- | --- |

| app/Database/LaravelCapsule.php | Étend Capsule et réutilise le gestionnaire DB Laravel pour maintenir l’API Capsule historique. | Inventorier les consommateurs ; migrer les usages standards vers DB/Schema/Connection Laravel ; garder une façade temporaire pour les bundles non migrés. |

| app/InstalledBundleAutoloader.php | spl_autoload_register() charge les bundles dans storage/bundles/ hors PSR-4 Composer. | À conserver si l’installation dynamique à chaud est une exigence. Documenter les frontières de confiance et les règles d’isolation. |

| app/BundleDiscoveryService.php | Découverte conventionnelle des bundles monorepo et installés. | Fonction produit légitime ; supprimer uniquement la branche legacy après migration complète des bundles concernés. |

| app/BundleManager.php | require manuel des routes et enregistrement des bundles comme Service Providers Laravel. | Extension acceptable pour les bundles ; vérifier compatibilité route cache et ordre de chargement. |

| app/Http/KintaiHttp.php | Enregistrement manuel des routes et handlers PHP error/shutdown. | Évaluer si les handlers doublonnent le gestionnaire d’exceptions Laravel ; conserver seulement les comportements fatals non couverts. |

| app/Http/Macros.php | Request::macro et ResponseFactory::macro. | Les macros sont Laravel-native. Garder les macros métier ; supprimer celles qui ne font que répliquer une API framework standard. |

| app/Http/ErrorResponder.php | Adaptateur de rendu d’erreurs et de pages HTML/JSON. | Peut rester un renderer applicatif branché sur withExceptions ; éviter de dupliquer le reporting et le mapping Laravel. |

| app/Http/FileStreamResponse.php | Sous-classe StreamedResponse avec méthode header(). | Confort API mineur ; vérifier si response()->streamDownload()/Symfony suffit. |


## 2.5 Autres convergences à examiner

Validation : app/Validation/PayloadValidator.php et ApiPayloadRules.php sont des abstractions maison. Vérifier si les règles simples peuvent devenir Validator/FormRequest/Rule Laravel ; garder les validateurs métier réutilisables.

HTTP sortant : app/Services/HttpFetcher.php implémente cURL et stream wrappers alors que Guzzle et Laravel HTTP Client sont disponibles. Standardiser les timeouts, limites de taille, redirections, erreurs et vérification TLS.

Dates : plusieurs modèles et services utilisent date() et time(). Préférer now()/Carbon et le timezone applicatif là où les dates sont métier ; ne pas faire un remplacement aveugle pour les formats externes.

Accès aux requêtes : les macros postFlag(), hasPost(), slashPath() et wantsJsonResponse() prolongent Request. Vérifier leur nécessité, leur documentation et leur usage cohérent.

Contrôleurs : les contrôleurs retournent souvent response($view->render()) et utilisent des tableaux. Ce n’est pas non-Laravel en soi, mais les Form Requests, RedirectResponse, JsonResource et modèles Eloquent peuvent réduire le code de plomberie.

Installateur : public/install.php est volontairement autonome et utilise $_POST/$_SERVER et des headers PHP directs. Ce choix est défendable pour un installateur sans base/sessions, mais doit être traité comme une surface d’attaque distincte.

BackupService et WebInstaller utilisent encore Capsule/ PDO directement. Distinguer les usages de maintenance/installation (potentiellement légitimes) des requêtes applicatives ordinaires.

# 3. Sécurité — constats et risques

Échelle de priorité utilisée : P0 = bloquant avant exposition publique ; P1 = corriger avant production ou prochaine release ; P2 = durcissement important ; P3 = amélioration. Ce classement est qualitatif et non un score CVSS.

| ID / priorité | Constat et preuve dans le code | Impact / précondition | Mesure recommandée |

| --- | --- | --- | --- |

| SEC-01 • P1 | Installateur sans CSRF visible : public/install.php traite directement $_POST ; la vue resources/views/install/index.php indique explicitement qu’il n’y a pas de jeton CSRF. | Pendant qu’une instance n’est pas installée, un navigateur peut être amené à soumettre un formulaire cross-site vers l’installateur. Peut permettre de déclencher une installation avec des paramètres contrôlés par l’attaquant, selon le déploiement et la possibilité d’atteindre l’installateur. | Ajouter un nonce d’installation à usage unique ou un mécanisme équivalent ; protéger les POST ; lier le formulaire à une session/cookie SameSite ; verrouiller atomiquement l’installation ; limiter l’exposition réseau de l’installateur. |

| SEC-02 • P1 | Mot de passe par défaut 0000 : PasswordPolicy::DEFAULT_PASSWORD ; AdminUserController::resetPassword() réinitialise à cette valeur. PasswordReminderMiddleware ne fait qu’afficher un rappel ; il ne bloque pas les routes web. | Un compte dont le mot de passe est faible peut continuer à utiliser l’application web. Le risque est élevé si les comptes sont prévisibles ou si l’instance est exposée. L’API bloque la délivrance d’un token, mais le web n’impose pas le changement. | Imposer un changement avant toute page métier ; n’autoriser que profil/changement/logout ; exiger un mot de passe aléatoire à la création/réinitialisation ou un lien d’activation ; révoquer toutes les sessions et tokens ; ajouter tests de parcours web/API. |

| SEC-03 • P1 | Jeton iCal dans le chemin URL : routes/web.php expose /ical/{token}/{store_id}/shifts.ics ; AccessLog journalise slashPath() dans activity_log. | Le jeton est un secret porteur d’accès au flux. Il apparaît dans les journaux applicatifs d’accès et potentiellement dans les journaux HTTP du serveur, reverse proxy, monitoring et historique navigateur. | Ne jamais journaliser le segment secret ; filtrer/masquer le token dans AccessLog ; préférer un header si le client le permet ; rotation/révocation ; traiter les anciens logs comme potentiellement sensibles. |

| SEC-04 • P1 | Chaîne de confiance des bundles : BundleInstallerService installe du PHP depuis des releases GitHub. expectedCommit peut être null ; le code logue bundle_install_unpinned puis continue l’installation. Les bundles s’exécutent dans le processus applicatif. | Un bundle tiers est du code arbitraire avec les privilèges du compte PHP et l’accès aux données/secrets de l’instance. L’épinglage absent ou un registry compromis augmente le risque de supply-chain. | Exiger un commit épinglé pour tout bundle ; signature éditeur indépendante ; allowlist d’éditeurs ; revue de code avant activation ; permissions minimales ; sandbox séparé si possible ; afficher clairement l’origine et le hash installé. |

| SEC-05 • P1 | Import d’instance : WebInstaller::importSource() accepte un chemin local fourni par l’installateur puis require le fichier config/database.local.php de cette source après quelques vérifications de structure. | Si un chemin local contrôlable par un attaquant ou un autre tenant peut être présenté et contient les marqueurs attendus, le require exécute du PHP avec les droits du serveur. Précondition importante : accès au parcours d’installation et présence d’un dossier local préparé. | Limiter l’import à des sources approuvées, chemins canoniques explicitement autorisés ; ne jamais require une config importée ; parser un format de configuration de données ; désactiver l’import web ou le réserver à une commande CLI. |

| SEC-06 • P2 | URL publique initiale : public/install.php construit l’URL depuis HTTP_HOST et la transmet à PublicUrlResolver::normalize(). Le resolver évite ensuite Host pour les demandes de reset, mais l’installateur enregistre cette valeur. | Un Host header contrôlé au moment de l’installation peut empoisonner l’URL publique sauvegardée ; les liens de reset et notifications pourraient pointer vers un domaine hostile. | Ne pas utiliser HTTP_HOST comme source de confiance ; exiger APP_URL saisi/confirmé par l’administrateur ; vérifier le domaine et le schéma ; afficher une confirmation avant enregistrement. |

| SEC-07 • P2 | SESSION_SECURE vaut env('SESSION_SECURE') sans valeur par défaut forcée dans config/session.php. | Si HTTPS est terminé par un proxy et que SESSION_SECURE n’est pas explicitement activé, le cookie peut ne pas être marqué Secure. Le comportement exact dépend de l’environnement serveur/proxy. | Exiger SESSION_SECURE=true en production HTTPS ; documenter reverse proxy et trusted proxies ; ajouter un test de configuration de production. |

| SEC-08 • P2 | HttpFetcher active CURLOPT_FOLLOWLOCATION et les redirections stream sans liste blanche de destinations ni limite explicite de redirections visible dans le code consulté. | Si une URL initiale contrôlable par un utilisateur/registry redirige vers une adresse interne, le client serveur peut accéder à une ressource non prévue (risque SSRF conditionnel). | Limiter les hôtes autorisés après chaque redirection ; interdire IP privées/link-local/loopback ; limiter le nombre de redirections et la taille téléchargée ; n’accepter que HTTPS ; auditer tous les appelants et leurs sources d’URL. |

| SEC-09 • P2 | Extraction d’archives : BundleInstallerService::extractZip() et BackupService::restore() utilisent ZipArchive::extractTo() sans prévalidation visible de chaque nom d’entrée. | Un ZIP malveillant peut tenter des chemins de sortie ou des liens symboliques. L’exploitabilité dépend de la version de libzip/PHP et du comportement de l’archive ; non démontrée ici. | Énumérer les entrées avant extraction ; rejeter chemins absolus, .., NUL, séparateurs inattendus et symlinks ; vérifier realpath après extraction ; limiter nombre/taille/ratio de compression ; tester Zip Slip et bombes ZIP. |

| SEC-10 • P2 | GET cron : CronController::run() déclenche une commande planifiée par GET ; le token peut être transmis en query string (documenté), même si l’en-tête Bearer est aussi accepté. | Les URL complètes peuvent être stockées dans les logs du serveur, historiques, outils de monitoring et Referer. Une requête GET déclenche un effet de bord si le token fuit. | Imposer Authorization: Bearer ; déprécier ?token= ; utiliser POST ; filtrer les logs ; rotation et expiration des tokens ; limiter les tâches autorisées par token. |

| SEC-11 • P2 | LoginThrottleMiddleware documente explicitement qu’un tiers connaissant l’identifiant peut verrouiller ce compte pendant 15 minutes en provoquant 10 échecs. | Déni de service ciblé contre un compte. C’est un compromis de conception connu, pas une vulnérabilité non documentée. | Ajouter une stratégie progressive plutôt qu’un verrouillage dur, alertes et déblocage sûr ; conserver un limiteur IP ; surveiller les attaques distribuées. |

| SEC-12 • P2 | bootstrap/app.php remplace la pile middleware globale Laravel par une pile personnalisée et omet explicitement les middlewares globaux Laravel (dont ValidatePostSize, TrimStrings, CORS). | Taille POST, normalisation et comportement CORS dépendent alors de PHP, du serveur web et du code applicatif. Risque de divergences et de requêtes volumineuses coûteuses. | Réintroduire explicitement les middlewares nécessaires ou démontrer leur remplacement ; fixer limites upload/POST au proxy et PHP ; tester les payloads surdimensionnés. |

| SEC-13 • P2 | CSP personnalisée dans SecurityHeadersMiddleware ; pas de base-uri, connect-src ni upgrade-insecure-requests visibles dans la politique actuelle. | La CSP apporte déjà une défense importante (nonce, pas d’unsafe-inline), mais sa couverture peut être renforcée. Ce n’est pas une preuve de XSS. | Ajouter base-uri 'self', object-src 'none' déjà présent, frame-ancestors ; définir connect-src selon les besoins ; envisager report-to/report-uri et tests CSP. |

| SEC-14 • P2 | $guarded = [] est utilisé dans plusieurs modèles Eloquent inspectés. | Mass assignment dangereux si un endpoint transmet un tableau utilisateur non filtré à fill/create/put. Les contrôleurs vus construisent souvent des tableaux, mais tous les appelants ne sont pas audités. | Passer à $fillable strict ou DTO ; rechercher les appels et tester les colonnes privilégiées. |

| SEC-15 • P3 | AccessLog enregistre les chemins et les durées ; ErrorResponder::log() écrit des traces d’exception dans storage/logs/error.log et via Log. | Les erreurs peuvent inclure des valeurs sensibles selon les exceptions et les bibliothèques ; les journaux contiennent potentiellement des données personnelles et des tokens d’URL. | Masquer les secrets, définir rétention et accès, protéger storage, éviter les traces en production côté utilisateur, auditer les contextes de logs. |


# 4. Contrôles positifs déjà présents

Authentification web basée sur le guard session Laravel et AuthenticateSession ; AuthService recharge l’utilisateur et vérifie qu’il reste actif.

PasswordResetService génère un token aléatoire de 32 octets, stocke son hash, impose une durée d’une heure et applique un message anti-énumération.

PublicUrlResolver refuse les URL avec identifiants, query ou fragment et évite l’usage de Host pour les liens de reset une fois la valeur configurée.

StorageFileController utilise realpath(), confine les chemins à storage/uploads, limite les extensions et sert SVG en pièce jointe plutôt qu’en inline.

SecurityHeadersMiddleware émet CSP avec nonce, X-Content-Type-Options, X-Frame-Options et Referrer-Policy ; HSTS est ajouté lorsque la requête est considérée HTTPS.

ApiRateLimitMiddleware limite l’API par token hashé et par IP ; LoginThrottleMiddleware limite les échecs.

OwnerGuard protège le dernier Owner actif et empêche certaines auto-révocations.

CredentialRevoker invalide remember_token et tokens API lors de changements de mot de passe/désactivation sur les parcours consultés.

UnsubscribeTokens utilise HMAC-SHA256 et le contrôleur ne permet que de désactiver un canal/type précis ; le GET ne désabonne pas.

GithubUpdateService documente et implémente une vérification SHA-256 et de manifeste de release ; sa propre documentation reconnaît que cela ne protège pas d’un compte GitHub compromis.

Le workflow CI prévoit PHPUnit, PHPStan, composer validate/audit, lint PHP/JS, smoke tests d’installation/import, tests SQLite/MySQL et crawl de formulaires.

# 5. Plan de migration et de remédiation

## Phase 0 — Sécurisation avant toute nouvelle exposition

Corriger SEC-01 : CSRF et verrou atomique de l’installateur ; tester un POST cross-site.

Corriger SEC-02 : changement de mot de passe obligatoire avant accès web normal ; supprimer le mot de passe universel 0000 des nouveaux comptes et resets.

Corriger SEC-03 : redacter le token iCal dans AccessLog et vérifier les logs du serveur/proxy.

Corriger SEC-06 : demander APP_URL explicitement et ne plus faire confiance à HTTP_HOST pendant l’installation.

Définir SESSION_SECURE=true en production et documenter la terminaison TLS/proxy.

Mettre en place une politique de bundles : commit pin obligatoire, provenance visible et confirmation explicite des bundles tiers.

Vérifier l’extraction ZIP avec tests de chemins malveillants et quotas de décompression.

## Phase 1 — Inventaire exact de l’API DAO

Faire une recherche AST/grep sur les méthodes statiques findBy*, findAll, put, remove, get*/save* dans app/Models et bundles/.

Pour chaque méthode, lister les appelants et le type de retour attendu (array, ?array, Collection, Model).

Classer les méthodes en : CRUD générique à retirer ; requête métier à convertir en scope/query object ; orchestration métier à déplacer dans un service ; API de compatibilité publique des bundles à versionner.

Ajouter des tests de contrat avant modification pour éviter les régressions.

## Phase 2 — Convergence Eloquent par domaine

Commencer par User, StoreUser, Role, RoleAssignment et ApiToken (identité, RBAC et sécurité).

Continuer par Store, Shift, ShiftType, Availability et TimesheetApproval.

Terminer par Notification, préférences, calendriers, tokens, journaux et modèles de configuration.

Créer les relations Eloquent (belongsTo/hasMany/belongsToMany) et casts de dates/JSON/booleans ; ajouter scopes nommés.

Déplacer les règles métier et effets secondaires vers services/actions dédiés ou événements Laravel ; ne pas faire porter toutes les règles au modèle.

Remplacer progressivement les appels array['field'] par accès modèle ; maintenir un adaptateur transitoire versionné si les bundles externes dépendent encore des tableaux.

Remplacer $guarded = [] par des listes $fillable ou des méthodes d’écriture explicites.

## Phase 3 — Retrait des compatibilités

Retirer LaravelCapsule seulement lorsque tous les consommateurs internes et bundles utilisent DB/Schema/Migrator Laravel.

Retirer l’autoloader legacy uniquement lorsque les bundles monorepo et installés utilisent une convention unique et documentée.

Évaluer les routes chargées par require et le route cache sur une installation avec plusieurs bundles ; ne pas les remplacer si cela casse l’installation dynamique.

Comparer KintaiHttp/ErrorResponder au cycle de vie Laravel et retirer les handlers redondants.

Consolider HttpFetcher autour du client HTTP Laravel/Guzzle après ajout des contrôles SSRF, taille et redirections.

Consolider PayloadValidator avec Validator/FormRequest sans perdre les règles métiers.

Réduire les macros aux extensions Kintai utiles et documenter leurs contrats.

## Phase 4 — Validation de fin de migration

Exécuter composer validate, composer audit, PHPStan et toute la suite PHPUnit sur le commit final.

Exécuter les smoke tests fresh install, import, provision, SQLite, MySQL, bundles, crawl et soumission de formulaires.

Ajouter un test d’installation concurrente et un test CSRF sur public/install.php.

Tester chaque rôle RBAC sur chaque route web et API : Owner, manager global, manager multi-store, manager mono-store, employé, utilisateur désactivé.

Tester IDOR/BOLA sur tous les endpoints API et routes qui prennent user_id/store_id/employee dans route, query ou body.

Tester la révocation des sessions, remember tokens, API tokens, reset tokens et cron tokens.

Tester les uploads (MIME réel, extension, taille, polyglottes, SVG, ZIP), les chemins traversal et les liens symboliques.

Tester les exports et fichiers servis : autorisation par store, absence de cache public, en-têtes nosniff et redaction des noms.

Tester la restauration d’une sauvegarde corrompue et d’une archive malveillante dans un environnement jetable.

Effectuer un DAST/pentest avant de déclarer la migration terminée.

# 6. Critères d’acceptation : quand considérer la migration terminée

| Critère | Definition of Done |

| --- | --- |

| Eloquent | Les cas d’usage applicatifs utilisent des modèles Eloquent/Query Builder ou des repositories explicitement justifiés ; les wrappers arrays ne restent que pour une compatibilité documentée et datée. |

| Mass assignment | Aucun modèle exposé aux données utilisateur n’utilise un $guarded = [] sans justification et tests de provenance des données. |

| Services Laravel | DB, Cache, HTTP, Storage, Mail, Queue, Validation, Auth et Console sont utilisés directement quand ils couvrent le besoin ; aucun doublon legacy sans justification. |

| Bundles | Contrat d’extension versionné, installation avec provenance vérifiable, commit pin/signature obligatoire, extraction sécurisée, permissions et rollback testés. |

| Sécurité | SEC-01 à SEC-10 corrigés ou acceptés formellement avec justification, propriétaire et date de réexamen. |

| Tests | Tous les jobs CI verts sur le commit exact ; couverture des scénarios critiques et tests de sécurité ajoutés. |

| Déploiement | APP_ENV=production, APP_DEBUG=false, APP_URL explicite, SESSION_SECURE=true, HTTPS, permissions filesystem minimales, backups testés, logs avec rétention. |

| Documentation | Guide de mise à niveau, compatibilité bundles, variables d’environnement, procédures de restauration et de rotation des secrets à jour. |


# 7. Ordre de travail conseillé

| Ordre | Lot | Résultat attendu |

| --- | --- | --- |

| 1 | Installateur + URL publique | Pas de CSRF install, pas de Host poisoning, import local limité. |

| 2 | Mots de passe + sessions | Aucun compte faible utilisable sans changement ; cookies durcis. |

| 3 | iCal + logs + cron | Aucun secret porteur dans les journaux ou les query strings. |

| 4 | Bundles + ZIP + HTTP sortant | Chaîne d’approvisionnement et extraction durcies. |

| 5 | Mass assignment + RBAC/API | Surfaces d’accès et mutations validées par tests. |

| 6 | API DAO des modèles | Migration de User/Store/Shift et modèles transverses. |

| 7 | Compatibilités Capsule/bootstrap | Suppression des adaptateurs devenus inutiles. |

| 8 | CI, tests d’intégration et pentest | Validation mesurable de la migration. |


# 8. Sources examinées

Fichiers particulièrement pertinents :

composer.json ; bootstrap/app.php ; bootstrap/providers.php ; public/index.php ; public/install.php ; resources/views/install/index.php.

config/auth.php ; config/session.php ; config/database.php ; config/filesystems.php ; config/logging.php ; config/license_server.php.

routes/web.php ; routes/api.php ; app/Auth/AuthService.php ; app/Auth/OwnerGuard.php ; app/Auth/PasswordPolicy.php ; app/Auth/CredentialRevoker.php.

app/Http/Middleware/LoginThrottleMiddleware.php ; ApiRateLimitMiddleware.php ; SecurityHeadersMiddleware.php ; PasswordReminderMiddleware.php ; AccessLog.php ; PermissionMiddleware.php ; ApiPermissionMiddleware.php.

app/Http/Controllers/StorageFileController.php ; CronController.php ; PasswordResetController.php ; NotificationUnsubscribeController.php ; System/BackupController.php ; System/BundleMarketController.php.

app/Services/PasswordResetService.php ; PublicUrlResolver.php ; SecretBox.php ; UnsubscribeTokens.php ; HttpFetcher.php ; BackupService.php ; GithubUpdateService.php ; BundleInstaller/BundleInstallerService.php.

app/Models/User.php ; Shift.php ; Store.php ; ApiToken.php ; Availability.php ; Role.php ; RoleAssignment.php ; StoreUser.php ; ShiftType.php ; Notification.php.

.github/workflows/tests.yml ; phpunit.xml ; database/migrations/2026_11_01_000001_create_core_tables.php.

Liens directs : https://github.com/AudricSan/kintai-laravel/tree/main ; révision examinée : https://github.com/AudricSan/kintai-laravel/commit/c35fa0c129e2f45dc9a0832990f194115e495e6f

# 9. Conclusion

Kintai est déjà une application Laravel au niveau du socle. La migration restante est principalement une migration de contrats internes : les modèles Eloquent sont encore utilisés comme façades d’un ancien accès aux données. Il ne faut pas supprimer les fonctionnalités propres à Kintai (RBAC, bundles, audit, calendrier, exports) au seul motif qu’elles sont personnalisées.

Les priorités immédiates sont la sécurisation de l’installateur, l’obligation de renouveler les mots de passe faibles, la protection des jetons iCal dans les logs, et la confiance accordée aux bundles et aux archives. Ensuite, la migration des modèles doit se faire par domaine, avec une cartographie des consommateurs et des tests de compatibilité.

La migration ne devra être déclarée terminée qu’après correction des constats prioritaires, passage de tous les contrôles CI sur le commit final, tests d’accès multi-rôles et validation dynamique. Ce rapport ne certifie pas l’absence d’autres problèmes dans les fichiers qui n’ont pas fait l’objet d’une lecture détaillée.
