<?php

namespace App\Install;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cœur partagé de l'installation, utilisé par `php artisan kintai:install` et par l'assistant web (`/install`) :
 * prérequis, `.env`, clé d'application, base de données, migrations, rôles du Core, premier Owner, verrou.
 *
 * L'installation est terminée quand le fichier verrou existe (`storage/app/installed`) ; une base qui a déjà un Owner
 * (instance antérieure au verrou) compte aussi comme installée.
 */
class Installer
{
    private readonly EnvironmentFile $environment;

    private readonly InstallRequirements $requirements;

    public function __construct(
        ?EnvironmentFile $environment = null,
        ?InstallRequirements $requirements = null,
        private readonly ?string $sqlitePath = null,
    ) {
        $this->environment = $environment ?? new EnvironmentFile(app()->environmentFilePath(), base_path('.env.example'));
        $this->requirements = $requirements ?? new InstallRequirements(base_path(), environmentPath: $this->environment->path());
    }

    public function requirements(): InstallRequirements
    {
        return $this->requirements;
    }

    public function lockPath(): string
    {
        $path = config('kintai.install.lock');

        return is_string($path) && $path !== '' ? $path : storage_path('app/installed');
    }

    /** Jeton d'installation web, à lire sur le serveur (même dossier que le verrou). */
    public function setupTokenPath(): string
    {
        return dirname($this->lockPath()).DIRECTORY_SEPARATOR.'install-token';
    }

    /**
     * Crée au besoin le jeton exigé par l'assistant web : sans lui, le premier visiteur d'une instance vierge
     * (ou remise à zéro) deviendrait Owner. Il faut un accès au serveur (FTP, SSH) pour le lire.
     *
     * @throws InstallFailure
     */
    public function ensureSetupToken(): void
    {
        $path = $this->setupTokenPath();

        if (is_file($path)) {
            return;
        }

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new InstallFailure(__('The setup token could not be written.'));
        }

        if (@file_put_contents($path, bin2hex(random_bytes(16)).'
') === false) {
            throw new InstallFailure(__('The setup token could not be written.'));
        }
    }

    public function verifySetupToken(string $given): bool
    {
        $expected = is_file($this->setupTokenPath()) ? trim((string) @file_get_contents($this->setupTokenPath())) : '';

        return $expected !== '' && hash_equals($expected, trim($given));
    }

    public function isInstalled(): bool
    {
        if (is_file($this->lockPath())) {
            return true;
        }

        if (! $this->hasOwner()) {
            return false;
        }

        // Instance antérieure au verrou : on le pose pour ne plus interroger la base à chaque requête.
        try {
            $this->markInstalled();
        } catch (InstallFailure) {
            // Dossier non inscriptible : la base continue de faire foi.
        }

        return true;
    }

    /**
     * Le `.env` choisit-il déjà une base de données ? Alors l'installation s'en sert et n'y touche pas (sauf `force`).
     */
    public function databaseConfigured(): bool
    {
        foreach (['DB_DATABASE', 'DB_HOST', 'DB_URL'] as $name) {
            if (($this->environment->get($name) ?? '') !== '') {
                return true;
            }
        }

        return ! in_array($this->environment->get('DB_CONNECTION') ?? 'sqlite', ['', 'sqlite'], true);
    }

    /**
     * Crée `.env` depuis `.env.example` s'il manque et y met une clé d'application si elle manque. N'écrase rien d'existant.
     * Une instance créée ici est réglée pour la production (APP_ENV, APP_DEBUG) sauf `$production = false`.
     *
     * @throws InstallFailure
     */
    public function prepareEnvironment(bool $production = true): void
    {
        $created = $this->environment->createFromExample();

        if ($created && $production) {
            $this->environment->set(['APP_ENV' => 'production', 'APP_DEBUG' => 'false']);
        }

        if (config('app.key') === null || config('app.key') === '') {
            $key = $this->generateKey();
            $this->environment->set(['APP_KEY' => $key]);
            config(['app.key' => $key]);
        }
    }

