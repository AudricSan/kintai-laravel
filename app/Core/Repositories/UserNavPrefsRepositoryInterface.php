<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface UserNavPrefsRepositoryInterface
{
    /** Retourne les clés d'items masqués pour cet utilisateur. */
    public function getHidden(int $userId): array;

    /** Persiste la liste des items masqués. */
    public function saveHidden(int $userId, array $hidden): void;

    /** Retourne l'ordre des sections pour cet utilisateur (tableau de clés, vide = ordre par défaut). */
    public function getSectionOrder(int $userId): array;

    /** Persiste l'ordre des sections. */
    public function saveSectionOrder(int $userId, array $order): void;

    /** Retourne les clés des items de la barre de navigation inférieure (vide = défaut du rôle). */
    public function getBottomNavItems(int $userId): array;

    /** Persiste les items de la barre de navigation inférieure. */
    public function saveBottomNavItems(int $userId, array $items): void;
}
