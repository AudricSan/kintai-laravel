<?php

namespace Kintai\Bundles\Messaging;

use App\Authorization\Authorizer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Kintai\Bundles\Messaging\Models\MessageThread;
use Kintai\Bundles\Messaging\Models\ThreadParticipant;
use Kintai\Contracts\Stores\Colleague;
use Kintai\Contracts\Stores\ColleagueDirectory;

/**
 * Qui lit et écrit à qui. Trois permissions du Core, avec sa portée magasin : `messaging.view` (lire ses conversations,
 * les lister, les retirer de sa liste, supprimer ses messages, compteur de non-lus), `messaging.send` (répondre, ajouter
 * un participant) et `messaging.create` (ouvrir une conversation). Une permission ne donne jamais accès à la conversation
 * d'autrui : la règle suivante s'y ajoute.
 *
 * Confidentialité stricte : un utilisateur ne lit que les conversations dont il est participant, quel que soit son rôle
 * (aucun droit, pas même celui de l'Owner, ne donne accès aux messages d'un tiers). Les destinataires possibles sont les
 * collègues que `ColleagueDirectory` du Core montre à l'expéditeur dans un magasin où la fonctionnalité `messages` est
 * activée.
 */
class MessagingAccess
{
    public function __construct(private readonly ColleagueDirectory $directory, private readonly Authorizer $authorizer) {}

    /**
     * Permission détenue pour au moins un magasin (ou pour tous).
     */
    public function can(User $user, string $permission): bool
    {
        return $this->authorizer->allows($user, $permission);
    }

    /**
     * Permission détenue pour ce magasin.
     */
    public function canIn(User $user, string $permission, int $storeId): bool
    {
        return $this->authorizer->allows($user, $permission, $storeId);
    }

    /**
     * Le droit de lire les conversations du magasin de cette conversation (en plus d'y participer).
     */
    public function canView(User $user, MessageThread $thread): bool
    {
        return $this->canIn($user, 'messaging.view', $thread->store_id);
    }

    /**
     * Magasins où l'utilisateur peut ouvrir une conversation : ceux dont il voit l'équipe, avec la fonctionnalité activée
     * et la permission `messaging.create`.
     *
     * @return array<int, string> identifiant => nom
     */
    public function composeStores(User $user): array
    {
        $ids = $this->authorizer->storeIdsFor($user, 'messaging.create');
        $stores = $this->directoryStores($user);

        return $ids === null ? $stores : array_intersect_key($stores, array_flip($ids));
    }

    /**
     * Magasins que l'annuaire du Core ouvre à la messagerie pour l'utilisateur, sans tenir compte de ses permissions.
     *
     * @return array<int, string>
     */
    private function directoryStores(User $user): array
    {
        return $this->directory->stores($user, MessagingServiceProvider::FEATURE);
    }

    /**
     * Le magasin accepte la messagerie de l'utilisateur (visible, fonctionnalité activée), sans tenir compte de ses permissions.
     */
    public function canWriteIn(User $user, int $storeId): bool
    {
        return isset($this->directoryStores($user)[$storeId]);
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
     * La messagerie a un sens pour l'utilisateur : il peut la lire (`messaging.view`) et soit écrire quelque part, soit
     * avoir déjà des conversations.
     */
    public function canUse(User $user): bool
    {
        return $this->can($user, 'messaging.view')
            && ($this->composeStores($user) !== [] || ThreadParticipant::query()->where('user_id', $user->id)->exists());
    }

    public function participation(User $user, int $threadId): ?ThreadParticipant
    {
        return ThreadParticipant::query()->where('thread_id', $threadId)->where('user_id', $user->id)->first();
    }

    /**
     * La conversation, seulement si l'utilisateur y participe (null sinon, sans distinguer « absente » de « étrangère »).
     * La permission `messaging.view` se vérifie à part (`canView()`).
     */
    public function thread(User $user, int $threadId): ?MessageThread
    {
        return MessageThread::query()
            ->whereKey($threadId)
            ->whereHas('participants', fn (Builder $q) => $q->where('user_id', $user->id))
            ->first();
    }

    /**
     * Conversations de la liste de l'utilisateur (hors celles qu'il a masquées), les plus récentes d'abord, limitées aux
     * magasins où il a `messaging.view`.
     *
     * @param  string  $tab  `inbox`, `sent` (qu'il a ouvertes) ou `unread`.
     * @return Builder<MessageThread>
     */
    public function listing(User $user, string $tab = 'inbox'): Builder
    {
        $ids = $this->authorizer->storeIdsFor($user, 'messaging.view');

        return MessageThread::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('store_id', $ids ?? []))
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
     * Conversations non lues de l'utilisateur (hors masquées), dans les magasins où il a `messaging.view` : 0 sans cette
     * permission.
     */
    public function unreadCount(User $user): int
    {
        return $this->listing($user, 'unread')->count();
    }

    /**
     * Peut-il encore écrire dans cette conversation ? Il faut `messaging.send` pour son magasin et qu'il puisse y écrire
     * (membre, fonctionnalité activée).
     */
    public function canReply(User $user, MessageThread $thread): bool
    {
        return $this->canIn($user, 'messaging.send', $thread->store_id) && $this->canWriteIn($user, $thread->store_id);
    }
}
