<?php

declare(strict_types=1);

namespace Tests\Bundles\Messaging\Controllers\Api;

use App\Exceptions\ForbiddenException;
use App\Exceptions\NotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use kintai\Bundles\Installed\Messaging\Controllers\Api\MessageController;
use PHPUnit\Framework\TestCase;
use Tests\Support\MessagingRows;

/**
 * Régression : ApiMessageController n'avait aucune vérification d'appartenance
 * malgré .wiki/API-Reference.md documentant messages.* comme "strictly scoped
 * to the token holder" — n'importe quel token valide pouvait lire/supprimer
 * les threads et messages de n'importe quel autre utilisateur.
 *
 * Les tests tournent sur les vraies tables message_threads, thread_messages et thread_participants.
 */
final class MessageControllerTest extends TestCase
{
    use MessagingRows;

    private ?MessageController $controller = null;

    protected function setUp(): void
    {
        $this->resetMessaging();
    }

    private function controller(): MessageController
    {
        return $this->controller ??= new MessageController();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = [];
    }

    private function requestAs(int $userId, array $json = []): Request
    {
        $req = Request::capture();
        $req->attributes->set('auth_user', ['id' => $userId]);
        if ($json !== []) {
            kintai_test_set_json($req, $json);
        }
        return $req;
    }

    // ── listThreads ──────────────────────────────────────────────────────────

    public function testListThreadsAlwaysUsesTokenHolderRegardlessOfUserIdQuery(): void
    {
        $this->thread(10);
        $this->thread(11);
        $this->participant(10, 42);
        $this->participant(11, 999);
        $_GET = ['user_id' => '999'];

        $response = $this->controller()->listThreads($this->requestAs(42));

        $this->assertSame(200, $response->getStatusCode());
        $threads = json_decode($response->getContent(), true);
        $threads = $threads['data'] ?? $threads;
        $this->assertSame([10], array_map(fn(array $t): int => (int) $t['id'], $threads), 'seuls les fils du porteur du jeton');
    }

    // ── createThread ─────────────────────────────────────────────────────────

    public function testCreateThreadForcesCreatorIdFromTokenAndAddsSelfAsParticipant(): void
    {
        // L'appelant est membre du magasin 3, comme le participant ajouté.
        kintai_test_store_users(findByUser: fn() => [['store_id' => 3]], findMembership: fn() => ['store_id' => 3, 'user_id' => 42]);
        $req = $this->requestAs(42, [
            'store_id'         => 3,
            'subject'          => 'Sujet',
            'creator_id'       => 999, // tentative de spoof, doit être ignorée
            'participant_ids'  => [7, 42], // 42 (soi-même) ne doit pas être dupliqué
        ]);

        $response = $this->controller()->createThread($req);

        $this->assertSame(201, $response->getStatusCode());
        $thread = DB::table('message_threads')->first();
        $this->assertSame(42, (int) $thread->creator_id);
        $this->assertSame(3, (int) $thread->store_id);
        $this->assertSame([7, 42], DB::table('thread_participants')->orderBy('user_id')->pluck('user_id')->map(fn($u) => (int) $u)->all());
    }

    // ── getThread ─────────────────────────────────────────────────────────────

    public function testGetThreadReturns404ForNonParticipant(): void
    {
        $this->thread(10);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10']);

        $this->expectException(NotFoundException::class);
        $this->controller()->getThread($req);
    }

    public function testGetThreadReturns404ForUnknownThread(): void
    {
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '999']);

        $this->expectException(NotFoundException::class);
        $this->controller()->getThread($req);
    }

    public function testGetThreadSucceedsForParticipant(): void
    {
        $this->thread(10);
        $this->participant(10, 42);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10']);

        $response = $this->controller()->getThread($req);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── deleteThread ─────────────────────────────────────────────────────────

    public function testDeleteThreadRejectsNonParticipant(): void
    {
        $this->thread(10);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10']);

        try {
            $this->controller()->deleteThread($req);
            $this->fail('NotFoundException attendue');
        } catch (NotFoundException) {
            $this->assertSame(1, DB::table('message_threads')->count(), 'le fil est conservé');
        }
    }

    // ── listMessages / addMessage ───────────────────────────────────────────

    public function testListMessagesRejectsNonParticipant(): void
    {
        $this->thread(10);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10']);

        $this->expectException(NotFoundException::class);
        $this->controller()->listMessages($req);
    }

    public function testAddMessageForcesSenderIdFromToken(): void
    {
        $this->thread(10);
        $this->participant(10, 42);
        $req = $this->requestAs(42, ['body' => 'salut', 'sender_id' => 999]);
        $req->setRouteParams(['id' => '10']);

        $response = $this->controller()->addMessage($req);

        $this->assertSame(201, $response->getStatusCode());
        $message = DB::table('thread_messages')->first();
        $this->assertSame(42, (int) $message->sender_id);
        $this->assertSame(10, (int) $message->thread_id);
    }

    // ── deleteMessage ────────────────────────────────────────────────────────

    public function testDeleteMessageRejectsNonSender(): void
    {
        $this->thread(10);
        $this->message(7, 10, 999);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '7']);

        try {
            $this->controller()->deleteMessage($req);
            $this->fail('ForbiddenException attendue');
        } catch (ForbiddenException) {
            $this->assertSame(1, DB::table('thread_messages')->count(), 'le message est conservé');
        }
    }

    public function testDeleteMessageSucceedsForSender(): void
    {
        $this->thread(10);
        $this->message(7, 10, 42);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '7']);

        $response = $this->controller()->deleteMessage($req);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(0, DB::table('thread_messages')->count());
    }

    // ── participants ─────────────────────────────────────────────────────────

    public function testListParticipantsRejectsNonParticipant(): void
    {
        $this->thread(10);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10']);

        $this->expectException(NotFoundException::class);
        $this->controller()->listParticipants($req);
    }

    public function testAddParticipantRejectsNonParticipant(): void
    {
        $this->thread(10);
        $req = $this->requestAs(42, ['user_id' => 7]);
        $req->setRouteParams(['id' => '10']);

        try {
            $this->controller()->addParticipant($req);
            $this->fail('NotFoundException attendue');
        } catch (NotFoundException) {
            $this->assertSame(0, DB::table('thread_participants')->count(), 'personne n\'est ajouté');
        }
    }

    public function testGetParticipantRejectsCallerNotInThread(): void
    {
        $this->thread(10);
        $this->participant(10, 7);
        $req = $this->requestAs(42);
        $req->setRouteParams(['id' => '10', 'user_id' => '7']);

        $this->expectException(NotFoundException::class);
        $this->controller()->getParticipant($req);
    }
}
