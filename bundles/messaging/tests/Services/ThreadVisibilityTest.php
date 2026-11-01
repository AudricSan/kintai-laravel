<?php

declare(strict_types=1);

namespace Tests\Bundles\Messaging\Services;

use Illuminate\Database\Connection;
use kintai\Bundles\Installed\Messaging\Models\MessageThread;
use kintai\Bundles\Installed\Messaging\Models\ThreadMessage;
use kintai\Bundles\Installed\Messaging\Models\ThreadParticipant;
use kintai\Bundles\Installed\Messaging\Services\ThreadVisibility;
use Tests\Support\BundleMigrationHarness;
use PHPUnit\Framework\TestCase;

/**
 * Supprimer une conversation la masque pour soi seul (audit n°8 de Kintai, D5) — avant, n'importe quel participant
 * l'effaçait pour tous. Testé sur les vraies tables migrées.
 */
final class ThreadVisibilityTest extends TestCase
{
    /** Ces tests posent leurs propres tables : le test suivant repart d'une base neuve. */
    protected function tearDown(): void
    {
        kintai_test_database();
    }

    private int $threadId;

    protected function setUp(): void
    {
        $capsule = kintai_test_database();
        $runner = new BundleMigrationHarness();
        $runner->runPendingFor('messaging', dirname(__DIR__, 2) . '/database/migrations');

        $this->threadId = (int) MessageThread::put(['store_id' => 1, 'creator_id' => 3, 'subject' => 'Planning'])['id'];
        ThreadMessage::put(['thread_id' => $this->threadId, 'sender_id' => 3, 'body' => 'Bonjour', 'sent_at' => '2026-10-15 09:00:00']);
        ThreadParticipant::put(['thread_id' => $this->threadId, 'user_id' => 3, 'is_read' => 1]);
        ThreadParticipant::put(['thread_id' => $this->threadId, 'user_id' => 4, 'is_read' => 0]);
    }

    private function visibleFor(int $userId): bool
    {
        foreach (ThreadParticipant::findByUser($userId) as $p) {
            if ((int) $p['thread_id'] === $this->threadId) {
                return ThreadVisibility::isVisible($p);
            }
        }
        return false;
    }

    public function testDeletingHidesTheConversationForTheCallerOnly(): void
    {
        $this->assertFalse((new ThreadVisibility())->hideFor($this->threadId, 3));

        $this->assertFalse($this->visibleFor(3), 'retirée de la liste du créateur');
        $this->assertTrue($this->visibleFor(4), 'toujours là pour l\'autre participant');
        $this->assertCount(1, ThreadMessage::findByThread($this->threadId), 'messages conservés');
        $this->assertSame(1, ThreadParticipant::countUnread(4));
    }

    public function testANewMessageBringsItBack(): void
    {
        (new ThreadVisibility())->hideFor($this->threadId, 3);

        ThreadParticipant::put(ThreadVisibility::reveal(ThreadParticipant::findByThreadAndUser($this->threadId, 3)));

        $this->assertTrue($this->visibleFor(3));
        $this->assertSame(1, ThreadParticipant::countUnread(3));
    }

    public function testTheConversationIsErasedOnceNobodySeesIt(): void
    {
        $visibility = new ThreadVisibility();
        $visibility->hideFor($this->threadId, 4);

        $this->assertTrue($visibility->hideFor($this->threadId, 3));
        $this->assertNull(MessageThread::findById($this->threadId));
        $this->assertSame([], ThreadMessage::findByThread($this->threadId));
    }

    public function testAHiddenUnreadConversationNoLongerCountsAsUnread(): void
    {
        (new ThreadVisibility())->hideFor($this->threadId, 4);

        $this->assertSame(0, ThreadParticipant::countUnread(4));
    }
}
