<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Lignes réelles de la messagerie (fils, participants, messages) pour les tests du bundle messaging : à la place des
 * dépôts simulés. S'appuie sur RealTable (table message_threads) et complète les colonnes obligatoires omises.
 */
trait MessagingRows
{
    use RealTable;

    private function realTable(): array
    {
        return ['message_threads', 'messaging'];
    }

    /** Base neuve des trois tables de la messagerie. */
    private function resetMessaging(): void
    {
        $this->resetRows();
        DB::table('thread_messages')->delete();
        DB::table('thread_participants')->delete();
    }

    private function thread(int $id, int $storeId = 3, int $creatorId = 1, array $more = []): void
    {
        DB::table('message_threads')->insert($this->completed('message_threads', ['id' => $id, 'store_id' => $storeId, 'creator_id' => $creatorId] + $more));
    }

    private function participant(int $threadId, int $userId, array $more = []): void
    {
        DB::table('thread_participants')->insert($this->completed('thread_participants', ['thread_id' => $threadId, 'user_id' => $userId] + $more));
    }

    private function message(int $id, int $threadId, int $senderId, string $body = 'x'): void
    {
        DB::table('thread_messages')->insert($this->completed('thread_messages', ['id' => $id, 'thread_id' => $threadId, 'sender_id' => $senderId, 'body' => $body]));
    }
}
