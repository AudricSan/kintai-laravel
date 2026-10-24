<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\PasswordResetToken as EloquentPasswordResetToken;

final class DatabasePasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function __construct() {}

    public function create(string $email, string $token, string $expiresAt): array
    {
        $this->deleteByEmail($email);

        $record = EloquentPasswordResetToken::create([
            'email'      => $email,
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);
        return $record->toArray();
    }

    public function findByToken(string $token): ?array
    {
        $record = EloquentPasswordResetToken::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public function deleteByEmail(string $email): void
    {
        EloquentPasswordResetToken::where('email', $email)->delete();
    }

    public function deleteExpired(): void
    {
        EloquentPasswordResetToken::where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
    }
}
