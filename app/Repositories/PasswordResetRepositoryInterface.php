<?php

declare(strict_types=1);

namespace App\Repositories;

interface PasswordResetRepositoryInterface
{
    public function create(string $email, string $token, string $expiresAt): array;
    public function findByToken(string $token): ?array;
    public function deleteByEmail(string $email): void;
    public function deleteExpired(): void;
}
