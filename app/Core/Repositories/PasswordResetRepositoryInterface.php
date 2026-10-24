<?php

declare(strict_types=1);

namespace kintai\Core\Repositories;

interface PasswordResetRepositoryInterface
{
    public function create(string $email, string $token, string $expiresAt): array;
    public function findByToken(string $token): ?array;
    public function deleteByEmail(string $email): void;
    public function deleteExpired(): void;
}
