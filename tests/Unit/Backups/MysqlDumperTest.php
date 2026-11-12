<?php

namespace Tests\Unit\Backups;

use App\Backups\BackupException;
use App\Backups\MysqlDumper;
use Illuminate\Database\Connection;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * Le dumper MySQL est testé sans serveur : un PDO simulé (qui échappe comme le pilote MySQL) et des jeux de lignes fournis
 * à la place de `SHOW ...` / `SELECT ... LIMIT`.
 */
class MysqlDumperTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kintai-dump-'.bin2hex(random_bytes(4)).'.sql';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function mysqlLikePdo(): RecordingPdo
    {
        return new RecordingPdo('sqlite::memory:');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     */
    private function dumper(array $rows, RecordingPdo $pdo, int $batch = 2, array $dropExisting = []): FakeMysqlDumper
    {
        return new FakeMysqlDumper(\Mockery::mock(Connection::class), $batch, $rows, $pdo, $dropExisting);
    }

    public function test_it_writes_structure_then_data_in_batches_with_escaping(): void
    {
        $pdo = $this->mysqlLikePdo();
        $rows = ['people' => [
            ['id' => 1, 'name' => "O'Brien", 'note' => null, 'photo' => "\x00\xFF\x10", 'score' => 1.5, 'active' => true],
            ['id' => 2, 'name' => 'back\\slash', 'note' => "line1\nline2\r\n", 'photo' => '', 'score' => 2.0, 'active' => false],
            ['id' => 3, 'name' => 'Émilie 日本語', 'note' => 'x; -- kintai:end', 'photo' => null, 'score' => 0.0, 'active' => true],
            ['id' => 4, 'name' => 'four', 'note' => '', 'photo' => null, 'score' => 0.0, 'active' => true],
            ['id' => 5, 'name' => 'five', 'note' => '', 'photo' => null, 'score' => 0.0, 'active' => true],
        ]];

        $this->dumper($rows, $pdo, batch: 2)->dump($this->file);
        $sql = (string) file_get_contents($this->file);

        $this->assertStringContainsString('SET NAMES utf8mb4', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0', $sql);
        $this->assertStringContainsString("DROP TABLE IF EXISTS `people`\n-- kintai:end", $sql);
        $this->assertStringContainsString('CREATE TABLE `people` (', $sql);

        // 5 lignes, lots de 2 : trois INSERT.
        $this->assertSame(3, substr_count($sql, 'INSERT INTO `people` (`id`, `name`, `note`, `photo`, `score`, `active`) VALUES'));

        $this->assertStringContainsString("(1, 'O\'Brien', NULL, 0x00ff10, 1.5, 1)", $sql);
        $this->assertStringContainsString("'back\\\\slash'", $sql);
        $this->assertStringContainsString("'line1\\nline2\\r\\n'", $sql);
        $this->assertStringContainsString("'', NULL", $sql, 'empty binary stays an empty literal');
        $this->assertStringContainsString("'Émilie 日本語'", $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1', $sql);
        $this->assertStringEndsWith("-- kintai:complete\n", $sql);

        // Aucune valeur ne contient un vrai retour à la ligne : chaque INSERT tient sur une ligne.
        foreach (explode("\n", $sql) as $line) {
            $this->assertFalse(str_contains($line, "\r"));
        }
    }

    public function test_a_value_cannot_forge_the_end_of_a_statement(): void
    {
        $pdo = $this->mysqlLikePdo();
        $evil = "x');\n-- kintai:end\nDROP TABLE users; --";

        $this->dumper(['people' => [['id' => 1, 'name' => $evil, 'note' => null, 'photo' => null, 'score' => 0.0, 'active' => true]]], $pdo)->dump($this->file);

        $statements = [];
        $handle = fopen($this->file, 'rb');
        $complete = (new MysqlDumper(\Mockery::mock(Connection::class)))->readStatements($handle, function (string $sql) use (&$statements): void {
            $statements[] = $sql;
        });
        fclose($handle);

        $this->assertTrue($complete);
        $inserts = array_values(array_filter($statements, fn ($s) => str_starts_with($s, 'INSERT')));
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString('DROP TABLE users', $inserts[0]);
        $this->assertSame([], array_filter($statements, fn ($s) => str_starts_with($s, 'DROP TABLE users')));
    }

    public function test_format_value_covers_every_type(): void
    {
        $pdo = $this->mysqlLikePdo();

        $this->assertSame('NULL', MysqlDumper::formatValue($pdo, null));
        $this->assertSame('12', MysqlDumper::formatValue($pdo, 12));
        $this->assertSame('1', MysqlDumper::formatValue($pdo, true));
        $this->assertSame('0', MysqlDumper::formatValue($pdo, false));
        $this->assertSame('NULL', MysqlDumper::formatValue($pdo, NAN));
        $this->assertSame("'12.50'", MysqlDumper::formatValue($pdo, '12.50'));
        $this->assertSame("'a\\'b'", MysqlDumper::formatValue($pdo, "a'b"));
        $this->assertSame('0x616263', MysqlDumper::formatValue($pdo, 'abc', true));
        $this->assertSame("''", MysqlDumper::formatValue($pdo, '', true));
        // Un pilote qui n'échappe pas les retours à la ligne ne casse pas le format.
        $plain = new PDO('sqlite::memory:');
        $this->assertSame("'a\\nb'", MysqlDumper::formatValue($plain, "a\nb"));
    }

    public function test_it_reads_back_what_it_wrote_and_runs_the_statements(): void
    {
        $pdo = $this->mysqlLikePdo();
        $rows = ['people' => [['id' => 1, 'name' => 'A', 'note' => null, 'photo' => null, 'score' => 0.0, 'active' => true]]];
        $this->dumper($rows, $pdo)->dump($this->file);

        $restore = $this->mysqlLikePdo();
        $this->dumper([], $restore, 2, dropExisting: ['stale_table'])->restore($this->file);

        $executed = $restore->executed;
        $this->assertSame('SET NAMES utf8mb4', $executed[0]);
        $this->assertContains('DROP TABLE IF EXISTS `stale_table`', $executed, 'tables absent from the backup are dropped first');
        $this->assertContains('DROP TABLE IF EXISTS `people`', $executed);
        $this->assertNotEmpty(array_filter($executed, fn ($s) => str_starts_with($s, 'CREATE TABLE `people`')));
        $this->assertNotEmpty(array_filter($executed, fn ($s) => str_starts_with($s, 'INSERT INTO `people`')));
        $this->assertSame('SET FOREIGN_KEY_CHECKS = 1', $executed[array_key_last($executed) - 1] ?? null);
        $this->assertStringStartsWith('SET SQL_MODE', (string) end($executed), 'the previous session mode is put back');
    }

    public function test_a_truncated_dump_is_refused(): void
    {
        $this->dumper(['people' => [['id' => 1, 'name' => 'A', 'note' => null, 'photo' => null, 'score' => 0.0, 'active' => true]]], $this->mysqlLikePdo())->dump($this->file);
        $content = (string) file_get_contents($this->file);

        // Coupé au milieu de la dernière instruction, puis juste avant la ligne finale.
        foreach ([substr($content, 0, (int) strrpos($content, 'INSERT') + 20), substr($content, 0, (int) strrpos($content, '-- kintai:complete'))] as $truncated) {
            file_put_contents($this->file, $truncated);
            $restore = $this->mysqlLikePdo();

            try {
                $this->dumper([], $restore)->restore($this->file);
                $this->fail('A truncated dump must be refused.');
            } catch (BackupException $e) {
                $this->assertStringContainsString('incomplete', $e->getMessage());
            }

            $this->assertContains('SET FOREIGN_KEY_CHECKS = 1', $restore->executed, 'foreign keys are re-enabled after a failure');
        }
    }
}

/**
 * PDO (sur SQLite en mémoire) qui échappe comme `mysqli_real_escape_string` et enregistre ce qu'on lui fait exécuter.
 */
class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'".addcslashes($string, "\\'\"\0\n\r\x1a")."'";
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query("SELECT 'STRICT_TRANS_TABLES'");
    }
}

