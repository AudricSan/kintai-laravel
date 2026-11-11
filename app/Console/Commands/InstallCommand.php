<?php

namespace App\Console\Commands;

use App\Install\Installer;
use App\Install\InstallFailure;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Installe Kintai : `.env`, clé d'application, base de données, migrations, rôles du Core et premier Owner.
 * Partage son cœur (`Installer`) avec l'assistant web. Sans interaction, tout passe par les options.
 * Refuse de s'exécuter si Kintai est déjà installé, et ne remplace jamais la base d'un `.env` existant sans `--force`.
 */
class InstallCommand extends Command
{
    protected $signature = 'kintai:install
                            {--name= : Name of the Owner account}
                            {--email= : Email of the Owner account}
                            {--password= : Password of the Owner account (visible in the process list: prefer the prompt)}
                            {--db-driver= : Database driver: sqlite (default) or mysql (MySQL and MariaDB)}
                            {--db-host= : MySQL/MariaDB host}
                            {--db-port= : MySQL/MariaDB port}
                            {--db-database= : MySQL/MariaDB database name}
                            {--db-username= : MySQL/MariaDB user}
                            {--db-password= : MySQL/MariaDB password}
                            {--url= : Public URL of the instance (APP_URL)}
                            {--local : Keep the development values of .env.example (APP_ENV=local, APP_DEBUG=true)}
                            {--force : Replace the database already configured in .env}';

    protected $description = 'Install Kintai: .env, application key, database, migrations and the first Owner account';

    public function handle(Installer $installer): int
    {
        if ($installer->isInstalled()) {
            $this->components->error('Kintai is already installed.');

            return self::FAILURE;
        }

        $failures = $installer->requirements()->failures();
        if ($failures !== []) {
            $this->components->error('Requirements are not met:');
            foreach ($failures as $failure) {
                $this->line(' - '.$failure);
            }

            return self::FAILURE;
        }

        $input = $this->collectOptions();

        try {
            if ($this->input->isInteractive()) {
                $input = $this->askMissing($installer, $input);
            }

            $installer->install($input, (bool) $this->option('force'), ! $this->option('local'));
        } catch (ValidationException $e) {
            $this->components->error('The given values are invalid:');
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->line(' - '.$message);
                }
            }

            return self::FAILURE;
        } catch (InstallFailure $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Kintai is installed. Sign in with the Owner account.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function collectOptions(): array
    {
        $input = [];

        foreach (['name', 'email', 'password', 'url'] as $key) {
            $input[$key] = $this->option($key);
        }

        foreach (['driver', 'host', 'port', 'database', 'username', 'password'] as $key) {
            $input['db_'.$key] = $this->option('db-'.$key);
        }

        return $input;
    }

    /**
     * Pose les questions manquantes. Les valeurs données en option ne sont pas redemandées.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function askMissing(Installer $installer, array $input): array
    {
        $this->line('Kintai installation');

        if (! $installer->databaseConfigured() || $this->option('force')) {
            $drivers = $installer->requirements()->availableDrivers();

            $input['db_driver'] ??= count($drivers) > 1
                ? select('Database', ['sqlite' => 'SQLite (a file, nothing else to set up)', 'mysql' => 'MySQL / MariaDB'], 'sqlite')
                : ($drivers[0] ?? 'sqlite');

            if ($input['db_driver'] === 'mysql') {
                $input['db_host'] ??= text('Database host', default: 'localhost', required: true);
                $input['db_port'] ??= text('Database port', default: '3306', required: true);
                $input['db_database'] ??= text('Database name', required: true);
                $input['db_username'] ??= text('Database user', required: true);
                $input['db_password'] ??= password('Database password') ?: null;
            }
        }

        $input['name'] ??= text('Owner name', required: true);
        $input['email'] ??= text('Owner email', required: true);

        if (! isset($input['password'])) {
            $input['password'] = password('Owner password', required: true);
            $input['password_confirmation'] = password('Confirm the password', required: true);
        }

        return $input;
    }
}
