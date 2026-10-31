<?php

declare(strict_types=1);

namespace Tests\Bundles\Messaging\Controllers\Api;

use kintai\Bundles\Installed\Messaging\Controllers\Api\MessageController;
use Illuminate\Support\Facades\DB;
use Tests\Support\MessagingRows;
use App\Exceptions\ForbiddenException;
use App\Models\StoreUser;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Régression (audit du 03/10/2026), repris du Core à l'extraction : les créations de l'API fusionnaient le JSON brut du
 * client dans save(), qui fait un upsert dès qu'un « id » est présent. Un POST portant un « id » écrasait donc le
 * message ou la participation de quelqu'un d'autre.
 *
 * L'appelant (id 2) est membre du magasin 5 ; le magasin 6 ne le concerne pas.
 */
final class MessageMassAssignmentTest extends TestCase
{
    use MessagingRows;

    private const ME       = 2;
    private const MY_STORE = 5;
    private const OTHER    = 6;

    protected function setUp(): void
    {
        kintai_test_store_users(findByUser: fn() => [['store_id' => self::MY_STORE]], findMembership: fn(int $storeId, int $userId): ?array => $storeId === self::MY_STORE && in_array($userId, [self::ME, 3], true)
                ? ['store_id' => $storeId, 'user_id' => $userId]
                : null);
    }

    private function request(array $json, array $params = []): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => self::ME]);
        $req->setRouteParams($params);
        kintai_test_set_json($req, $json);
        return $req;
    }

    public function testMessageCreationIgnoresIdSoItCannotHijackAnotherThreadsMessage(): void
    {
        $this->resetMessaging();
        $this->thread(10, self::MY_STORE);
        $this->participant(10, self::ME);
        $this->message(55, 10, 1, 'original');
        $this->nextRowId(77);

        (new MessageController())
            ->addMessage($this->request(['id' => 55, 'thread_id' => 99, 'sender_id' => 1, 'body' => 'bonjour'], ['id' => '10']));

        $this->assertSame('original', DB::table('thread_messages')->where('id', 55)->value('body'), 'le message 55 est intact');
        $created = DB::table('thread_messages')->where('body', 'bonjour')->first();
        $this->assertNotNull($created);
        $this->assertSame(10, (int) $created->thread_id);
        $this->assertSame(self::ME, (int) $created->sender_id);
    }

    public function testParticipantCreationIgnoresIdAndOtherFields(): void
    {
        $this->resetMessaging();
        $this->thread(10, self::MY_STORE);
        $this->participant(10, self::ME);

        (new MessageController())
            ->addParticipant($this->request(['id' => 9, 'thread_id' => 99, 'user_id' => 3, 'is_read' => 1], ['id' => '10']));

        $rows = DB::table('thread_participants')->orderBy('id')->get();
        $created = $rows->last();
        $this->assertSame(10, (int) $created->thread_id);
        $this->assertSame(0, (int) $created->is_read, 'is_read n\'est pas imposé par le client');
        $this->assertNotSame(9, (int) $created->id);
    }

    public function testThreadCreationInAStoreTheCallerDoesNotBelongToIsForbidden(): void
    {
        $this->resetMessaging();

        try {
            (new MessageController())->createThread($this->request(['store_id' => self::OTHER, 'subject' => 's']));
            $this->fail('ForbiddenException attendue');
        } catch (ForbiddenException) {
            $this->assertSame(0, DB::table('message_threads')->count());
        }
    }
}
