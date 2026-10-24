<?php

declare(strict_types=1);

namespace kintai\Core\Install;

/**
 * Données du formulaire de l'installateur web, nettoyées, avec leur validation. Les erreurs sont des clés de
 * traduction (lang/*.json) : la page les affiche dans la langue choisie.
 */
final class InstallInput
{
    /** Nom de base MySQL accepté : pas de caractère à échapper dans CREATE DATABASE. */
    public const MYSQL_DATABASE_PATTERN = '/^[A-Za-z0-9_$]{1,64}$/';

    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $password,
        public readonly string $passwordConfirmation,
        public readonly string $locale,
        public readonly string $driver,
        public readonly string $mysqlHost,
        public readonly int $mysqlPort,
        public readonly string $mysqlDatabase,
        public readonly string $mysqlUsername,
        public readonly string $mysqlPassword,
        public readonly bool $demoData,
    ) {}

    /** @param array<string, mixed> $post $_POST ; $defaultLocale sert quand app_locale est absent (installation scriptée) */
    public static function fromPost(array $post, string $defaultLocale): self
    {
        $str = static fn (string $key, string $default = ''): string => is_string($post[$key] ?? null) ? $post[$key] : $default;

        return new self(
            firstName:            trim($str('admin_first_name')),
            lastName:             trim($str('admin_last_name')),
            email:                trim($str('admin_email')),
            password:             $str('admin_password'),
            passwordConfirmation: $str('admin_password_confirmation'),
            locale:               trim($str('app_locale', $defaultLocale)),
            driver:               trim($str('db_driver')),
            mysqlHost:            trim($str('mysql_host', '127.0.0.1')),
            mysqlPort:            (int) $str('mysql_port', '3306'),
            mysqlDatabase:        trim($str('mysql_database')),
            mysqlUsername:        trim($str('mysql_username')),
            mysqlPassword:        $str('mysql_password'),
            demoData:             isset($post['run_seeds']),
        );
    }

    /**
     * @param list<string> $languageCodes langues actives (vide : pas de contrôle)
     * @return list<string> clés de traduction des erreurs, vide si tout est valide
     */
    public function errors(array $languageCodes, InstallRequirements $requirements): array
    {
        $errors = [];
        if ($this->firstName === '') {
            $errors[] = 'install_error_first_name';
        }
        if ($this->lastName === '') {
            $errors[] = 'install_error_last_name';
        }
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'install_error_email';
        }
        if (strlen($this->password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'install_error_password_length';
        } elseif (!hash_equals($this->password, $this->passwordConfirmation)) {
            $errors[] = 'install_error_password_mismatch';
        }
        if ($languageCodes !== [] && !in_array($this->locale, $languageCodes, true)) {
            $errors[] = 'install_error_language';
        }
        if (!in_array($this->driver, array_keys(InstallRequirements::DRIVER_EXTENSIONS), true)) {
            $errors[] = 'install_error_driver';
        } elseif (!$requirements->driverAvailable($this->driver)) {
            $errors[] = 'install_error_driver_unavailable';
        }
        if ($this->driver === 'mysql') {
            if ($this->mysqlHost === '') {
                $errors[] = 'install_error_mysql_host';
            }
            if (preg_match(self::MYSQL_DATABASE_PATTERN, $this->mysqlDatabase) !== 1) {
                $errors[] = 'install_error_mysql_database';
            }
            if ($this->mysqlUsername === '') {
                $errors[] = 'install_error_mysql_username';
            }
            if ($this->mysqlPort < 1 || $this->mysqlPort > 65535) {
                $errors[] = 'install_error_mysql_port';
            }
        }
        if (!$requirements->canInstall()) {
            $errors[] = 'install_error_requirements';
        }

        return $errors;
    }
}
