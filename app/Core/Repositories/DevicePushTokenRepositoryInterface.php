<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface DevicePushTokenRepositoryInterface
{
    public function findByUser(int $userId): array;

    public function findByToken(string $token): ?array;

    /** Upsert par token (un jeton FCM peut migrer d'un utilisateur à l'autre sur un appareil partagé). */
    public function save(array $data): array;

    /**
     * @param int|null $userId Si fourni, ne supprime que le jeton de CET utilisateur : la désinscription
     *                         d'un utilisateur ne doit pas retirer l'appareil d'un autre compte.
     */
    public function deleteByToken(string $token, ?int $userId = null): int;

    public function touchLastUsed(int $id): void;
}
