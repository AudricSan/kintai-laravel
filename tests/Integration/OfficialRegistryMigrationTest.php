<?php

declare(strict_types=1);

namespace kintai\Tests\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * Mise à jour d'une instance vers Laravel : le registry officiel passe à celui des bundles Laravel, sans toucher à un
 * registry ajouté à la main.
 */
final class OfficialRegistryMigrationTest extends TestCase
{
    private const OLD_URL = 'https://raw.githubusercontent.com/AudricSan/KintaiBundle/main/registry.json';

    private Capsule $capsule;

    protected function setUp(): void
    {
        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        foreach (['2026_09_18_000000_create_bundle_registries_table', '2026_09_18_000002_create_installed_bundles_table'] as $name) {
            $this->migration($name)->up();
        }
    }

    private function migration(string $name): object
    {
        return require dirname(__DIR__, 2) . "/database/migrations/php/{$name}.php";
    }

    public function testTheOfficialRegistryMovesToTheLaravelBundles(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('bundle_registries')->insert([
            ['name' => 'Registry officiel Kintai', 'url' => self::OLD_URL, 'is_official' => true, 'created_at' => '2026-10-01 00:00:00'],
            ['name' => 'Mes bundles', 'url' => 'https://example.test/registry.json', 'is_official' => false, 'created_at' => '2026-10-01 00:00:00'],
        ]);
        $db->table('installed_bundles')->insert([
            ['slug' => 'timeoff', 'active_version' => '2.0.0', 'source_registry_url' => self::OLD_URL],
            ['slug' => 'mine', 'active_version' => '1.0.0', 'source_registry_url' => 'https://example.test/registry.json'],
        ]);

        $migration = $this->migration('2026_10_25_000000_point_official_registry_to_laravel_bundles');
        $migration->up();

        $new = (require dirname(__DIR__, 2) . '/config/official-bundle-registry.php')['url'];
        $this->assertSame('https://raw.githubusercontent.com/AudricSan/kintai-laravel-bundles/main/registry.json', $new);
        $this->assertSame($new, $db->table('bundle_registries')->where('is_official', true)->value('url'));
        $this->assertSame('https://example.test/registry.json', $db->table('bundle_registries')->where('is_official', false)->value('url'));
        $this->assertSame($new, $db->table('installed_bundles')->where('slug', 'timeoff')->value('source_registry_url'));
        $this->assertSame('https://example.test/registry.json', $db->table('installed_bundles')->where('slug', 'mine')->value('source_registry_url'));

        $migration->down();
        $this->assertSame(self::OLD_URL, $db->table('bundle_registries')->where('is_official', true)->value('url'));
    }

    public function testAnOfficialRegistryAlreadyChangedByHandIsLeftAlone(): void
    {
        $db = $this->capsule->getConnection();
        $db->table('bundle_registries')->insert(['name' => 'Miroir', 'url' => 'https://mirror.test/registry.json', 'is_official' => true, 'created_at' => '2026-10-01 00:00:00']);

        $this->migration('2026_10_25_000000_point_official_registry_to_laravel_bundles')->up();

        $this->assertSame('https://mirror.test/registry.json', $db->table('bundle_registries')->value('url'));
    }
}
