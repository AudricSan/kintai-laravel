<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Connexion à la base de l'instance, telle que la décrivent les fichiers de Kintai : valeurs par défaut (ci-dessous,
 * variables d'environnement DB_*) surchargées par config/database.local.php, écrit par l'installateur (« driver » et
 * « connections » par pilote).
 *
 * Source unique pour Laravel (config/database.php) et pour Kintai (DatabaseServiceProvider).
 */
final class DatabaseConfig
{
    /**
     * Pilote actif et sa configuration de connexion au format de Laravel/Eloquent.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public static function resolve(string $configDir): array
    {
        $config = self::load($configDir);
        $driver = (string) ($config['driver'] ?? 'sqlite');

        return [$driver, self::connection($driver, $config['connections'][$driver] ?? [])];
    }

    /** @return array{driver: string, connections: array<string, array<string, mixed>>} format historique de Kintai */
    public static function load(string $configDir): array
    {
        $config = self::defaults();

        $localPath = rtrim($configDir, '/\\') . '/database.local.php';
        if (file_exists($localPath)) {
            $local = require $localPath;
            if (isset($local['driver'])) {
                $config['driver'] = $local['driver'];
            }
            if (isset($local['connections']) && is_array($local['connections'])) {
                foreach ($local['connections'] as $driver => $cfg) {
                    $config['connections'][$driver] = array_merge($config['connections'][$driver] ?? [], $cfg);
                }
            }
        }

        return $config;
    }

    /** @return array{driver: string, connections: array<string, array<string, mixed>>} */
    private static function defaults(): array
    {
        return [
            'driver' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'path' => storage_path('app/database.sqlite'),
                ],
                'mysql' => [
                    'host'      => env('DB_HOST', '127.0.0.1'),
                    'port'      => (int) env('DB_PORT', 3306),
                    'database'  => env('DB_DATABASE', 'kintai'),
                    'username'  => env('DB_USERNAME', 'root'),
                    'password'  => env('DB_PASSWORD', ''),
                    'charset'   => env('DB_CHARSET', 'utf8mb4'),
                    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
                    'prefix'    => env('DB_PREFIX', ''),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $c configuration du pilote au format de Kintai
     * @return array<string, mixed>
     */
    private static function connection(string $driver, array $c): array
    {
        return match ($driver) {
            'sqlite' => [
                'driver'   => 'sqlite',
                'database' => $c['path'] ?? storage_path('app/database.sqlite'),
                'prefix'   => '',
                // Règles onDelete appliquées comme sous MySQL (audit du 13/10/2026, M8) : sans cela, supprimer
                // une ligne laissait ses dépendances orphelines sous SQLite et les supprimait sous MySQL.
                'foreign_key_constraints' => true,
            ],
            'mysql' => [
                'driver'    => 'mysql',
                'host'      => $c['host'] ?? '127.0.0.1',
                'port'      => $c['port'] ?? 3306,
                'database'  => $c['database'] ?? 'kintai',
                'username'  => $c['username'] ?? 'root',
                'password'  => $c['password'] ?? '',
                'charset'   => $c['charset'] ?? 'utf8mb4',
                'collation' => $c['collation'] ?? 'utf8mb4_unicode_ci',
                'prefix'    => $c['prefix'] ?? '',
            ],
            default => throw new \InvalidArgumentException(
                "Unsupported database driver: [{$driver}]. Supported: sqlite, mysql."
            ),
        };
    }
}
