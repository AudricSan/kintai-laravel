<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface AppSettingsRepositoryInterface
{
    /** Retourne tous les paramètres sous forme de map clé → valeur. */
    public function all(): array;

    /** Récupère un paramètre par clé. */
    public function get(string $key): ?string;

    /** Crée ou met à jour un paramètre. */
    public function set(string $key, string $value): void;

    /** Met à jour plusieurs paramètres en une seule opération. */
    public function setMany(array $settings): void;
}
