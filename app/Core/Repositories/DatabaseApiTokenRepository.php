<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\ApiToken as EloquentApiToken;

final class DatabaseApiTokenRepository implements ApiTokenRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $record = EloquentApiToken::find($id);
        return $record ? $record->toArray() : null;
    }

    public function findByToken(string $token): ?array
    {
        $record = EloquentApiToken::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public function findByUserId(int $userId): array
    {
        return EloquentApiToken::where('user_id', $userId)->get()->toArray();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $record = EloquentApiToken::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = EloquentApiToken::create($data);
        }
        return $record->toArray();
    }

    public function delete(int $id): int
    {
        $record = EloquentApiToken::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public function deleteByUserId(int $userId): int
    {
        return EloquentApiToken::where('user_id', $userId)->delete();
    }
}
