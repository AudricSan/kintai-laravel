<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface CronTokenRepositoryInterface
{
    public function findById(int $id): ?array;

    public function findByToken(string $token): ?array;

    public function findByUserId(int $userId): array;

    public function save(array $data): array;

    public function update(int $id, array $data): void;

    public function delete(int $id): int;

    public function touchLastUsed(int $id): void;
}
