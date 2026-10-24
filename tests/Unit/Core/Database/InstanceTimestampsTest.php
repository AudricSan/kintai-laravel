<?php

declare(strict_types=1);

namespace kintai\Tests\Unit\Core\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use kintai\Core\Database\InstanceTimestamps;
use PHPUnit\Framework\TestCase;

/**
 * created_at écrit dans le fuseau de l'instance (audit n°8, I5) : sous SQLite, la valeur par défaut CURRENT_TIMESTAMP
 * est en UTC, 9 h de décalage avec les dates écrites par PHP pour une instance à Tokyo.
 */
final class InstanceTimestampsTest extends TestCase
{
    private string $timezone;
    private mixed $dispatcher;

    protected function setUp(): void
    {
        $this->timezone   = date_default_timezone_get();
        $this->dispatcher = Model::getEventDispatcher();
        date_default_timezone_set('Asia/Tokyo');

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        Model::setEventDispatcher(new InstanceTimestamps());

        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('audit_rows', function ($t) {
            $t->increments('id');
            $t->string('label');
            $t->timestamp('created_at')->useCurrent();
        });
        $schema->create('audit_plain', function ($t) {
            $t->increments('id');
            $t->string('label');
        });
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        if ($this->dispatcher !== null) {
            Model::setEventDispatcher($this->dispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
    }

    public function testCreatedAtFollowsTheInstanceTimezone(): void
    {
        $before = date('Y-m-d H:i:s');
        $row = AuditRow::create(['label' => 'x']);
        $after = date('Y-m-d H:i:s');

        $stored = (string) AuditRow::query()->find($row->id)->created_at;
        $this->assertGreaterThanOrEqual($before, $stored, 'heure de Tokyo, pas UTC');
        $this->assertLessThanOrEqual($after, $stored);
    }

    public function testAnExplicitValueIsKept(): void
    {
        $row = AuditRow::create(['label' => 'x', 'created_at' => '2020-01-02 03:04:05']);

        $this->assertSame('2020-01-02 03:04:05', (string) AuditRow::query()->find($row->id)->created_at);
    }

    public function testATableWithoutCreatedAtIsUntouched(): void
    {
        $row = AuditPlain::create(['label' => 'x']);

        $this->assertSame(['id', 'label'], array_keys(AuditPlain::query()->find($row->id)->getAttributes()));
    }
}

final class AuditRow extends Model
{
    protected $table = 'audit_rows';
    protected $guarded = [];
    public $timestamps = false;
}

final class AuditPlain extends Model
{
    protected $table = 'audit_plain';
    protected $guarded = [];
    public $timestamps = false;
}