    /**
     * Installe : valide, vérifie la base, écrit `.env`, migre, crée les rôles et le premier Owner, pose le verrou.
     * Rien n'est écrit tant que les saisies et la connexion à la base ne sont pas bonnes.
     *
     * @param  array<string, mixed>  $input  name, email, password, [password_confirmation], db_driver, db_host, db_port, db_database, db_username, db_password, url
     * @param  bool  $force  remplace la base déjà réglée dans `.env`
     * @param  bool  $production  règle APP_ENV et APP_DEBUG pour la production quand `.env` est créé
     *
     * @throws InstallFailure
     * @throws ValidationException
     */
    public function install(array $input, bool $force = false, bool $production = true): User
    {
        $this->assertNotInstalled();

        // Verrou exclusif : deux requêtes simultanées ne peuvent pas créer deux Owners.
        $guard = $this->acquireInstallGuard();

        try {
            // Une installation concurrente a pu se terminer entre-temps.
            $this->assertNotInstalled();

            return $this->performInstall($input, $force, $production);
        } finally {
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /**
     * @throws InstallFailure
     */
    private function assertNotInstalled(): void
    {
        if ($this->isInstalled()) {
            throw new InstallFailure(__('Kintai is already installed.'));
        }
    }

    /**
     * @return resource
     *
     * @throws InstallFailure
     */
    private function acquireInstallGuard()
    {
        $path = $this->lockPath().'.installing';

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new InstallFailure(__('The installation lock could not be written.'));
        }

        $handle = @fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new InstallFailure(__('An installation is already in progress.'));
        }

        return $handle;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws InstallFailure
     * @throws ValidationException
     */
    private function performInstall(array $input, bool $force, bool $production): User
    {
        $failures = $this->requirements->failures();
        if ($failures !== []) {
            throw new InstallFailure(__('Requirements are not met: :list', ['list' => implode(', ', $failures)]));
        }

        $keepDatabase = $this->databaseConfigured() && ! $force;

        if ($keepDatabase && $this->hasDatabaseInput($input)) {
            throw new InstallFailure(__('The .env file already configures a database. Use --force to replace it.'));
        }

        $data = InstallInput::validate($input, withDatabase: ! $keepDatabase);

        $this->prepareEnvironment($production);

        if ($keepDatabase) {
            $this->connectConfigured();
        } else {
            $this->connect($data);
            $this->writeDatabase($data);
        }

        if ($data['url'] !== null && ! $keepDatabase) {
            $this->environment->set(['APP_URL' => $data['url']]);
        }

        $this->run('migrate');
        $this->run('db:seed', ['--class' => RoleSeeder::class]);

        $owner = $this->createOwner($data);

        $this->markInstalled();

        return $owner;
    }

    /**
     * @throws InstallFailure
     */
    public function markInstalled(): void
    {
        $path = $this->lockPath();

        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0755, true) && ! is_dir(dirname($path))) {
            throw new InstallFailure(__('The installation lock could not be written.'));
        }

        if (@file_put_contents($path, now()->toIso8601String()."\n") === false) {
            throw new InstallFailure(__('The installation lock could not be written.'));
        }

