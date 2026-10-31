<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les tests démarrent la vraie application (configuration chargée), sans lire le .env de l'instance et avec une
// session en mémoire.
putenv('SESSION_DRIVER=array');
// Étanchéité : la connexion par défaut est une base en mémoire dès le démarrage, jamais storage/app/database.sqlite.
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = ':memory:';
$_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
putenv('APP_KEY=' . $_ENV['APP_KEY']);
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';

$app = $GLOBALS["kintai_test_app"] = require dirname(__DIR__) . "/bootstrap/standalone.php";
$app->loadEnvironmentFrom('.env.testing');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Les bundles du dépôt (bundles/<slug>) : leurs migrations suivent celles du Cœur et leurs vues ont leur espace de noms,
// sans dépendre de l'ordre des tests ni d'un bundle activé dans la base de l'instance.
foreach (glob(dirname(__DIR__) . '/bundles/*', GLOB_ONLYDIR) ?: [] as $bundleDirectory) {
    if (is_dir($bundleDirectory . '/database/migrations')) {
        $app->make('migrator')->path($bundleDirectory . '/database/migrations');
    }
    if (is_dir($bundleDirectory . '/Views')) {
        $app->make('view')->addNamespace(basename($bundleDirectory), $bundleDirectory . '/Views');
    }
}

// Aides des tests : routeur de Laravel vierge, que ResetAuthSessionFingerprint remplace par celui de l'application
// avant chaque test.
$GLOBALS['kintai_test_router'] = $app->make('router');

function kintai_test_router(): Illuminate\Routing\Router
{
    $app    = $GLOBALS['kintai_test_app'];
    $router = new Illuminate\Routing\Router($app->make('events'), $app);
    $app->instance('router', $router);
    Illuminate\Support\Facades\Route::clearResolvedInstance('router');

    return $router;
}

/** Enregistreur de routes à la manière des bundles ($router->get('/chemin', [Classe::class, 'm'], name: '…')). */
function kintai_test_registrar(): Tests\Support\RouteRegistrar
{
    return new Tests\Support\RouteRegistrar(kintai_test_router());
}

/** Les vraies routes du cœur (routes/web.php et routes/api.php) dans un routeur vierge. */
function kintai_test_load_routes(): Illuminate\Routing\Router
{
    $router = kintai_test_router();
    require dirname(__DIR__) . '/routes/web.php';
    require dirname(__DIR__) . '/routes/api.php';

    return $router;
}

// Aide des tests : en-têtes d'une réponse sous forme de tableau « Nom => valeur ».
function kintai_test_headers(object $response): array
{
    $headers = [];
    foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
        $headers[$name] = implode(', ', $values);
    }

    return $headers;
}

// Aide des tests : donne à une requête construite à la main les paramètres qu'une route Laravel lui aurait donnés.
Illuminate\Http\Request::macro('setRouteParams', function (array $params): void {
    /** @var Illuminate\Http\Request $this */
    $route = new Illuminate\Routing\Route(['GET'], '/', []);
    $route->bind($this);
    foreach ($params as $name => $value) {
        $route->setParameter($name, $value);
    }
    $this->setRouteResolver(static fn () => $route);
});

// Aide des tests : base neuve dont la table bundle_registries contient ces registries (name, url, is_official).
function kintai_test_registries(array $rows): void
{
    kintai_test_database();
    kintai_test_migrate();
    App\Models\BundleRegistry::query()->delete();
    foreach ($rows as $row) {
        App\Models\BundleRegistry::create(['name' => $row['name'], 'url' => $row['url'], 'is_official' => $row['is_official'] ?? false]);
    }
}

