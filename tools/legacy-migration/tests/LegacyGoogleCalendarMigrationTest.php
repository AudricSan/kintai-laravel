<?php

declare(strict_types=1);

namespace KintaiLegacy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;
use KintaiLegacy\AppEncrypter;
use KintaiLegacy\Importer;
use KintaiLegacy\MigrationException;
use KintaiLegacy\Report;
use KintaiLegacy\SecretBox;
use KintaiLegacy\Source;
use KintaiLegacy\Target;
use KintaiLegacy\Tests\Support\SyntheticSource;
use Tests\Concerns\InstallsBundles;
use Tests\TestCase;

require_once dirname(__DIR__).'/autoload.php';
require_once __DIR__.'/Support/SyntheticSource.php';

/** Tranche 2e (calendrier Google) : liaisons (jeton rechiffré), liens d'événements et file de synchronisation. */
class LegacyGoogleCalendarMigrationTest extends TestCase
{
    use InstallsBundles;
    use RefreshDatabase;

    private const SECRET = 'secret-d-instance-synthetique-0123456789';

    private const OTHER_SECRET = 'un-tout-autre-secret-synthetique-9876543210';

    private const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private SyntheticSource $legacy;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableBundle('google-calendar');
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

    /** Chiffre comme l'ancien `SecretBox` (implémentation indépendante de celle de l'outil). */
    private function legacyEncrypt(string $plain, string $secret): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', 'kintai-secretbox-v1|'.$secret, true), OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return 'enc1:'.base64_encode($iv.$tag.$cipher);
    }

    private function tampered(string $stored): string
    {
        $raw = (string) base64_decode(substr($stored, 5), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1); // un bit du texte chiffré

        return 'enc1:'.base64_encode($raw);
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
        $this->insert('app_settings', ['key' => 'instance_secret', 'value' => self::SECRET]);
        $valid = $this->legacyEncrypt('rt-owner-1', self::SECRET);
        $at = '2026-03-02 09:00:00'; // heure locale (Asia/Tokyo) : 00:00:00 en UTC

        $this->insert('calendar_connections', ['id' => 1, 'user_id' => 1, 'provider' => 'google', 'account_email' => 'owner@example.test', 'refresh_token' => $valid,
            'calendar_id' => 'cal-1@group.calendar.google.com', 'calendar_name' => 'Kintai Boutique A', 'status' => 'active', 'last_sync_at' => $at, 'last_error' => null,
            'options' => '{"calendar_name_mode":"store","store_id":1,"reconciled_at":1772409600}', 'created_at' => $at, 'updated_at' => $at]);
        $this->insert('calendar_connections', ['id' => 2, 'user_id' => 2, 'provider' => 'google', 'refresh_token' => 'rt-plain-2', 'status' => 'weird',
            'last_error' => 'invalid_grant', 'options' => '{"calendar_name_mode":"store","store_id":99}']);
        $this->insert('calendar_connections', ['id' => 3, 'user_id' => 3, 'provider' => 'google', 'refresh_token' => $this->tampered($this->legacyEncrypt('rt-tampered', self::SECRET)), 'status' => 'active']);
        $this->insert('calendar_connections', ['id' => 4, 'user_id' => 4, 'provider' => 'google', 'refresh_token' => 'enc2:'.base64_encode(str_repeat('x', 40)), 'status' => 'active']);
        $this->insert('calendar_connections', ['id' => 5, 'user_id' => 99, 'provider' => 'google', 'refresh_token' => $valid, 'status' => 'active']);
        $this->insert('calendar_connections', ['id' => 6, 'user_id' => 1, 'provider' => 'google', 'refresh_token' => $valid, 'status' => 'active']);
        $this->insert('calendar_connections', ['id' => 7, 'user_id' => 3, 'provider' => 'outlook', 'refresh_token' => $valid, 'status' => 'active']);
        $this->insert('calendar_connections', ['id' => 8, 'user_id' => 3, 'provider' => 'google', 'refresh_token' => $this->legacyEncrypt('rt-autre', self::OTHER_SECRET), 'status' => 'active']);

        $this->insert('calendar_event_links', ['id' => 1, 'connection_id' => 1, 'source_type' => 'shift', 'source_id' => 1, 'external_event_id' => 'evt-1', 'content_hash' => self::HASH, 'synced_at' => $at]);
        $this->insert('calendar_event_links', ['id' => 2, 'connection_id' => 1, 'source_type' => 'timeoff', 'source_id' => 7, 'external_event_id' => 'evt-2', 'content_hash' => strtoupper(self::HASH)]);
        $this->insert('calendar_event_links', ['id' => 3, 'connection_id' => 1, 'source_type' => 'shift', 'source_id' => 1, 'external_event_id' => 'evt-3', 'content_hash' => self::HASH]);
        $this->insert('calendar_event_links', ['id' => 4, 'connection_id' => 3, 'source_type' => 'shift', 'source_id' => 1, 'external_event_id' => 'evt-4', 'content_hash' => self::HASH]);
        $this->insert('calendar_event_links', ['id' => 5, 'connection_id' => 1, 'source_type' => 'absence', 'source_id' => 2, 'external_event_id' => 'evt-5', 'content_hash' => self::HASH]);
        $this->insert('calendar_event_links', ['id' => 6, 'connection_id' => 2, 'source_type' => 'shift', 'source_id' => 2, 'external_event_id' => 'evt-6', 'content_hash' => 'court']);
        $this->insert('calendar_event_links', ['id' => 7, 'connection_id' => 2, 'source_type' => 'shift', 'source_id' => 3, 'external_event_id' => 'evt-7', 'content_hash' => self::HASH]);

        $this->insert('calendar_sync_queue', ['id' => 1, 'connection_id' => 1, 'source_type' => 'shift', 'source_id' => 2, 'attempts' => 3, 'next_attempt_at' => $at, 'last_error' => 'rate limit', 'created_at' => $at]);
        $this->insert('calendar_sync_queue', ['id' => 2, 'connection_id' => 3, 'source_type' => 'shift', 'source_id' => 1, 'attempts' => 0]);
        $this->insert('calendar_sync_queue', ['id' => 3, 'connection_id' => 1, 'source_type' => 'shift', 'source_id' => 2, 'attempts' => 0]);
        $this->insert('calendar_sync_queue', ['id' => 4, 'connection_id' => 2, 'source_type' => 'timeoff', 'source_id' => 5, 'attempts' => 'abc']);
    }

    private function migrate(?Source $source = null, bool $dryRun = false, ?string $appKey = 'default'): Report
    {
        $target = new Target(DB::connection()->getPdo(), 'sqlite', $this->storage, $appKey === 'default' ? (string) config('app.key') : $appKey);

        return (new Importer($source ?? $this->legacy->source(), $target, 'Asia/Tokyo', $dryRun))->run();
    }

    /** @return list<int> */
    private function ids(string $table): array
    {
        return DB::table($table)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function total(): int
    {
        return DB::table('google_calendar_connections')->count() + DB::table('google_calendar_event_links')->count() + DB::table('google_calendar_sync_queue')->count();
    }

    private function hasWarning(Report $report, string $needle): bool
    {
        return (bool) array_filter($report->warnings, fn (string $n): bool => str_contains($n, $needle));
    }

    public function test_connections_keep_their_ids_and_tokens_are_re_encrypted_with_the_target_key(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2], $this->ids('google_calendar_connections'));
        $one = CalendarConnection::query()->findOrFail(1);
        $this->assertSame('rt-owner-1', $one->decryptedRefreshToken());
        $this->assertSame('owner@example.test', $one->account_email);
        $this->assertSame('cal-1@group.calendar.google.com', $one->calendar_id);
        $this->assertSame('Kintai Boutique A', $one->calendar_name);
        $this->assertSame('active', $one->status);
        $this->assertSame('store', $one->name_mode);
        $this->assertSame(1, $one->store_id);
        $this->assertSame('2026-03-02 00:00:00', $one->last_sync_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 00:00:00', $one->reconciled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02 00:00:00', $one->created_at?->format('Y-m-d H:i:s'));

        // Jamais en clair en base ; la valeur sans préfixe (en clair dans l'ancienne version) est chiffrée elle aussi.
        $raw = (string) DB::table('google_calendar_connections')->where('id', 1)->value('refresh_token');
        $this->assertStringNotContainsString('rt-owner-1', $raw);
        $this->assertSame('rt-owner-1', Crypt::decryptString($raw));
        $this->assertSame('rt-plain-2', CalendarConnection::query()->findOrFail(2)->decryptedRefreshToken());

        // Statut inconnu : révoquée ; magasin absent : nom de l'organisation.
        $two = CalendarConnection::query()->findOrFail(2);
        $this->assertSame('revoked', $two->status);
        $this->assertSame('instance', $two->name_mode);
        $this->assertNull($two->store_id);
        $this->assertSame('invalid_grant', $two->last_error);
        $this->assertTrue($this->hasWarning($report, 'magasin absent de la cible'));
        $this->assertTrue($this->hasWarning($report, 'statut inconnu'));

        $this->assertSame(8, $report->count('google_calendar_connections', 'lues'));
        $this->assertSame(2, $report->count('google_calendar_connections'));
        $this->assertSame(6, $report->count('google_calendar_connections', 'ignorees'));
    }

    public function test_unreadable_tokens_other_providers_orphans_and_duplicates_are_not_migrated(): void
    {
        $report = $this->migrate();

        // 3 altéré, 4 préfixe inconnu, 8 autre secret : jeton illisible ; 5 employé absent ; 6 doublon ; 7 autre fournisseur.
        foreach ([3, 4, 8] as $id) {
            $this->assertTrue($this->hasWarning($report, "liaison {$id} (employé"), (string) $id);
        }
        $this->assertTrue($this->hasWarning($report, 'reconnecter son agenda Google'));
        $this->assertTrue($this->hasWarning($report, 'liaison 6 en double'));
        $this->assertTrue($this->hasWarning($report, 'liaison 7 d\'un autre fournisseur'));
        $this->assertSame([1, 2], $this->ids('google_calendar_connections'));
    }

    public function test_neither_the_tokens_nor_the_secret_appear_in_the_report(): void
    {
        $report = $this->migrate();
        $text = $report->render();

        foreach (['rt-owner-1', 'rt-plain-2', 'rt-tampered', 'rt-autre', self::SECRET, self::OTHER_SECRET, 'enc1:', 'enc2:'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, $forbidden);
        }
        $this->assertSame(1, preg_match('/^\s*'.preg_quote('google_calendar_connections', '/').'/m', $text));
    }

    public function test_links_and_queue_follow_their_connection_and_become_source_keys(): void
    {
        $report = $this->migrate();

        $this->assertSame([1, 2, 7], $this->ids('google_calendar_event_links'));
        $shift = DB::table('google_calendar_event_links')->find(1);
        $this->assertSame(1, (int) $shift->connection_id);
        $this->assertSame('shift', $shift->source_type);
        $this->assertSame('1', $shift->source_key);
        $this->assertSame('evt-1', $shift->external_event_id);
        $this->assertSame(self::HASH, $shift->content_hash);
        $this->assertSame('2026-03-02 00:00:00', $shift->synced_at);
        $timeoff = DB::table('google_calendar_event_links')->find(2);
        $this->assertSame('timeoff', $timeoff->source_type);
        $this->assertSame('timeoff-7', $timeoff->source_key);
        $this->assertSame(self::HASH, $timeoff->content_hash); // empreinte mise en minuscules
        $this->assertNull($timeoff->synced_at);
        $this->assertSame(7, $report->count('google_calendar_event_links', 'lues'));
        $this->assertSame(4, $report->count('google_calendar_event_links', 'ignorees'));
        $this->assertTrue($this->hasWarning($report, 'type de source inconnu de la cible'));
        $this->assertTrue($this->hasWarning($report, 'ligne 3 en double'));
        $this->assertTrue($this->hasWarning($report, 'ligne 6 à l\'empreinte'));

        $this->assertSame([1, 4], $this->ids('google_calendar_sync_queue'));
        $first = DB::table('google_calendar_sync_queue')->find(1);
        $this->assertSame('2', $first->source_key);
        $this->assertSame(3, (int) $first->attempts);
        $this->assertSame('2026-03-02 00:00:00', $first->next_attempt_at);
        $this->assertSame('rate limit', $first->last_error);
        $four = DB::table('google_calendar_sync_queue')->find(4);
        $this->assertSame('timeoff-5', $four->source_key);
        $this->assertSame(0, (int) $four->attempts);
        $this->assertSame(4, $report->count('google_calendar_sync_queue', 'lues'));
        $this->assertSame(2, $report->count('google_calendar_sync_queue', 'ignorees'));
    }

    public function test_absent_instance_secret_keeps_plain_tokens_and_refuses_encrypted_ones(): void
    {
        $this->legacy->pdo->exec('DELETE FROM "app_settings" WHERE "key" = \'instance_secret\'');

        $report = $this->migrate();

        $this->assertSame([2], $this->ids('google_calendar_connections'));
        $this->assertTrue($this->hasWarning($report, "liaison 8 (employé 3) non reprise, jeton illisible (secret d'instance absent ou trop court)"));
        // Les liens de la liaison non reprise suivent : 1 et 2 appartenaient à la liaison 1.
        $this->assertSame([7], $this->ids('google_calendar_event_links'));
    }

    public function test_source_secret_box_decrypts_valid_plain_and_refuses_everything_else(): void
    {
        $valid = $this->legacyEncrypt('un-jeton-synthetique', self::SECRET);

        $this->assertSame('un-jeton-synthetique', SecretBox::decrypt($valid, self::SECRET));
        $this->assertSame('en-clair', SecretBox::decrypt('en-clair', self::SECRET));
        $this->assertSame('en-clair', SecretBox::decrypt('en-clair', null)); // le clair n'a pas besoin du secret
        $this->assertNull(SecretBox::decrypt($this->tampered($valid), self::SECRET)); // GCM : contenu altéré
        $this->assertNull(SecretBox::decrypt($valid, self::OTHER_SECRET));
        $this->assertNull(SecretBox::decrypt($valid, null));
        $this->assertNull(SecretBox::decrypt($valid, 'trop-court'));
        $this->assertNull(SecretBox::decrypt('enc2:'.substr($valid, 5), self::SECRET)); // préfixe inconnu
        $this->assertNull(SecretBox::decrypt('enc1:!!pas du base64!!', self::SECRET));
        $this->assertNull(SecretBox::decrypt('enc1:'.base64_encode('trop court'), self::SECRET));
    }

    public function test_app_encrypter_output_is_read_by_the_framework(): void
    {
        $encrypter = new AppEncrypter((string) config('app.key'));
        $stored = $encrypter->encryptString('un-jeton-synthetique');

        $this->assertSame('un-jeton-synthetique', Crypt::decryptString($stored));
        $this->assertNotSame($stored, $encrypter->encryptString('un-jeton-synthetique')); // IV aléatoire

        $this->expectException(MigrationException::class);
        new AppEncrypter('base64:'.base64_encode('trop court'));
    }

    public function test_target_without_an_app_key_is_refused_and_nothing_is_written(): void
    {
        try {
            $this->migrate(appKey: null);
            $this->fail('La cible sans APP_KEY aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, $this->total());
    }

    public function test_source_without_the_calendar_tables_skips_with_a_note(): void
    {
        $legacy = new SyntheticSource(bundles: false);
        try {
            $report = $this->migrate($legacy->source());
        } finally {
            $legacy->cleanup();
        }

        $this->assertSame(0, $this->total());
        foreach (['calendar_connections', 'calendar_event_links', 'calendar_sync_queue'] as $table) {
            $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_starts_with($n, $table.' : absente de la source')), $table);
        }
    }

    public function test_calendar_table_with_missing_columns_is_refused(): void
    {
        $this->legacy->pdo->exec('ALTER TABLE "calendar_connections" DROP COLUMN "refresh_token"');

        try {
            $this->migrate();
            $this->fail('La source aurait dû être refusée.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('calendar_connections.refresh_token', $e->getMessage());
        }
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_target_without_the_bundle_tables_skips_with_a_note(): void
    {
        Schema::drop('google_calendar_sync_queue');
        Schema::drop('google_calendar_event_links');
        Schema::drop('google_calendar_connections');

        $report = $this->migrate();

        $this->assertSame(8, $report->count('google_calendar_connections', 'ignorees'));
        $this->assertSame(0, $report->count('google_calendar_connections'));
        $this->assertTrue((bool) array_filter($report->notes, fn (string $n): bool => str_contains($n, 'google_calendar_connections : table absente de la cible')));
        $this->assertSame(5, DB::table('users')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $report = $this->migrate(dryRun: true);

        $this->assertSame(0, $this->total());
        $this->assertSame(2, $report->count('google_calendar_connections'));
        $this->assertSame(3, $report->count('google_calendar_event_links'));
        $this->assertSame(2, $report->count('google_calendar_sync_queue'));
    }
}
