<?php

declare(strict_types=1);

namespace kintai\Core\Services;

/**
 * Les détecteurs de SystemNotificationService : un par type de notification système. Chacun renvoie une alerte
 * (`key` distingue l'alerte pour l'anti-doublon, `link` est la page à ouvrir, `params` remplit la traduction) ou null
 * quand tout va bien. Séparé du service pour pouvoir tester celui-ci (anti-doublon, résolution) sans GitHub ni disque.
 */
interface SystemAlertSourceInterface
{
    /** @return array<string, callable(): ?array{key: string, link: string, params?: array}> type => détecteur */
    public function all(): array;
}
