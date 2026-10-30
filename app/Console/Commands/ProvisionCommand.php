<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\PasswordHasher;
use App\Auth\PasswordPolicy;
use App\Database\DemoDataSeeder;
use App\Models\AppSetting;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Repositories\JsonLanguageRepository;
use App\Services\AppSettingsService;
use App\Services\EnvFileWriter;
use App\Services\InstanceLocale;
use App\Services\PublicUrlResolver;
use App\Services\SetupWizard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PDO;
use Throwable;

/**
 * Crée une instance neuve en ligne de commande (même résultat que l'installateur web) : dossiers de stockage, base,
 * variables DB_* du .env, migrations, compte Owner, URL publique, langue de l'instance, assistant de configuration,
 * verrou d'installation.
 *
 * Chaque option a pour repli une variable d'environnement : ADMIN_EMAIL, ADMIN_PASSWORD, ADMIN_FIRST_NAME,
 * ADMIN_LAST_NAME, APP_URL, APP_LOCALE, DB_DRIVER, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_PREFIX.
 */
final class ProvisionCommand extends Command
{
    protected $signature = 'kintai:provision
        {--admin-email= : adresse e-mail du compte Owner}
        {--admin-password= : mot de passe du compte Owner}
        {--admin-first-name= : prénom (Admin par défaut)}
        {--admin-last-name= : nom (Kintai par défaut)}
        {--app-url= : URL publique (http(s) complète)}
        {--locale= : langue de l\'instance (langue active)}
        {--db-driver= : sqlite (défaut) ou mysql}
        {--db-host=}
        {--db-port=}
        {--db-name=}
        {--db-user=}
        {--db-pass=}
        {--db-prefix=}
        {--seed : ajoute les données de démonstration}
        {--force : réinstalle une instance déjà installée}';

    protected $description = 'Crée une instance Kintai neuve (base, migrations, compte Owner, verrou d\'installation)';

    public function handle(): int
    {
        $adminEmail     = trim($this->value('admin-email', 'ADMIN_EMAIL'));
        $adminPassword  = $this->value('admin-password', 'ADMIN_PASSWORD');
        $adminFirstName = trim($this->value('admin-first-name', 'ADMIN_FIRST_NAME', 'Admin'));
        $adminLastName  = trim($this->value('admin-last-name', 'ADMIN_LAST_NAME', 'Kintai'));
        $appUrl         = trim($this->value('app-url', 'APP_URL'));
        $appLocale      = trim($this->value('locale', 'APP_LOCALE'));
        $driver         = $this->value('db-driver', 'DB_DRIVER', 'sqlite');
        $host           = $this->value('db-host', 'DB_HOST', '127.0.0.1');
        $port           = (int) $this->value('db-port', 'DB_PORT', '3306');
        $name           = $this->value('db-name', 'DB_DATABASE', 'kintai');
        $user           = $this->value('db-user', 'DB_USERNAME', 'root');
        $pass           = $this->value('db-pass', 'DB_PASSWORD');
        $prefix         = $this->value('db-prefix', 'DB_PREFIX');

        if ($adminEmail === '' || $adminPassword === '') {
            return $this->refuse('--admin-email et --admin-password sont requis.');
        }
        // Mêmes règles que l'installateur web.
        $check = Validator::make(
            ['email' => $adminEmail, 'first' => $adminFirstName, 'last' => $adminLastName, 'driver' => $driver],
            ['email' => ['email'], 'first' => ['required'], 'last' => ['required'], 'driver' => ['in:sqlite,mysql']],
        );
        if ($check->fails()) {
            return $this->refuse(match (true) {
                $check->errors()->has('email')  => "adresse e-mail d'administrateur invalide.",
                $check->errors()->has('driver') => 'driver invalide. Utilise sqlite ou mysql.',
                default                         => "le prénom et le nom de l'administrateur ne peuvent pas être vides.",
            });
        }
        if (!PasswordPolicy::isLongEnough($adminPassword)) {
            return $this->refuse('le mot de passe doit contenir au moins ' . PasswordPolicy::MIN_LENGTH . ' caractères.');
        }
        $publicUrl = null;
        if ($appUrl !== '') {
            $publicUrl = PublicUrlResolver::normalize($appUrl);
            if ($publicUrl === null) {
                return $this->refuse("--app-url doit être une adresse http(s) complète, sans paramètres ni identifiants (reçu : {$appUrl}).");
            }
        }
        // Langue de l'instance : celle de tous les textes tant qu'un utilisateur n'a pas choisi la sienne. Sans --locale,
        // la même que propose l'installateur web (anglais) ; jamais le repli « is_default » de lang/languages.json.
        $languages     = new JsonLanguageRepository(base_path('lang'));
        $activeLocales = array_column($languages->findAllActive(), 'code');
        if ($appLocale === '') {
            $appLocale = InstanceLocale::defaultForNewInstance($languages);
        } elseif (!in_array($appLocale, $activeLocales, true)) {
            return $this->refuse('--locale doit être une langue active (' . implode(', ', $activeLocales) . "), reçu : {$appLocale}.");
        }
        if (file_exists(base_path('storage/installed.lock')) && !$this->option('force')) {
            return $this->refuse('instance déjà installée. Utilise --force pour réinstaller.');
        }

        try {
            foreach (['app', 'logs', 'backups'] as $dir) {
                if (!is_dir(base_path("storage/$dir"))) {
                    mkdir(base_path("storage/$dir"), 0755, true);
                }
            }

            $this->createDatabase($driver, $host, $port, $name, $user, $pass);

            $dotenv = new EnvFileWriter(base_path());
            $dotenv->set('DB_CONNECTION', $driver);
            if ($driver === 'mysql') {
                foreach (['DB_HOST' => $host, 'DB_PORT' => (string) $port, 'DB_DATABASE' => $name, 'DB_USERNAME' => $user, 'DB_PASSWORD' => $pass, 'DB_PREFIX' => $prefix] as $key => $value) {
                    $dotenv->set($key, $value);
                }
            }
            $this->line('[Kintai] Variables DB_* écrites dans .env.');

            $this->useConnection($driver, $host, $port, $name, $user, $pass, $prefix);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            $this->line('[Kintai] Migrations exécutées.');

            if ($this->option('seed')) {
                $seeded = (new DemoDataSeeder())->run();
                $this->line("[Kintai] Données de démonstration : {$seeded['stores']} magasins, {$seeded['users']} comptes "
                    . '(mot de passe ' . DemoDataSeeder::PASSWORD . "), {$seeded['shifts']} shifts.");
            }

            // --force réinstalle par-dessus une base existante : un compte avec cet e-mail y existe peut-être déjà.
            if (User::findByEmail($adminEmail) !== null) {
                return $this->refuse("un compte existe déjà avec l'adresse {$adminEmail} : rien n'a été créé.");
            }
            $admin = User::put([
                'first_name'    => $adminFirstName,
                'last_name'     => $adminLastName,
                'display_name'  => $adminFirstName . ' ' . $adminLastName,
                'email'         => $adminEmail,
                'password_hash' => PasswordHasher::hash($adminPassword),
                'is_active'     => 1,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            // Le statut d'administrateur vient du RBAC (role_assignments), pas d'une colonne users.is_admin : sans cette
            // affectation, le compte créé n'aurait aucun droit.
            $ownerRole = Role::findBySlug('owner');
            if ($ownerRole === null) {
                return $this->refuse("rôle « owner » introuvable après les migrations : le compte {$adminEmail} a été créé sans droits.");
            }
            RoleAssignment::assign((int) $admin['id'], (int) $ownerRole['id'], 'global', null);
            $this->line("[Kintai] Admin '{$adminEmail}' créé (rôle Owner).");

            // Sert aux liens absolus des e-mails (réinitialisation du mot de passe) : en ligne de commande, aucune adresse
            // de requête ne permet de la deviner.
            if ($publicUrl !== null) {
                AppSetting::putMany([PublicUrlResolver::SETTING_KEY => $publicUrl]);
                $this->line("[Kintai] URL publique : {$publicUrl}");
            } else {
                $this->line("[Kintai] Attention : aucune URL publique (--app-url ou APP_URL). Les e-mails de réinitialisation du mot de "
                    . "passe ne partiront pas tant qu'elle n'est pas renseignée dans les réglages Owner.");
            }

            $dotenv->set(InstanceLocale::ENV_KEY, $appLocale);
            if ((string) env('APP_KEY', '') === '') {
                $dotenv->set('APP_KEY', 'base64:' . base64_encode(random_bytes(32)));
            }
            $this->line("[Kintai] Langue de l'instance : {$appLocale}");

            // Comme l'installateur web : aucun bundle actif, l'Owner choisit licence et bundles dans /admin/setup.
            SetupWizard::start(app(AppSettingsService::class)->updateChannel());
            $this->line("[Kintai] Licence et bundles : à choisir dans /admin/setup à la première connexion de l'Owner.");

            file_put_contents(base_path('storage/installed.lock'), bin2hex(random_bytes(32)));
            $this->line('[Kintai] Installation verrouillée.');
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        $this->line('[Kintai] Instance prête !');

        return self::SUCCESS;
    }

    /** Option, sinon variable d'environnement, sinon valeur par défaut (une option absente n'est pas une erreur). */
    private function value(string $option, string $envKey, string $default = ''): string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : (string) env($envKey, $default);
    }

    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln("Erreur : {$message}");

        return self::FAILURE;
    }

    private function createDatabase(string $driver, string $host, int $port, string $name, string $user, string $pass): void
    {
        if ($driver === 'mysql') {
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->line("[Kintai] Base de données MySQL '{$name}' créée.");

            return;
        }
        // Eloquent refuse de se connecter à un fichier SQLite absent : on le crée, comme l'installateur web.
        $path = base_path('storage/app/database.sqlite');
        if (!file_exists($path)) {
            touch($path);
            $this->line('[Kintai] Base de données SQLite créée.');
        }
    }

    /** Bascule la connexion du processus sur la base choisie (le .env n'est relu qu'au prochain démarrage). */
    private function useConnection(string $driver, string $host, int $port, string $name, string $user, string $pass, string $prefix): void
    {
        if ($driver === 'mysql') {
            config([
                'database.default'                  => 'mysql',
                'database.connections.mysql.host'     => $host,
                'database.connections.mysql.port'     => $port,
                'database.connections.mysql.database' => $name,
                'database.connections.mysql.username' => $user,
                'database.connections.mysql.password' => $pass,
                'database.connections.mysql.prefix'   => $prefix,
            ]);
        } else {
            config(['database.default' => 'sqlite']);
        }
        DB::purge();
    }
}
