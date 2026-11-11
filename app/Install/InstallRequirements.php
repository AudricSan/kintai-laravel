<?php

namespace App\Install;

use Closure;

/**
 * Prérequis vérifiés avant toute écriture : version de PHP, extensions, dossiers inscriptibles. Un point bloquant
 * interdit l'installation ; un point non bloquant est seulement signalé (fonctionnalité dégradée).
 * Un seul pilote de base suffit (SQLite ou MySQL/MariaDB).
 */
final class InstallRequirements
{
    public const MIN_PHP = '8.3.0';

    /** Extension PDO requise par pilote de base de données. */
    public const DRIVER_EXTENSIONS = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql'];

    private const REQUIRED_EXTENSIONS = ['pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'fileinfo'];

    private const RECOMMENDED_EXTENSIONS = ['zip', 'curl', 'gd'];

    private readonly Closure $extensionLoaded;

    private readonly Closure $isWritable;

    public function __construct(
        private readonly string $basePath,
        private readonly string $phpVersion = PHP_VERSION,
        ?Closure $extensionLoaded = null,
        ?Closure $isWritable = null,
        private readonly ?string $environmentPath = null,
    ) {
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $extension): bool => extension_loaded($extension);
        $this->isWritable = $isWritable ?? static fn (string $path): bool => is_writable($path);
    }

    /**
     * @return list<array{label: string, ok: bool, blocking: bool}>
     */
    public function checks(): array
    {
        $checks = [[
            'label' => __('PHP :version or later (current: :current)', ['version' => self::MIN_PHP, 'current' => $this->phpVersion]),
            'ok' => version_compare($this->phpVersion, self::MIN_PHP, '>='),
            'blocking' => true,
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $checks[] = ['label' => __('PHP extension :name', ['name' => $extension]), 'ok' => $this->loaded($extension), 'blocking' => true];
        }

        $anyDriver = $this->availableDrivers() !== [];
        foreach (self::DRIVER_EXTENSIONS as $extension) {
            $checks[] = ['label' => __('Database driver :name (one is enough)', ['name' => $extension]), 'ok' => $this->loaded($extension), 'blocking' => ! $anyDriver];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $extension) {
            $checks[] = ['label' => __('PHP extension :name (recommended)', ['name' => $extension]), 'ok' => $this->loaded($extension), 'blocking' => false];
        }

        foreach (['storage', 'bootstrap/cache'] as $directory) {
            $checks[] = ['label' => __('Writable folder :name', ['name' => $directory.'/']), 'ok' => $this->writable($this->basePath.'/'.$directory), 'blocking' => true];
        }

        // `.env` : le fichier lui-même s'il existe, sinon le dossier où il sera créé.
        $environment = $this->environmentPath ?? $this->basePath.'/.env';
        $checks[] = [
            'label' => __('Writable file :name', ['name' => '.env']),
            'ok' => $this->writable(is_file($environment) ? $environment : dirname($environment)),
            'blocking' => true,
        ];

        return $checks;
    }

    /**
     * Aucun point bloquant en échec.
     */
    public function passes(): bool
    {
        return $this->failures() === [];
    }

    /**
     * Libellés des points bloquants en échec.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return array_values(array_map(
            fn (array $check): string => $check['label'],
            array_filter($this->checks(), fn (array $check): bool => $check['blocking'] && ! $check['ok']),
        ));
    }

    /**
     * Pilotes de base dont l'extension PDO est chargée.
     *
     * @return list<string>
     */
    public function availableDrivers(): array
    {
        return array_keys(array_filter(self::DRIVER_EXTENSIONS, fn (string $extension): bool => $this->loaded($extension)));
    }

    private function loaded(string $extension): bool
    {
        return (bool) ($this->extensionLoaded)($extension);
    }

    private function writable(string $path): bool
    {
        return (bool) ($this->isWritable)($path);
    }
}
