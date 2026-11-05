<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Kintai\Contracts\Notifications\Notifier;
use Throwable;

/**
 * Le service `Notifier` du contrat : envoie une `KintaiNotification` aux comptes actifs et délègue le « lu » à la cloche.
 */
class UserNotifier implements Notifier
{
    public function __construct(private readonly NotificationInbox $inbox) {}

    public function notify(int|array $userIds, string $type, string $body, array $params = [], ?string $link = null, ?int $referenceId = null): void
    {
        $users = User::query()->whereIn('id', array_values(array_unique((array) $userIds)))->where('is_active', true)->get();

        foreach ($users as $user) {
            try {
                $user->notify(KintaiNotification::make($type, $body, $params, $link, $referenceId));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function markRead(Authenticatable $user, string $referenceType, int|array $referenceId): void
    {
        if ($user instanceof User) {
            $this->inbox->markReadFor($user, $referenceType, $referenceId);
        }
    }
}
