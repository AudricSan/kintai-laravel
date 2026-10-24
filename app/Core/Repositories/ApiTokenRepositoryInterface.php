<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface ApiTokenRepositoryInterface
{
    public function findById(int $id): ?array;

    public function findByToken(string $token): ?array;

    public function findByUserId(int $userId): array;

    public function save(array $data): array;

    public function delete(int $id): int;

    public function deleteByUserId(int $userId): int;
}
