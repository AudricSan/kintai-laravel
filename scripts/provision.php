<?php

/**
 * CLI provisioner for Kintai — creates a new instance from scratch (même résultat que l'installateur web).
 *
 * Usage:
 *   php scripts/provision.php \
 *       --admin-email=admin@example.com \
 *       --admin-password=secret \
 *       [--admin-first-name=Admin] \
 *       [--admin-last-name=Demo] \
 *       [--app-url=https://kintai.example.com] \
 *       [--locale=en|fr|ja] \
 *       [--db-driver=sqlite|mysql] \
 *       [--db-host=127.0.0.1] \
 *       [--db-port=3306] \
 *       [--db-name=kintai] \
 *       [--db-user=root] \
 *       [--db-pass=] \
 *       [--db-prefix=kt_] \
 *       [--seed] \
 *       [--force]
 *
 * Environment variables are used as fallback: ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_FIRST_NAME, ADMIN_LAST_NAME,
 * APP_URL, APP_LOCALE, DB_DRIVER, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PREFIX.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/bootstrap/standalone.php';
require_once BASE_PATH . '/app/Support/helpers.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use App\Auth\PasswordHasher;
use App\Auth\PasswordPolicy;
use App\Database\DemoDataSeeder;
use App\Repositories\AppSettingsRepositoryInterface;
use App\Repositories\JsonLanguageRepository;
use App\Repositories\RoleAssignmentRepositoryInterface;
use App\Repositories\RoleRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\EnvFileWriter;
use App\Services\InstanceLocale;
use App\Services\PublicUrlResolver;

// ─── Parse CLI options ───────────────────────────────────────────────────

$opts = getopt('', [
    'admin-email:',
    'admin-password:',
    'admin-first-name:',
    'admin-last-name:',
    'app-url:',
    'locale:',
    'db-driver:',
    'db-host:',
    'db-port:',
    'db-name:',
    'db-user:',
    'db-pass:',
    'db-prefix:',
    'seed',
    'force',
]);

/** Option CLI, sinon variable d'environnement, sinon valeur par défaut (une option absente n'est pas une erreur). */
function option(array $opts, string $name, string $envKey, string $default = ''): string
{
    $value = $opts[$name] ?? '';
    if (is_string($value) && $value !== '') {
        return $value;
    }
    return (string) env($envKey, $default);
}

$adminEmail     = trim(option($opts, 'admin-email', 'ADMIN_EMAIL'));
$adminPassword  = option($opts, 'admin-password', 'ADMIN_PASSWORD');
$adminFirstName = trim(option($opts, 'admin-first-name', 'ADMIN_FIRST_NAME', 'Admin'));
$adminLastName  = trim(option($opts, 'admin-last-name', 'ADMIN_LAST_NAME', 'Kintai'));
$appUrl         = trim(option($opts, 'app-url', 'APP_URL'));
$appLocale      = trim(option($opts, 'locale', 'APP_LOCALE'));
$dbDriver       = option($opts, 'db-driver', 'DB_DRIVER', 'sqlite');
$dbHost         = option($opts, 'db-host', 'DB_HOST', '127.0.0.1');
$dbPort         = (int) option($opts, 'db-port', 'DB_PORT', '3306');
$dbName         = option($opts, 'db-name', 'DB_DATABASE', 'kintai');
$dbUser         = option($opts, 'db-user', 'DB_USERNAME', 'root');
$dbPass         = option($opts, 'db-pass', 'DB_PASSWORD');
$dbPrefix       = option($opts, 'db-prefix', 'DB_PREFIX');
$runSeeds       = isset($opts['seed']);
$force          = isset($opts['force']);

function fail(string $message): never
{
    fwrite(STDERR, "Erreur : {$message}\n");
    exit(1);
}

// ─── Guards (mêmes règles que l'installateur web) ─────────────────────────

