<?php

namespace App\Notifications;

/**
 * Liens des notifications : seuls les chemins internes sont acceptés, jamais une autre origine.
 */
final class NotificationLink
{
    /**
     * Le chemin tel quel s'il est interne (commence par « / », ni « // » ni « /\ » que les navigateurs lisent comme
     * une autre origine, aucun caractère de contrôle), sinon null.
     */
    public static function internal(?string $link): ?string
    {
        if ($link === null || $link === '' || $link[0] !== '/') {
            return null;
        }

        if (str_starts_with($link, '//') || preg_match('#[\x00-\x1f\x7f\\\\]#', $link) === 1) {
            return null;
        }

        return $link;
    }
}
