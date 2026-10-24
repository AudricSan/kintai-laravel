<?php
declare(strict_types=1);

namespace kintai\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use kintai\Core\Repositories\DatabaseUserNavPrefsRepository;
use Illuminate\Database\Capsule\Manager as Capsule;
use kintai\Domain\Eloquent\UserNavPref as EloquentUserNavPref;

final class DatabaseUserNavPrefsRepositoryTest extends TestCase
{
    private DatabaseUserNavPrefsRepository $repo;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule->getConnection()->getSchemaBuilder()->create('user_nav_prefs', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->text('hidden_items')->nullable();
            $table->text('section_order')->nullable();
            $table->text('bottom_nav_items')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $this->repo = new DatabaseUserNavPrefsRepository();
    }

    public function testGetHiddenReturnsEmptyWhenNoRow(): void
    {
        $this->assertSame([], $this->repo->getHidden(1));
    }

    public function testGetHiddenDecodesJsonArray(): void
    {
        EloquentUserNavPref::create([
            'user_id'      => 5,
            'hidden_items' => '["shifts","calendar"]',
        ]);
        $this->assertSame(['shifts', 'calendar'], $this->repo->getHidden(5));
    }

    public function testGetHiddenReturnsEmptyOnInvalidJson(): void
    {
        EloquentUserNavPref::create([
            'user_id'      => 5,
            'hidden_items' => 'not-json',
        ]);
        $this->assertSame([], $this->repo->getHidden(5));
    }

    public function testSaveHiddenUpdatesExistingRow(): void
    {
        EloquentUserNavPref::create(['user_id' => 5, 'hidden_items' => '[]']);
        $this->repo->saveHidden(5, ['timeoff']);
        $this->assertSame(['timeoff'], $this->repo->getHidden(5));
    }

    public function testSaveHiddenInsertsNewRow(): void
    {
        $this->repo->saveHidden(7, []);
        $this->assertSame([], $this->repo->getHidden(7));
    }

    public function testGetSectionOrderReturnsEmptyWhenNoRow(): void
    {
        $this->assertSame([], $this->repo->getSectionOrder(1));
    }

    public function testGetSectionOrderReturnsEmptyWhenColumnNull(): void
    {
        EloquentUserNavPref::create(['user_id' => 5, 'hidden_items' => '[]']);
        $this->assertSame([], $this->repo->getSectionOrder(5));
    }

    public function testGetSectionOrderDecodesJsonArray(): void
    {
        EloquentUserNavPref::create([
            'user_id'       => 5,
            'hidden_items'  => '[]',
            'section_order' => '["requests","planning","hr"]',
        ]);
        $this->assertSame(['requests', 'planning', 'hr'], $this->repo->getSectionOrder(5));
    }

    public function testGetSectionOrderReturnsEmptyOnInvalidJson(): void
    {
        EloquentUserNavPref::create([
            'user_id'       => 5,
            'hidden_items'  => '[]',
            'section_order' => 'bad-json',
        ]);
        $this->assertSame([], $this->repo->getSectionOrder(5));
    }

    public function testSaveSectionOrderUpdatesExistingRow(): void
    {
        EloquentUserNavPref::create([
            'user_id'       => 5,
            'hidden_items'  => '[]',
            'section_order' => null,
        ]);
        $this->repo->saveSectionOrder(5, ['hr', 'planning']);
        $this->assertSame(['hr', 'planning'], $this->repo->getSectionOrder(5));
    }

    public function testSaveSectionOrderInsertsNewRowWithDefaultHiddenItems(): void
    {
        $this->repo->saveSectionOrder(9, ['planning', 'requests']);
        $this->assertSame(['planning', 'requests'], $this->repo->getSectionOrder(9));
    }

    public function testSaveSectionOrderPreservesIndexesAsSequential(): void
    {
        EloquentUserNavPref::create(['user_id' => 3, 'hidden_items' => '[]']);
        $this->repo->saveSectionOrder(3, [5 => 'a', 10 => 'b', 20 => 'c']);
        $this->assertSame(['a', 'b', 'c'], $this->repo->getSectionOrder(3));
    }

    public function testSaveSectionOrderAcceptsEmptyArrayForReset(): void
    {
        EloquentUserNavPref::create(['user_id' => 11, 'hidden_items' => '[]']);
        $this->repo->saveSectionOrder(11, []);
        $this->assertSame([], $this->repo->getSectionOrder(11));
    }

    public function testGetBottomNavItemsReturnsEmptyWhenNoRow(): void
    {
        $this->assertSame([], $this->repo->getBottomNavItems(1));
    }

    public function testGetBottomNavItemsReturnsEmptyWhenColumnNull(): void
    {
        EloquentUserNavPref::create(['user_id' => 5, 'hidden_items' => '[]']);
        $this->assertSame([], $this->repo->getBottomNavItems(5));
    }

    public function testGetBottomNavItemsDecodesJsonArray(): void
    {
        EloquentUserNavPref::create([
            'user_id'          => 5,
            'hidden_items'     => '[]',
            'bottom_nav_items' => '["shifts","team","requests"]',
        ]);
        $this->assertSame(['shifts', 'team', 'requests'], $this->repo->getBottomNavItems(5));
    }

    public function testGetBottomNavItemsReturnsEmptyOnInvalidJson(): void
    {
        EloquentUserNavPref::create([
            'user_id'          => 5,
            'hidden_items'     => '[]',
            'bottom_nav_items' => 'not-json',
        ]);
        $this->assertSame([], $this->repo->getBottomNavItems(5));
    }

    public function testSaveBottomNavItemsUpdatesExistingRow(): void
    {
        EloquentUserNavPref::create(['user_id' => 5, 'hidden_items' => '[]']);
        $this->repo->saveBottomNavItems(5, ['shifts', 'messages']);
        $this->assertSame(['shifts', 'messages'], $this->repo->getBottomNavItems(5));
    }

    public function testSaveBottomNavItemsInsertsNewRowWithDefaultHiddenItems(): void
    {
        $this->repo->saveBottomNavItems(8, ['my_planning', 'timeclock']);
        $this->assertSame(['my_planning', 'timeclock'], $this->repo->getBottomNavItems(8));
    }

    public function testSaveBottomNavItemsPreservesSequentialIndexes(): void
    {
        EloquentUserNavPref::create(['user_id' => 12, 'hidden_items' => '[]']);
        $this->repo->saveBottomNavItems(12, [2 => 'x', 5 => 'y', 8 => 'z']);
        $this->assertSame(['x', 'y', 'z'], $this->repo->getBottomNavItems(12));
    }
}
