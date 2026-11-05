<?php

namespace Kintai\Bundles\Messaging;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;

/**
 * Qui lit et écrit à qui. Confidentialité stricte : un utilisateur ne lit que les conversations dont il est participant,
 * quel que soit son rôle (aucun droit, pas même celui de l'Owner, ne donne accès aux messages d'un tiers). La messagerie
 * est un libre-service : les destinataires possibles sont les collègues que `ColleagueDirectory` du Core montre à
 * l'expéditeur dans un magasin où la fonctionnalité `messages` est activée.
 */
class MessagingAccess
{
    public function __construct(private readonly ColleagueDirectory $directory) {}

    /**
     * Magasins où l'utilisateur peut écrire : ceux dont il voit l'équipe, avec la fonctionnalité activée.
     *
     * @return array<int, string> identifiant => nom
     */
    public function composeStores(User $user): array
    {
        return $this->directory->stores($user, MessagingServiceProvider::FEATURE);
    }

    public function canWriteIn(User $user, int $storeId): bool
    {
        return isset($this->composeStores($user)[$storeId]);
    }

    /**
     * Collègues à qui l'utilisateur peut écrire dans ce magasin (aucun s'il ne peut pas y écrire).
     *
     * @return list<Colleague>
     */
    public function recipients(User $user, int $storeId): array
    {
        return $this->canWriteIn($user, $storeId) ? $this->directory->colleagues($user, [$storeId]) : [];
    }

    /**
     * La messagerie a un sens pour l'utilisateur : il peut écrire quelque part, ou il a déjà des conversations.
     */
    public function canUse(User $user): bool
    {
        return $this->composeStores($user) !== [] || ThreadParticipant::query()->where('user_id', $user->id)->exists();
    }

    public function participation(User $user, int $threadId): ?ThreadParticipant
    {
        return ThreadParticipant::query()->where('thread_id', $threadId)->where('user_id', $user->id)->first();
    }

    /**
     * La conversation, seulement si l'utilisateur y participe (null sinon, sans distinguer « absente » de « étrangère »).
     */
    public function thread(User $user, int $threadId): ?MessageThread
    {
        return MessageThread::query()
            ->whereKey($threadId)
            ->whereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id))
            ->first();
    }

    /**
     * Conversations de la liste de l'utilisateur (hors celles qu'il a masquées), les plus récentes d'abord.
     *
     * @param  string  $tab  `inbox`, `sent` (qu'il a ouvertes) ou `unread`.
     * @return Builder<MessageThread>
     */
    public function listing(User $user, string $tab = 'inbox'): Builder
    {
        return MessageThread::query()
            ->whereHas('participants', function (Builder $q) use ($user, $tab): void {
                $q->where('user_id', $user->id)->whereNull('hidden_at');

                if ($tab === 'unread') {
                    $q->where('is_read', false);
                }
            })
            ->when($tab === 'sent', fn (Builder $q) => $q->where('creator_id', $user->id))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * Conversations non lues de l'utilisateur (hors masquées).
     */
    public function unreadCount(User $user): int
    {
        return ThreadParticipant::query()->where('user_id', $user->id)->where('is_read', false)->whereNull('hidden_at')->count();
    }

    /**
     * Peut-il encore écrire dans cette conversation ? Il faut qu'il puisse écrire dans son magasin (membre, fonctionnalité
     * activée).
     */
    public function canReply(User $user, MessageThread $thread): bool
    {
        return $this->canWriteIn($user, $thread->store_id);
    }
}
