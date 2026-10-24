<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface RememberTokenRepositoryInterface
{
    public function findBySelector(string $selector): ?array;

    public function create(array $data): array;

    public function deleteBySelector(string $selector): void;

    public function deleteByUserId(int $userId): void;

    public function deleteExpired(): void;
}
