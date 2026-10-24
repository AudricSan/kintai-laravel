<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseInstalledBundleRepository;
use Illuminate\Database\Capsule\Manager as Capsule;

final class DatabaseInstalledBundleRepositoryTest extends TestCase
{
    private DatabaseInstalledBundleRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('installed_bundles', function ($table) {
            $table->string('slug')->primary();
            $table->string('active_version');
            $table->string('source_registry_url')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $this->repo = new DatabaseInstalledBundleRepository();
    }

    public function testAllReturnsEmptyArrayWhenNothingInstalled(): void
    {
        $this->assertSame([], $this->repo->all());
    }

    public function testUpsertThenFindReturnsTheRow(): void
    {
        $this->repo->upsert('feedback', '1.0.0', 'https://example.test/registry.json');

        $found = $this->repo->find('feedback');

        $this->assertNotNull($found);
        $this->assertSame('feedback', $found['slug']);
        $this->assertSame('1.0.0', $found['active_version']);
        $this->assertSame('https://example.test/registry.json', $found['source_registry_url']);
    }

    public function testUpsertWithNullSourceRegistryUrl(): void
    {
        $this->repo->upsert('feedback', '1.0.0', null);

        $found = $this->repo->find('feedback');

        $this->assertNull($found['source_registry_url']);
    }

    public function testUpsertOverwritesTheActiveVersion(): void
    {
        $this->repo->upsert('feedback', '1.0.0', null);
        $this->repo->upsert('feedback', '1.1.0', null);

        $found = $this->repo->find('feedback');

        $this->assertSame('1.1.0', $found['active_version']);
        $this->assertCount(1, $this->repo->all());
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->find('does-not-exist'));
    }

    public function testDeleteRemovesTheRow(): void
    {
        $this->repo->upsert('feedback', '1.0.0', null);

        $this->repo->delete('feedback');

        $this->assertNull($this->repo->find('feedback'));
    }
}