        @unlink($this->setupTokenPath());
    }

    private function hasOwner(): bool
    {
        try {
            return User::query()->whereHas('roles', fn ($roles) => $roles->where('is_system', true))->exists();
        } catch (Throwable) {
            // Pas de `.env`, pas de base ou pas de tables : rien d'installé.
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hasDatabaseInput(array $input): bool
    {
        foreach (['db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_password'] as $key) {
            if (($input[$key] ?? null) !== null && $input[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function generateKey(): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
    }

    /**
     * Applique la base choisie à la configuration d'exécution et vérifie la connexion, avant d'écrire `.env`.
     *
     * @param  array{db_driver: string, db_host: string|null, db_port: int|null, db_database: string|null, db_username: string|null, db_password: string|null}  $data
     *
     * @throws InstallFailure
     */
    private function connect(array $data): void
    {
        $driver = $data['db_driver'];

        if ($driver === 'sqlite') {
            config([
                'database.connections.sqlite.url' => null,
                'database.connections.sqlite.database' => $this->sqliteFile(),
            ]);
        } else {
            config([
                'database.connections.mysql.url' => null,
                'database.connections.mysql.host' => $data['db_host'],
                'database.connections.mysql.port' => (string) ($data['db_port'] ?? 3306),
                'database.connections.mysql.database' => $data['db_database'],
                'database.connections.mysql.username' => $data['db_username'],
                'database.connections.mysql.password' => $data['db_password'] ?? '',
            ]);
        }

        config(['database.default' => $driver]);

        $this->verifyConnection();
    }

    /**
     * Base déjà réglée dans `.env` : on la vérifie telle quelle (un fichier SQLite absent est créé).
     *
     * @throws InstallFailure
     */
    private function connectConfigured(): void
    {
        $default = (string) config('database.default');

        if ($default === 'sqlite') {
            $file = (string) config('database.connections.sqlite.database');

            if ($file !== ':memory:' && $file !== '') {
                $this->ensureSqliteFile($file);
            }
        }

        $this->verifyConnection();
    }

    /**
     * @throws InstallFailure
     */
    private function verifyConnection(): void
    {
        $name = (string) config('database.default');

        try {
            DB::purge($name);
            DB::connection($name)->getPdo();
        } catch (Throwable $e) {
            throw new InstallFailure(__('Could not connect to the database: :message', ['message' => $e->getMessage()]));
        }
    }

    private function sqliteFile(): string
    {
        $file = $this->sqlitePath ?? database_path('database.sqlite');
        $this->ensureSqliteFile($file);

        return $file;
    }

    /**
     * @throws InstallFailure
     */
    private function ensureSqliteFile(string $file): void
    {
        if (is_file($file)) {
            return;
        }

        if ((! is_dir(dirname($file)) && ! @mkdir(dirname($file), 0755, true) && ! is_dir(dirname($file))) || @touch($file) === false) {
            throw new InstallFailure(__('The SQLite database file could not be created: :path', ['path' => $file]));
        }
    }

    /**
     * Écrit la base choisie dans `.env` (le fichier SQLite par défaut n'a pas besoin de DB_DATABASE).
     *
     * @param  array{db_driver: string, db_host: string|null, db_port: int|null, db_database: string|null, db_username: string|null, db_password: string|null}  $data
     *
     * @throws InstallFailure
     */
    private function writeDatabase(array $data): void
    {
        $this->environment->forget(['DB_URL']);

        if ($data['db_driver'] === 'sqlite') {
            $this->environment->set(['DB_CONNECTION' => 'sqlite']);
            $this->environment->forget(['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE']);

            if ($this->sqliteFile() !== database_path('database.sqlite')) {
                $this->environment->set(['DB_DATABASE' => $this->sqliteFile()]);
            }

            return;
        }

        $this->environment->set([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $data['db_host'],
            'DB_PORT' => (string) ($data['db_port'] ?? 3306),
            'DB_DATABASE' => (string) $data['db_database'],
            'DB_USERNAME' => (string) $data['db_username'],
            'DB_PASSWORD' => (string) ($data['db_password'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     *
     * @throws InstallFailure
     */
    private function run(string $command, array $arguments = []): void
    {
        try {
            $status = Artisan::call($command, $arguments + ['--force' => true]);
        } catch (Throwable $e) {
            throw new InstallFailure(__('The step ":step" failed: :message', ['step' => $command, 'message' => $e->getMessage()]));
        }

        if ($status !== 0) {
            throw new InstallFailure(__('The step ":step" failed: :message', ['step' => $command, 'message' => trim(Artisan::output())]));
        }
    }

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    private function createOwner(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User;
            $user->fill([
                'name' => $data['name'],
                'first_name' => $data['name'],
                'last_name' => '',
                'email' => $data['email'],
                'password' => $data['password'],
            ]);
            $user->must_change_password = false;
            $user->save();

            $user->assignRole(Role::query()->where('slug', 'owner')->firstOrFail());

            return $user;
        });
    }
}
