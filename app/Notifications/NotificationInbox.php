<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * La cloche d'un utilisateur : compteur, dernières notifications, « lu » et suppression. Le « lu » automatique
 * (`markReadFor`) est ce que les écrans appellent quand l'utilisateur vient de voir l'objet désigné.
 */
class NotificationInbox
{
    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function latest(User $user, int $limit = 10): Collection
    {
        return $user->notifications()->limit($limit)->get();
    }

    /**
     * Marque comme lues les notifications non lues qui désignent ces objets. Ne lève jamais : une panne ici ne
     * doit pas empêcher d'afficher la page.
     *
     * @param  int|list<int>  $referenceId
     */
    public function markReadFor(User $user, string $referenceType, int|array $referenceId): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) $referenceId)));

        if ($ids === []) {
            return;
        }

        try {
            $user->unreadNotifications()
                ->where('data->reference_type', $referenceType)
                ->whereIn('data->reference_id', $ids)
                ->update(['read_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function markAllRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    public function deleteAll(User $user): int
    {
        return $user->notifications()->delete();
    }
}