if ($adminEmail === '' || $adminPassword === '') {
    fail('--admin-email et --admin-password sont requis.');
}
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fail("adresse e-mail d'administrateur invalide.");
}
if (!PasswordPolicy::isLongEnough($adminPassword)) {
    fail('le mot de passe doit contenir au moins ' . PasswordPolicy::MIN_LENGTH . ' caractères.');
}
if ($adminFirstName === '' || $adminLastName === '') {
    fail("le prénom et le nom de l'administrateur ne peuvent pas être vides.");
}
if (!in_array($dbDriver, ['sqlite', 'mysql'], true)) {
    fail('driver invalide. Utilise sqlite ou mysql.');
}
$publicUrl = null;
if ($appUrl !== '') {
    $publicUrl = PublicUrlResolver::normalize($appUrl);
    if ($publicUrl === null) {
        fail("--app-url doit être une adresse http(s) complète, sans paramètres ni identifiants (reçu : {$appUrl}).");
    }
}
// Langue de l'instance (APP_LOCALE) : celle de tous les textes tant qu'un utilisateur n'a pas choisi la sienne. Sans
// --locale, la même que propose l'installateur web (anglais) ; jamais le repli « is_default » de lang/languages.json.
$languageRepo  = new JsonLanguageRepository(BASE_PATH . '/lang');
$activeLocales = array_column($languageRepo->findAllActive(), 'code');
if ($appLocale === '') {
    $appLocale = InstanceLocale::defaultForNewInstance($languageRepo);
} elseif (!in_array($appLocale, $activeLocales, true)) {
    fail('--locale doit être une langue active (' . implode(', ', $activeLocales) . "), reçu : {$appLocale}.");
}
if (file_exists(BASE_PATH . '/storage/installed.lock') && !$force) {
    fail('instance déjà installée. Utilise --force pour réinstaller.');
}

// ─── Helpers ──────────────────────────────────────────────────────────────

function pdo_dsn_without_db(string $driver, string $host, int $port): string
{
    return match ($driver) {
        'mysql' => sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
        default => throw new InvalidArgumentException("Unsupported driver: $driver"),
    };
}

function pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
}