// Aide des tests : base neuve pour le routage lisible ; les employés (id => numéro d'employé, ou null) sont créés.
function kintai_test_route_slugs(array $employeeCodes = []): void
{
    kintai_test_database();
    kintai_test_migrate();
    foreach ($employeeCodes as $id => $code) {
        kintai_test_user((int) $id);
        App\Models\User::where('id', $id)->update(['employee_code' => $code]);
    }
}
// Aide des tests : base neuve où ces employés ont ces disponibilités hebdomadaires dans le magasin 1.
function kintai_test_availabilities(array $userIds, array $rows): void
{
    kintai_test_database();
    kintai_test_migrate();
    Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => 1, 'name' => 'Magasin', 'code' => 'M1']);
    foreach ($userIds as $userId) {
        kintai_test_user((int) $userId);
        foreach ($rows as $row) {
            App\Models\Availability::create($row + ['user_id' => $userId, 'store_id' => 1, 'start_time' => '00:00', 'end_time' => '23:59']);
        }
    }
}
// Aide des tests : exécute une commande Artisan (ses services viennent du conteneur de test) ; renvoie [code de sortie, sortie].
function kintai_test_command(Illuminate\Console\Command $command, array $input = []): array
{
    $command->setLaravel($GLOBALS['kintai_test_app']);
    $output = new Symfony\Component\Console\Output\BufferedOutput();
    $code   = $command->run(new Symfony\Component\Console\Input\ArrayInput($input), $output);

    return [$code, trim($output->fetch())];
}

// Aide des tests : remplace le journal de Laravel par un RecordingLog qui retient les entrées (Log::info, AuditLogger…).
// Une base neuve (kintai_test_database) remet le vrai journal.
function kintai_test_log(): Tests\Support\RecordingLog
{
    $log = new Tests\Support\RecordingLog();
    $GLOBALS['kintai_test_app']->instance('log', $log);
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');

    return $log;
}

