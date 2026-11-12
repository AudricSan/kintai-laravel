<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Report;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2g (messagerie) : fils, participants et messages. */
class LegacyMessagingMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('messaging');
        $this->legacy = new SyntheticSource;
        $this->storage = sys_get_temp_dir().'/kintai-target-'.bin2hex(random_bytes(6));
        mkdir($this->storage, 0777, true);
        $this->seedLegacy();
    }

    protected function tearDown(): void
    {
        $this->legacy->cleanup();
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $row */
    private function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        $this->legacy->pdo->prepare('INSERT INTO "'.$table.'" ('.implode(', ', array_map(fn (string $c): string => '"'.$c.'"', $columns)).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')')
            ->execute(array_values($row));
    }

    private function seedLegacy(): void
    {
        $at = '2026-03-02 09:00:00'; // heure locale (Asia/Tokyo) : 00:00:00 en UTC
        $this->insert('message_threads', ['id' => 1, 'store_id' => 1, 'creator_id' => 2, 'subject' => 'Planning', 'type' => 'direct', 'last_message_at' => '2026-03-02 18:00:00', 'created_at' => $at, 'updated_at' => $at]);
        $this->insert('message_threads', ['id' => 2, 'store_id' => 1, 'creator_id' => 3, 'subject' => null, 'created_at' => $at]);
        $this->insert('message_threads', ['id' => 3, 'store_id' => 99, 'creator_id' => 2, 'subject' => 'Magasin absent']);
        $this->insert('message_threads', ['id' => 4, 'store_id' => 1, 'creator_id' => 99, 'subject' => 'Créateur absent']);
        $this->insert('message_threads', ['id' => 5, 'store_id' => 2, 'creator_id' => 1, 'subject' => str_repeat('x', 256)]);
        $this->insert('message_threads', ['id' => 6, 'store_id' => 1, 'creator_id' => 3, 'subject' => 'Vide']);

        $this->insert('thread_participants', ['id' => 1, 'thread_id' => 1, 'user_id' => 2, 'is_read' => 1, 'last_read_at' => '2026-03-02 10:00:00', 'created_at' => $at]);
        $this->insert('thread_participants', ['id' => 2, 'thread_id' => 1, 'user_id' => 3, 'is_read' => 0, 'created_at' => $at]);
        $this->insert('thread_participants', ['id' => 3, 'thread_id' => 1, 'user_id' => 3, 'is_read' => 1, 'created_at' => $at]);
        $this->insert('thread_participants', ['id' => 4, 'thread_id' => 1, 'user_id' => 99, 'is_read' => 0]);
        $this->insert('thread_participants', ['id' => 5, 'thread_id' => 3, 'user_id' => 2, 'is_read' => 0]);
        $this->insert('thread_participants', ['id' => 6, 'thread_id' => 2, 'user_id' => 3, 'is_read' => 0]);

        $this->insert('thread_messages', ['id' => 1, 'thread_id' => 1, 'sender_id' => 2, 'body' => 'Bonjour', 'created_at' => $at]);
        $this->insert('thread_messages', ['id' => 2, 'thread_id' => 1, 'sender_id' => 3, 'body' => 'Réponse', 'created_at' => '2026-03-02 09:30:00']);
        $this->insert('thread_messages', ['id' => 3, 'thread_id' => 1, 'sender_id' => 99, 'body' => 'Expéditeur absent', 'created_at' => $at]);
        $this->insert('thread_messages', ['id' => 4, 'thread_id' => 3, 'sender_id' => 2, 'body' => 'Fil absent', 'created_at' => $at]);
        $this->insert('thread_messages', ['id' => 5, 'thread_id' => 1, 'sender_id' => 2, 'body' => '  ', 'created_at' => $at]);
        $this->insert('thread_messages', ['id' => 6, 'thread_id' => 1, 'sender_id' => 2, 'body' => 'Sans date', 'created_at' => null]);
    }

    private function migrate(?Source $source = null, bool $dryRun = false): Report
    {
        $target = new Target(DB::connection()->getPdo(), 'sqlite', $this->storage);

        return (new Importer($source ?? $this->legacy->source(), $target, 'Asia/Tokyo', $dryRun))->run();
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        return DB::table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function total(): int
    {
        return DB::table('message_threads')->count() + DB::table('thread_participants')->count() + DB::table('thread_messages')->count();
    }

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    public function test_threads_keep_their_ids_and_an_empty_subject_when_there_is_none(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 6], $this->ids('message_threads'));
        $one = DB::table('message_threads')->find(1);
        $this->assertSame(1, (int) $one->store_id);
        $this->assertSame(2, (int) $one->creator_id);
        $this->assertSame('Planning', $one->subject);
        $this->assertSame('2026-03-02 09:00:00', $one->last_message_at);
        $this->assertSame('2026-03-02 00:00:00', $one->created_at);
        $two = DB::table('message_threads')->find(2);
        $this->assertSame('', $two->subject);
        $this->assertNull($two->last_message_at);
        // Un fil sans participant ni message est repris tel quel.
        $this->assertSame('Vide', DB::table('message_threads')->find(6)->subject);

        $this->assertSame(6, $report->count('message_threads', 'lues'));
        $this->assertSame(3, $report->count('message_threads'));
        $this->assertSame(3, $report->count('message_threads', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'fil 5 au sujet trop long'));
    }

    public function test_participants_and_messages_drop_orphans_duplicates_and_unreadable_rows(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 6], $this->ids('thread_participants'));
        $first = DB::table('thread_participants')->find(1);
        $this->assertSame(1, (int) $first->is_read);
        $this->assertSame('2026-03-02 01:00:00', $first->last_read_at);
        $this->assertNull($first->hidden_at);
        $this->assertSame(0, (int) DB::table('thread_participants')->find(2)->is_read); // le doublon (id 3) est ignoré
        $this->assertNull(DB::table('thread_participants')->find(2)->last_read_at);
        $this->assertSame(6, $report->count('thread_participants', 'lues'));
        $this->assertSame(3, $report->count('thread_participants', 'ignorees'));

        $this->assertSame([1, 2], $this->ids('thread_messages'));
        $message = DB::table('thread_messages')->find(1);
        $this->assertSame('Bonjour', $message->body);
        $this->assertSame('2026-03-02 00:00:00', $message->sent_at);
        $this->assertNull($message->erased_at);
        $this->assertSame('2026-03-02 00:30:00', DB::table('thread_messages')->find(2)->sent_at);
        $this->assertSame(6, $report->count('thread_messages', 'lues'));
        $this->assertSame(4, $report->count('thread_messages', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'message 6 sans texte ou à la date illisible'));
    }

    public function test_source_without_the_messaging_tables_skips_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, $this->total());
        foreach (['message_threads', 'thread_participants', 'thread_messages'] as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
            $this->assertArrayNotHasKey($table, $report->tables);
        }
    }

    public function test_messaging_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "thread_messages" DROP COLUMN "body"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('thread_messages.body', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note(): void
    {
        Schema::drop('thread_messages');
        Schema::drop('thread_participants');
        Schema::drop('message_threads');

        $report = $this->migrate();

        $this->assertSame(6, $report->count('message_threads', 'ignorees'));
        $this->assertSame(6, $report->count('thread_messages', 'ignorees'));
        $this->assertSame(0, $report->count('message_threads'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'message_threads : table absente de la cible')));
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, $this->total());
        $this->assertSame(3, $report->count('message_threads'));
        $this->assertSame(3, $report->count('thread_participants'));
        $this->assertSame(2, $report->count('thread_messages'));
    }
}