// Application coupe l'affichage des erreurs hors mode debug : sans ce filet, une erreur arrêtait le script sans
// aucun message (code de sortie 255 et rien d'autre).
try {
    // ─── Step 1 : Storage directories ────────────────────────────────────────

    foreach ([BASE_PATH . '/storage/app', BASE_PATH . '/storage/logs', BASE_PATH . '/storage/backups'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    // ─── Step 2 : Create database ────────────────────────────────────────────

    if ($dbDriver === 'mysql') {
        $serverPdo = new PDO(
            pdo_dsn_without_db('mysql', $dbHost, $dbPort),
            $dbUser,
            $dbPass,
            pdo_options()
        );
        $serverPdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` "
            . "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        unset($serverPdo);
        echo "[Kintai] Base de données MySQL '{$dbName}' créée.\n";
    } else {
        // Eloquent refuse de se connecter à un fichier SQLite absent : on le crée, comme l'installateur web.
        $sqlitePath = BASE_PATH . '/storage/app/database.sqlite';
        if (!file_exists($sqlitePath)) {
            touch($sqlitePath);
            echo "[Kintai] Base de données SQLite créée.\n";
        }
    }

    // ─── Step 3 : Variables DB_* du fichier .env ─────────────────────────────

    $dotenv = new EnvFileWriter(BASE_PATH);
    $dotenv->set('DB_CONNECTION', $dbDriver);
    if ($dbDriver === 'mysql') {
        $dotenv->set('DB_HOST', $dbHost);
        $dotenv->set('DB_PORT', (string) $dbPort);
        $dotenv->set('DB_DATABASE', $dbName);
        $dotenv->set('DB_USERNAME', $dbUser);
        $dotenv->set('DB_PASSWORD', $dbPass);
        $dotenv->set('DB_PREFIX', $dbPrefix);
    }
    echo "[Kintai] Variables DB_* écrites dans .env.\n";

    // ─── Step 4 : Boot framework, run migrations ────────────────────────────

    $app = require BASE_PATH . '/bootstrap/boot.php';

    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('migrate', ['--force' => true]);
    $kernel->call('db:seed', ['--force' => true]);
    echo "[Kintai] Migrations exécutées.\n";

    // ─── Step 5 : Seeders ────────────────────────────────────────────────────

    if ($runSeeds) {
        // Données de démonstration du Core, écrites par les modèles (indépendantes du pilote et du schéma).
        $seeded = (new DemoDataSeeder())->run();
        echo "[Kintai] Données de démonstration : {$seeded['stores']} magasins, {$seeded['users']} comptes "
            . "(mot de passe " . DemoDataSeeder::PASSWORD . "), {$seeded['shifts']} shifts.\n";
    }

    // ─── Step 6 : Create admin user (rôle Owner) ─────────────────────────────

    $container = $app;
    $userRepo  = $container->make(UserRepositoryInterface::class);

    // --force réinstalle par-dessus une base existante : un compte avec cet e-mail y existe peut-être déjà.
    if ($userRepo->findByEmail($adminEmail) !== null) {
        fail("un compte existe déjà avec l'adresse {$adminEmail} : rien n'a été créé.");
    }

    $adminUser = $userRepo->save([
        'first_name'    => $adminFirstName,
        'last_name'     => $adminLastName,
        'display_name'  => $adminFirstName . ' ' . $adminLastName,
        'email'         => $adminEmail,
        'password_hash' => PasswordHasher::hash($adminPassword),
        'is_active'     => 1,
        'created_at'    => date('Y-m-d H:i:s'),
        'updated_at'    => date('Y-m-d H:i:s'),
    ]);

    // Le statut d'administrateur vient du RBAC (role_assignments), plus d'une colonne users.is_admin (supprimée le
    // 19/09/2026) : sans cette affectation, le compte créé n'aurait aucun droit.
    $ownerRole = $container->make(RoleRepositoryInterface::class)->findBySlug('owner');
    if ($ownerRole === null) {
        fail("rôle « owner » introuvable après les migrations : le compte {$adminEmail} a été créé sans droits.");
    }
    $container->make(RoleAssignmentRepositoryInterface::class)
        ->assign((int) $adminUser['id'], (int) $ownerRole['id'], 'global', null);
    echo "[Kintai] Admin '{$adminEmail}' créé (rôle Owner).\n";

    // ─── Step 7 : Public URL ─────────────────────────────────────────────────

    // Sert aux liens absolus des e-mails (réinitialisation du mot de passe). En ligne de commande, aucune adresse de
    // requête ne permet de la deviner : --app-url ou APP_URL.
    if ($publicUrl !== null) {
        $container->make(AppSettingsRepositoryInterface::class)
            ->setMany([PublicUrlResolver::SETTING_KEY => $publicUrl]);
        echo "[Kintai] URL publique : {$publicUrl}\n";
    } else {
        echo "[Kintai] Attention : aucune URL publique (--app-url ou APP_URL). Les e-mails de réinitialisation du mot de "
            . "passe ne partiront pas tant qu'elle n'est pas renseignée dans les réglages Owner.\n";
    }

    // ─── Step 7 bis : Langue de l'instance ───────────────────────────────────

    (new EnvFileWriter(BASE_PATH))->set(InstanceLocale::ENV_KEY, $appLocale);
    if ((string) env('APP_KEY', '') === '') {
        (new EnvFileWriter(BASE_PATH))->set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
    }
    echo "[Kintai] Langue de l'instance : {$appLocale}\n";

    // ─── Step 7 ter : Assistant de configuration ───────────────────────────

    // Comme l'installateur web : aucun bundle actif, l'Owner choisit licence et bundles dans /admin/setup (un
    // bandeau l'y invite à sa première connexion).
    \App\Services\SetupWizard::start(
        $container->make(AppSettingsRepositoryInterface::class),
        $container->make(\App\Services\AppSettingsService::class)->updateChannel(),
    );
    echo "[Kintai] Licence et bundles : à choisir dans /admin/setup à la première connexion de l'Owner.\n";

    // ─── Step 8 : Lock installation ─────────────────────────────────────────

    file_put_contents(BASE_PATH . '/storage/installed.lock', bin2hex(random_bytes(32)));
    echo "[Kintai] Installation verrouillée.\n";
} catch (\Throwable $e) {
    fail($e->getMessage());
}

echo "[Kintai] Instance prête !\n";
exit(0);