// Aide des tests : base SQLite en mémoire neuve, installée comme connexion par défaut de l'application (DB::, Schema::,
// Eloquent, migrateur). Renvoie la Capsule posée sur cette connexion.
function kintai_test_database(): App\Database\LaravelCapsule
{
    $app = $GLOBALS['kintai_test_app'];
    // Les messages flashés d'un test précédent ne doivent pas servir au suivant.
    $app['session.store']->forget(['success', 'error', 'warning', 'activation']);
    $app->forgetInstance('log');
    Illuminate\Support\Facades\Log::clearResolvedInstance('log');
    config(['database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $app['db']->purge('testing');
    $app['db']->setDefaultConnection('testing');
    config(['database.default' => 'testing']);

    // Les modèles Eloquent reviennent sur le gestionnaire de l'application (un test peut avoir posé une autre Capsule).
    Illuminate\Database\Eloquent\Model::setConnectionResolver($app['db']);

    $capsule = new App\Database\LaravelCapsule($app);
    $capsule->setAsGlobal();

    return $capsule;
}

// Aide des tests : `php artisan migrate` puis `php artisan db:seed` sur la base de kintai_test_database().
function kintai_test_migrate(): void
{
    // Certains tests vident $_SERVER ; la console de Symfony lit PHP_SELF.
    $_SERVER['PHP_SELF'] ??= 'artisan';
    $kernel = $GLOBALS['kintai_test_app']->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('migrate', ['--force' => true]);
    $kernel->call('db:seed', ['--force' => true]);
}

// Aide des tests : base en mémoire neuve dont app_settings ne contient que les réglages donnés (clé => valeur).
function kintai_test_settings(array $values = []): void
{
    // Base de test déjà prête (le test y a inséré ses lignes) : on la garde ; sinon neuve et migrée, jamais celle de l'instance.
    if (config('database.default') !== 'testing' || !Illuminate\Support\Facades\Schema::hasTable('app_settings')) {
        kintai_test_database();
        kintai_test_migrate();
    }
    Illuminate\Database\Eloquent\Model::setConnectionResolver($GLOBALS['kintai_test_app']['db']);
    App\Models\AppSetting::query()->delete();
    App\Models\AppSetting::putMany($values);
}
// Aide des tests : base de test prête (neuve et migrée si le test n'en a pas encore préparé une).
function kintai_test_ready(string $table): void
{
    if (config('database.default') !== 'testing' || !Illuminate\Support\Facades\Schema::hasTable($table)) {
        kintai_test_database();
        kintai_test_migrate();
    }
    Illuminate\Database\Eloquent\Model::setConnectionResolver($GLOBALS['kintai_test_app']['db']);
}

// Aide des tests : types de shift réels (lignes shift_types) ; `store_ids` (défaut 1 à 5) lie le type aux magasins, créés au besoin.
function kintai_test_shift_types(array $rows): void
{
    kintai_test_ready('shift_types');
    foreach ($rows as $row) {
        $storeIds = $row['store_ids'] ?? [1, 2, 3, 4, 5];
        unset($row['store_ids']);
        $id = $row['id'] ?? null;
        $row += ['name' => 'Type', 'code' => 'T' . ($id ?? uniqid()), 'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => 1];
        $id = $id ?? (int) Illuminate\Support\Facades\DB::table('shift_types')->max('id') + 1;
        $row['id'] = $id;
        Illuminate\Support\Facades\DB::table('shift_types')->updateOrInsert(['id' => $id], $row);
        kintai_test_shift_type_stores($id, $storeIds);
    }
}

// Aide des tests : magasins liés à un type de shift (tous les types existants si l'identifiant est null).
function kintai_test_shift_type_stores(?int $shiftTypeId, array $storeIds): void
{
    kintai_test_ready('shift_types');
    foreach ($storeIds as $storeId) {
        Illuminate\Support\Facades\DB::table('stores')->insertOrIgnore(['id' => $storeId, 'name' => 'Magasin ' . $storeId, 'code' => 'M' . $storeId]);
    }
    $ids = $shiftTypeId === null ? Illuminate\Support\Facades\DB::table('shift_types')->pluck('id')->all() : [$shiftTypeId];
    foreach ($ids as $id) {
        Illuminate\Support\Facades\DB::table('shift_type_stores')->where('shift_type_id', $id)->delete();
        foreach ($storeIds as $storeId) {
            Illuminate\Support\Facades\DB::table('shift_type_stores')->insert(['shift_type_id' => $id, 'store_id' => $storeId]);
        }
    }
}
// Aide des tests : shifts réels (lignes shifts). Le magasin, l'employé et le type de shift référencés sont créés au besoin ;
// les colonnes obligatoires prennent des valeurs par défaut.
function kintai_test_shifts(array $rows): void
{
    kintai_test_ready('shifts');
    $db = Illuminate\Support\Facades\DB::class;
    foreach ($rows as $row) {
        $row += ['store_id' => 1, 'user_id' => null, 'shift_date' => '2026-01-01', 'start_time' => '09:00', 'end_time' => '17:00'];
        $db::table('stores')->insertOrIgnore(['id' => $row['store_id'], 'name' => 'Magasin ' . $row['store_id'], 'code' => 'M' . $row['store_id']]);
        if ($row['user_id'] !== null) {
            kintai_test_user((int) $row['user_id']);
        }
        if (!empty($row['shift_type_id'])) {
            $db::table('shift_types')->insertOrIgnore(['id' => $row['shift_type_id'], 'name' => 'Type ' . $row['shift_type_id'], 'code' => 'T' . $row['shift_type_id'], 'start_time' => '08:00', 'end_time' => '16:00']);
        }
        $row += [
            'starts_at' => $row['shift_date'] . ' ' . $row['start_time'],
            'ends_at'   => $row['shift_date'] . ' ' . $row['end_time'],
        ];
        if (isset($row['id'])) {
            $db::table('shifts')->updateOrInsert(['id' => $row['id']], $row);
        } else {
            $db::table('shifts')->insert($row);
        }
    }
}
// Aide des tests : rôles réels (lignes roles + role_permissions) pour les identifiants 1 à $upTo (60 par défaut). Chaque réglage est une
// valeur ou un callable (identifiant du rôle -> valeur), comme les anciens doubles du dépôt de rôles :
// $findById renvoie la ligne du rôle (null = rôle inconnu), $getPermissions ses clés, $getGlobalPermissionKeys celles de portée globale.
function kintai_test_roles(mixed $findById = null, mixed $getPermissions = [], mixed $getGlobalPermissionKeys = [], int $upTo = 60): void
{
    kintai_test_ready('roles');
    App\Database\RequestMemo::flush();
    $resolve = static fn(mixed $spec, int $id): mixed => is_callable($spec) ? $spec($id) : $spec;
    $db = Illuminate\Support\Facades\DB::class;
    for ($id = 1; $id <= $upTo; $id++) {
        $exists = $db::table('roles')->where('id', $id)->exists();
        $row = $findById === null ? ($exists ? [] : null) : $resolve($findById, $id);   // sans $findById : seulement les rôles déjà présents
        if (!is_array($row)) {
            continue;
        }
        $row = array_intersect_key($row, array_flip(['name', 'slug', 'color', 'description', 'is_system', 'is_manager']));
        if ($db::table('roles')->where('id', $id)->exists()) {
            unset($row['slug'], $row['name']);   // le nom et le slug du rôle semé sont conservés
            $row === [] || $db::table('roles')->where('id', $id)->update($row);
        } else {
            if (isset($row['slug']) && $db::table('roles')->where('slug', $row['slug'])->exists()) {
                unset($row['slug']); // déjà pris par un rôle semé (owner, manager…)
            }
            $db::table('roles')->insert($row + ['id' => $id, 'name' => 'Role ' . $id, 'slug' => 'role-' . $id, 'is_system' => 0]);
        }
        $db::table('role_permissions')->where('role_id', $id)->delete();
        $global = (array) $resolve($getGlobalPermissionKeys, $id);
        foreach (array_unique(array_merge((array) $resolve($getPermissions, $id), $global)) as $key) {
            $db::table('role_permissions')->insert(['role_id' => $id, 'permission_key' => $key, 'scope' => in_array($key, $global, true) ? 'global' : 'local']);
        }
    }
    App\Database\RequestMemo::flush();
}
// Aide des tests : remplace le contenu de la table roles par ces lignes (id, name, slug, is_system, is_manager…).
function kintai_test_role_table(array $rows): void
{
    kintai_test_ready('roles');
    $db = Illuminate\Support\Facades\DB::class;
    $db::table('role_assignments')->delete();
    $db::table('role_permissions')->delete();
    $db::table('roles')->delete();
    foreach ($rows as $row) {
        $row = array_intersect_key($row, array_flip(['id', 'name', 'slug', 'color', 'description', 'is_system', 'is_manager']));
        $id = $row['id'] ?? null;
        $db::table('roles')->insert($row + ['name' => 'Role ' . $id, 'slug' => 'role-' . $id, 'is_system' => 0]);
    }
    App\Database\RequestMemo::flush();
}
// Aide des tests : affectations de rôle réelles (lignes role_assignments), alimentées comme les anciens doubles du dépôt :
// chaque réglage est une valeur ou un callable (findByUser/findByRole/findById : identifiant ; findByScope : type, identifiant)
// appelé pour les identifiants 1 à $upTo. Les clés étrangères sont suspendues le temps de l'insertion (utilisateurs, rôles
// et magasins fictifs).
function kintai_test_assignments(mixed $findByUser = null, mixed $findByScope = null, mixed $findByRole = null, mixed $findById = null, int $upTo = 60): void
{
    kintai_test_ready('role_assignments');
    App\Database\RequestMemo::flush();
    $db = Illuminate\Support\Facades\DB::class;
    $rows = [];
    $collect = static function (mixed $list, array $implied = []) use (&$rows): void {
        foreach (is_array($list) ? $list : [] as $row) {
            $row = is_array($row) ? $row + $implied : $row;   // une ligne sans user_id vise l'utilisateur interrogé, etc.
            if (is_array($row) && isset($row['user_id'], $row['role_id'])) {
                $row += ['scope_type' => 'global', 'scope_id' => null];
                $signature = $row['user_id'] . '|' . $row['role_id'] . '|' . $row['scope_type'] . '|' . $row['scope_id'];
                if (isset($row['id']) && in_array($row['id'], array_column(array_diff_key($rows, [$signature => 1]), 'id'), true)) {
                    unset($row['id']);   // même identifiant fourni pour plusieurs lignes : le suivant est attribué par la base
                }
                $rows[$signature] = $row;
            }
        }
    };
    $call = static fn(mixed $spec, mixed ...$args): mixed => is_callable($spec) ? $spec(...$args) : $spec;
    for ($id = 1; $id <= $upTo; $id++) {
        if ($findByUser !== null) { $collect($call($findByUser, $id), ['user_id' => $id]); }
        if ($findByRole !== null) { $collect($call($findByRole, $id), ['role_id' => $id]); }
        if ($findByScope !== null) { $collect($call($findByScope, 'store', $id), ['scope_type' => 'store', 'scope_id' => $id]); }
        if ($findById !== null) { $collect([$call($findById, $id)]); }
    }
    if ($findByScope !== null) { $collect($call($findByScope, 'global', null), ['scope_type' => 'global', 'scope_id' => null]); }

    $foreignKeys = (int) ($db::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 1);
    $db::statement('PRAGMA foreign_keys = OFF');
    foreach ($rows as $row) {
        $row = array_intersect_key($row, array_flip(['id', 'user_id', 'role_id', 'scope_type', 'scope_id']));
        isset($row['id'])
            ? $db::table('role_assignments')->updateOrInsert(['id' => $row['id']], $row)
            : $db::table('role_assignments')->insert($row);
    }
    $db::statement('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));   // l'état du test est conservé
    App\Database\RequestMemo::flush();
}
// Aide des tests : suspend les clés étrangères (identifiants fictifs d'utilisateurs, de rôles ou de magasins).
function kintai_test_fk_off(): void
{
    Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');
}

// Aide des tests : appartenances magasin/employé réelles (lignes store_user), alimentées comme les anciens doubles du dépôt :
// chaque réglage est une valeur ou un callable (findByStore/findByUser/findById : identifiant ; findMembership : magasin, employé)
// appelé pour les identifiants 1 à $upTo. Les clés étrangères sont suspendues le temps de l'insertion.
function kintai_test_store_users(mixed $findByStore = null, mixed $findByUser = null, mixed $findMembership = null, mixed $findById = null, int $upTo = 60): void
{
    kintai_test_ready('store_user');
    $db = Illuminate\Support\Facades\DB::class;
    $db::table('store_user')->delete();   // l'état décrit remplace celui des tests précédents
    $rows = [];
    $collect = static function (mixed $list, array $implied = []) use (&$rows): void {
        foreach (is_array($list) ? $list : [] as $row) {
            $row = is_array($row) ? $row + $implied : $row;
            if (is_array($row) && isset($row['store_id'], $row['user_id'])) {
                $signature = $row['store_id'] . '|' . $row['user_id'];
                if (isset($row['id']) && in_array($row['id'], array_column(array_diff_key($rows, [$signature => 1]), 'id'), true)) {
                    unset($row['id']);
                }
                $rows[$signature] = $row;
            }
        }
    };
    $call = static fn(mixed $spec, mixed ...$args): mixed => is_callable($spec) ? $spec(...$args) : $spec;
    for ($id = 1; $id <= $upTo; $id++) {
        if ($findByStore !== null) { $collect($call($findByStore, $id), ['store_id' => $id]); }
        if ($findByUser !== null) { $collect($call($findByUser, $id), ['user_id' => $id]); }
        if ($findById !== null) { $collect([$call($findById, $id)]); }
        if ($findMembership !== null && $id <= 30) {
            for ($userId = 1; $userId <= $upTo; $userId++) {
                $collect([$call($findMembership, $id, $userId)], ['store_id' => $id, 'user_id' => $userId]);
            }
        }
    }

    $foreignKeys = (int) ($db::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 1);
    $db::statement('PRAGMA foreign_keys = OFF');
    $columns = array_flip(['id', 'store_id', 'user_id', 'staff_code', 'hire_date', 'contract_type', 'is_active', 'subject_to_deductions', 'hired_by']);
    foreach ($rows as $row) {
        $row = array_intersect_key($row, $columns);
        if (isset($row['id'])) {   // une même paire magasin/employé ne peut exister sous deux identifiants
            $db::table('store_user')->where('store_id', $row['store_id'])->where('user_id', $row['user_id'])->where('id', '!=', $row['id'])->delete();
        }
        isset($row['id'])
            ? $db::table('store_user')->updateOrInsert(['id' => $row['id']], $row)
            : $db::table('store_user')->updateOrInsert(['store_id' => $row['store_id'], 'user_id' => $row['user_id']], $row);
    }
    $db::statement('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
}
// Aide des tests : magasins réels (lignes stores), alimentés comme les anciens doubles du dépôt : chaque réglage est une valeur ou
// un callable (findById : identifiant ; findAll/findActive/findAllWithDeleted : liste ; countActive : nombre de magasins actifs).
// Le nom, le code et le reste prennent des valeurs par défaut ; un magasin déjà présent est mis à jour.
function kintai_test_stores(mixed $findById = null, mixed $findAll = null, mixed $findActive = null, mixed $findAllWithDeleted = null, mixed $countActive = null, mixed $findByCode = null, int $upTo = 60): void
{
    kintai_test_ready('stores');
    $db = Illuminate\Support\Facades\DB::class;
    $rows = [];
    $collect = static function (mixed $list, array $implied = []) use (&$rows): void {
        foreach (is_array($list) ? $list : [] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $rows[$row['id']] = $row + $implied;
            }
        }
    };
    $call = static fn(mixed $spec, mixed ...$args): mixed => is_callable($spec) ? $spec(...$args) : $spec;
    for ($id = 1; $id <= $upTo; $id++) {
        if ($findById !== null) { $collect([$call($findById, $id)]); }
    }
    foreach ([$findAll, $findActive, $findAllWithDeleted] as $i => $spec) {
        if ($spec !== null) { $collect($call($spec), $i === 1 ? ['is_active' => 1] : []); }
    }
    if ($findByCode !== null) { $collect([$call($findByCode, 'ST')]); }
    if ($countActive !== null) {
        for ($id = 1; $id <= (int) $call($countActive); $id++) { $rows[$id] ??= ['id' => $id, 'is_active' => 1]; }
    }

    $columns = array_flip(Illuminate\Support\Facades\Schema::getColumnListing('stores'));
    $foreignKeys = (int) ($db::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 1);
    $db::statement('PRAGMA foreign_keys = OFF');
    if ($countActive !== null) {
        $db::table('stores')->whereNotIn('id', array_keys($rows) ?: [0])->delete(); // le décompte demandé est exact
    }
    foreach ($rows as $id => $row) {
        $row = array_intersect_key($row, $columns) + ['name' => 'Magasin ' . $id, 'code' => 'M' . $id];
        $db::table('stores')->updateOrInsert(['id' => $id], $row);
    }
    $db::statement('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
}
// Aide des tests : comptes tels que User::findById() & co. les restituent. Chaque spécification est une valeur ou une
// fonction ; les lignes obtenues (id 1 à $upTo pour findById) sont écrites dans la vraie table users. Une ligne sans id
// en reçoit un à partir de 900. countActive : le décompte demandé est exact (les autres comptes sont retirés).
function kintai_test_users(mixed $findById = null, mixed $findByEmail = null, mixed $findByEmployeeCode = null, mixed $findAll = null, mixed $countActive = null, int $upTo = 120): void
{
    kintai_test_ready('users');
    $db = Illuminate\Support\Facades\DB::class;
    $rows = [];
    $absent = [];
    $next = 900;
    $call = static fn(mixed $spec, mixed ...$args): mixed => is_callable($spec) ? $spec(...$args) : $spec;
    $collect = static function (mixed $list, ?int $forId = null) use (&$rows, &$next): void {
        foreach (is_array($list) ? $list : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['id'] ??= $forId ?? $next++;
            $rows[$row['id']] = $row;
        }
    };
    if ($findById !== null) {
        $existing = $db::table('users')->pluck('id')->map(fn($v) => (int) $v)->all();
        for ($id = 1; $id <= $upTo; $id++) {
            $row = $call($findById, $id);
            // Ligne sans id (fonction qui ignore son argument) : elle vaut pour les comptes déjà présents, sinon pour tous.
            if (is_array($row) && !isset($row['id']) && $existing !== [] && !in_array($id, $existing, true)) {
                continue;
            }
            if (is_array($row)) {
                $collect([$row], $id);
            } else {
                $absent[] = $id; // la fonction répond « inconnu » : le compte n'existe pas
            }
        }
    }
    if ($findByEmail !== null) { $collect([$call($findByEmail, 'probe@example.com')]); }
    if ($findByEmployeeCode !== null) { $collect([$call($findByEmployeeCode, '000')]); }
    if ($findAll !== null) { $collect($call($findAll)); }
    if ($countActive !== null) {
        for ($id = 1; $id <= (int) $call($countActive); $id++) { $rows[$id] ??= ['id' => $id, 'is_active' => 1]; }
    }

    $columns = array_flip(Illuminate\Support\Facades\Schema::getColumnListing('users'));
    $foreignKeys = (int) ($db::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 1);
    $db::statement('PRAGMA foreign_keys = OFF');
    if ($countActive !== null || $findAll !== null) { // la liste ou le décompte demandé est exact
        $db::table('users')->whereNotIn('id', array_keys($rows) ?: [0])->delete();
    }
    if ($absent !== []) {
        $db::table('users')->whereIn('id', array_values(array_diff($absent, array_keys($rows))))->delete();
    }
    $now = date('Y-m-d H:i:s');
    $emails = [];
    foreach ($rows as $id => $row) {
        // Une fonction qui renvoie le même courriel pour tout identifiant : l'unicité de users.email l'interdit.
        if (isset($row['email'])) {
            $row['email'] = isset($emails[$row['email']]) ? $id . '.' . $row['email'] : $row['email'];
            $emails[$row['email']] = true;
        }
        $row += [
            'first_name' => '', 'last_name' => '', 'display_name' => '',
            'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ];
        if (!array_key_exists('email', $row)) {
            $row['email'] = 'user' . $id . '@test.local';
        }
        $db::table('users')->updateOrInsert(['id' => $id], array_intersect_key($row, $columns));
    }
    $db::statement('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
    App\Database\RequestMemo::flush(); // écriture directe : la mémoire de requête n'a rien vu
}
// Aide des tests : fonctionnalités d'un magasin (décisions store_features) telles que Store::getFeatures() les restitue.
// Liste vide : aucune restriction (aucune décision). Sinon, seules les clés listées restent actives parmi celles proposées.
// $storeId null : magasins 1 à 9.
function kintai_test_store_features(?int $storeId, array $features): void
{
    kintai_test_ready('store_features');
    $db = Illuminate\Support\Facades\DB::class;
    $foreignKeys = (int) ($db::selectOne('PRAGMA foreign_keys')->foreign_keys ?? 1);
    $db::statement('PRAGMA foreign_keys = OFF');
    foreach ($storeId === null ? range(1, 9) : [$storeId] as $id) {
        $db::table('store_features')->where('store_id', $id)->delete();
        if ($features === []) {
            continue;
        }
        // Clés connues des bundles, en plus de celles du registre (qui ne liste que les bundles chargés par le test).
        $known = ['shifts', 'timeclock', 'timeoff', 'photos', 'messages', 'swaps', 'open_shifts'];
        $keys = array_values(array_unique(array_merge($features, $known, App\Extension\StoreFeatures::current()->availableKeys())));
        foreach ($keys as $key) {
            $db::table('store_features')->insert(['store_id' => $id, 'feature_key' => $key, 'enabled' => in_array($key, $features, true) ? 1 : 0]);
        }
    }
    $db::statement('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
}
// Aide des tests : traducteur de Laravel adossé à des textes donnés (langue => clé => texte), installé comme
// « translator » de l'application (__() et trans() le lisent). Remis par kintai_test_translator_reset().
function kintai_test_translator(array $catalog = [], string $locale = 'en'): Illuminate\Translation\Translator
{
    $loader = new Illuminate\Translation\ArrayLoader();
    foreach ($catalog as $code => $lines) {
        $loader->addMessages($code, '*', $lines);
    }

    return kintai_test_install_translator(new Illuminate\Translation\Translator($loader, $locale));
}

// Aide des tests : traducteur sur les vrais fichiers lang/ (JSON et validation.php).
function kintai_test_real_translator(string $locale = 'en', bool $install = true): Illuminate\Translation\Translator
{
    $translator = new Illuminate\Translation\Translator(
        new Illuminate\Translation\FileLoader(new Illuminate\Filesystem\Filesystem(), dirname(__DIR__) . '/lang'),
        $locale,
    );
    $translator->setFallback($locale);

    return $install ? kintai_test_install_translator($translator) : $translator;
}

function kintai_test_install_translator(Illuminate\Translation\Translator $translator): Illuminate\Translation\Translator
{
    $app = $GLOBALS['kintai_test_app'];
    $GLOBALS['kintai_test_translator_original'] ??= $app->make('translator');
    $app->instance('translator', $translator);
    Illuminate\Support\Facades\Lang::clearResolvedInstance('translator');

    return $translator;
}

function kintai_test_translator_reset(): void
{
    $GLOBALS['kintai_test_app']->instance('translator', $GLOBALS['kintai_test_translator_original']);
    Illuminate\Support\Facades\Lang::clearResolvedInstance('translator');
}

// Par défaut les tests n'ont aucun texte : __() rend la clé. Un test qui veut des textes installe le sien.
$GLOBALS['kintai_test_translator_original'] = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en');
kintai_test_translator_reset();


// Aide des tests : moteur de vues de Laravel sur un autre dossier de vues (vues d'essai, copie…), avec les directives et
// les helpers de l'application.
/**
 * Messages flashés en session (success, danger, warning) par le code testé, retirés de la session : [type => texte].
 *
 * @return array<string, string>
 */
function kintai_test_flash(): array
{
    $messages = [];
    foreach (['success', 'danger', 'warning'] as $type) {
        if (session()->has($type)) {
            $messages[$type] = (string) session($type);
        }
    }
    session()->flush();

    return $messages;
}

/**
 * Mailer de Laravel dont le transport transmet chaque message à un rappel (voir Tests\Support\RecordingTransport) :
 * function (array $to, string $subject, string $html, array $headers): bool.
 */
function kintai_test_mailer(Closure $onSend): Illuminate\Mail\Mailer
{
    return new Illuminate\Mail\Mailer('test', app('view'), new Tests\Support\RecordingTransport($onSend));
}

/** Donne un corps JSON à une requête déjà construite (Request::capture() des tests). */
function kintai_test_set_json(Illuminate\Http\Request $request, array $json): void
{
    (new ReflectionProperty(Symfony\Component\HttpFoundation\Request::class, 'content'))->setValue($request, json_encode($json));
    $request->headers->set('Content-Type', 'application/json');
    (new ReflectionProperty(Illuminate\Http\Request::class, 'json'))->setValue($request, null);
}

/** Route qui répond à cette méthode et ce chemin dans le routeur courant des tests. */
function kintai_test_match(string $method, string $path): Illuminate\Routing\Route
{
    return app('router')->getRoutes()->match(Illuminate\Http\Request::create($path, $method));
}

/** Utilisateur minimal dans la base de test (après kintai_test_migrate()), pour les lignes qui le référencent. */
// Aide des tests : ce compte (créé au besoin) a un jeton « rester connecté » à révoquer.
function kintai_test_remember_token(int $userId): void
{
    kintai_test_ready('users');
    kintai_test_user($userId);
    Illuminate\Support\Facades\DB::table('users')->where('id', $userId)->update(['remember_token' => 'abc']);
}
function kintai_test_user(int $id = 1): int
{
    $now = date('Y-m-d H:i:s');
    Illuminate\Support\Facades\DB::table('users')->insertOrIgnore([
        'id' => $id, 'first_name' => '', 'last_name' => '', 'display_name' => '',
        'email' => 'user' . $id . '@test.local', 'password_hash' => 'x', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return $id;
}

function kintai_test_views(string $path, bool $withLayout = false): Illuminate\View\Factory
{
    $app    = $GLOBALS['kintai_test_app'];
    // Mises en page réduites à leur contenu (tests/Fixtures/views/layout) devant les vraies : un test de page vérifie la
    // page, pas la navigation qui l'entoure.
    $finder = new Illuminate\View\FileViewFinder(new Illuminate\Filesystem\Filesystem(), $withLayout ? [$path] : [__DIR__ . '/Fixtures/views', $path]);
    $views  = new Illuminate\View\Factory($app['view.engine.resolver'], $finder, $app['events']);
    $views->setContainer($app);
    $views->share('app', $app);

    return $views;
}
// Aide des tests : langues actives de l'instance (table languages) ; chaque code reçoit pour nom son code.
function kintai_test_languages(array $codes): void
{
    kintai_test_ready('languages');
    Illuminate\Support\Facades\DB::table('languages')->delete();
    foreach (array_values($codes) as $i => $code) {
        Illuminate\Support\Facades\DB::table('languages')->insert(['code' => $code, 'name' => $code, 'is_active' => 1, 'sort_order' => $i]);
    }
}
