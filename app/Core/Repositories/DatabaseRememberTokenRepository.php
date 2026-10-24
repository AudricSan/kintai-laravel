<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\RememberToken as EloquentRememberToken;

final class DatabaseRememberTokenRepository implements RememberTokenRepositoryInterface
{
    public function __construct() {}

    public function findBySelector(string $selector): ?array
    {
        $record = EloquentRememberToken::where('selector', $selector)->first();
        return $record ? $record->toArray() : null;
    }

    public function create(array $data): array
    {
        $record = EloquentRememberToken::create($data);
        return $record->toArray();
    }

    public function deleteBySelector(string $selector): void
    {
        EloquentRememberToken::where('selector', $selector)->delete();
    }

    public function deleteByUserId(int $userId): void
    {
        EloquentRememberToken::where('user_id', $userId)->delete();
    }

    public function deleteExpired(): void
    {
        EloquentRememberToken::where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
    }
}
