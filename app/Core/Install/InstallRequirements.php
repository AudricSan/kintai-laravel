<?php

declare(strict_types=1);

namespace kintai\Core\Install;

use Closure;

/**
 * Prérequis vérifiés par l'installateur web (public/install.php) avant toute écriture : version de PHP, extensions,
 * dossiers inscriptibles. Un point bloquant désactive l'installation ; un point non bloquant est seulement signalé
 * (fonctionnalité dégradée : téléchargement des bundles, PDF…). Sans aucun pilote PDO disponible, rien n'est possible.
 */
final class InstallRequirements
{
    public const MIN_PHP = '8.3.0';

    /** Extension PDO requise par pilote de base de données. */
    public const DRIVER_EXTENSIONS = ['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql'];

    /** Extensions indispensables, quel que soit le pilote. */
    private const REQUIRED_EXTENSIONS = ['pdo', 'mbstring'];

    /** Extensions recommandées : leur absence ne bloque que certaines fonctionnalités. */
    private const RECOMMENDED_EXTENSIONS = ['openssl', 'zip', 'curl', 'gd'];

    private readonly Closure $extensionLoaded;
    private readonly Closure $isWritable;

    public function __construct(
        private readonly string $basePath,
        private readonly string $phpVersion = PHP_VERSION,
        ?Closure $extensionLoaded = null,
        ?Closure $isWritable = null,
    ) {
        $this->extensionLoaded = $extensionLoaded ?? static fn (string $ext): bool => extension_loaded($ext);
        $this->isWritable      = $isWritable ?? static fn (string $path): bool => is_writable($path);
    }

    /**
     * @return list<array{key:string, label:string, ok:bool, blocking:bool}>
     *         key = clé de traduction du libellé, label = valeur insérée (:name)
     */
    public function checks(): array
    {
        $checks = [[
            'key'      => 'install_req_php',
            'label'    => self::MIN_PHP . ' (' . $this->phpVersion . ')',
            'ok'       => version_compare($this->phpVersion, self::MIN_PHP, '>='),
            'blocking' => true,
        ]];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = ['key' => 'install_req_extension', 'label' => $ext, 'ok' => $this->loaded($ext), 'blocking' => true];
        }

        // Un seul pilote suffit : chacun n'est bloquant que si l'autre manque aussi.
        $anyDriver = $this->availableDrivers() !== [];
        foreach (self::DRIVER_EXTENSIONS as $ext) {
            $checks[] = ['key' => 'install_req_driver', 'label' => $ext, 'ok' => $this->loaded($ext), 'blocking' => !$anyDriver];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            $checks[] = ['key' => 'install_req_extension_optional', 'label' => $ext, 'ok' => $this->loaded($ext), 'blocking' => false];
        }

        foreach (['config', 'storage'] as $dir) {
            $checks[] = ['key' => 'install_req_writable', 'label' => $dir . '/', 'ok' => ($this->isWritable)($this->basePath . '/' . $dir), 'blocking' => true];
        }

        // Fichier d'environnement (langue de l'instance) : le fichier lui-même s'il existe, sinon la racine qui le recevra.
        $envPath = (new \kintai\Core\Services\EnvFileWriter($this->basePath))->path();
        $checks[] = [
            'key'      => 'install_req_writable',
            'label'    => basename($envPath),
            'ok'       => ($this->isWritable)(file_exists($envPath) ? $envPath : $this->basePath),
            'blocking' => true,
        ];

        return $checks;
    }

    /** @return list<string> pilotes ('sqlite', 'mysql') dont l'extension PDO est chargée */
    public function availableDrivers(): array
    {
        $drivers = [];
        foreach (self::DRIVER_EXTENSIONS as $driver => $ext) {
            if ($this->loaded($ext)) {
                $drivers[] = $driver;
            }
        }
        return $drivers;
    }

    public function driverAvailable(string $driver): bool
    {
        return in_array($driver, $this->availableDrivers(), true);
    }

    /** Vrai si aucun point bloquant n'échoue. */
    public function canInstall(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check['blocking'] && !$check['ok']) {
                return false;
            }
        }
        return true;
    }

    private function loaded(string $ext): bool
    {
        return ($this->extensionLoaded)($ext);
    }
}