class FakeMysqlDumper extends MysqlDumper
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  list<string>  $dropExisting
     */
    public function __construct(Connection $connection, int $batch, private readonly array $rows, private readonly RecordingPdo $pdo, private readonly array $dropExisting = [])
    {
        parent::__construct($connection, $batch);
    }

    protected function pdo(): PDO
    {
        return $this->pdo;
    }

    protected function beginSnapshot(): bool
    {
        return false;
    }

    protected function tables(): array
    {
        return [...$this->dropExisting, ...array_keys($this->rows)];
    }

    protected function createStatement(string $table): string
    {
        return "CREATE TABLE `{$table}` (\n  `id` int NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB";
    }

    protected function columns(string $table): array
    {
        return [
            ['name' => 'id', 'binary' => false],
            ['name' => 'name', 'binary' => false],
            ['name' => 'note', 'binary' => false],
            ['name' => 'photo', 'binary' => true],
            ['name' => 'score', 'binary' => false],
            ['name' => 'active', 'binary' => false],
        ];
    }

    protected function primaryKey(string $table): array
    {
        return ['id'];
    }

    protected function fetchBatch(string $table, array $columns, array $order, int $limit, int $offset): array
    {
        return array_slice($this->rows[$table] ?? [], $offset, $limit);
    }
}
