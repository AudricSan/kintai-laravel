<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\CronToken as EloquentCronToken;

final class DatabaseCronTokenRepository implements CronTokenRepositoryInterface
{
    public function __construct() {}

    public function findById(int $id): ?array
    {
        $record = EloquentCronToken::find($id);
        return $record ? $record->toArray() : null;
    }

    public function findByToken(string $token): ?array
    {
        $record = EloquentCronToken::where('token', $token)->first();
        return $record ? $record->toArray() : null;
    }

    public function findByUserId(int $userId): array
    {
        return EloquentCronToken::where('user_id', $userId)->get()->toArray();
    }

    public function save(array $data): array
    {
        if (!empty($data['id'])) {
            $record = EloquentCronToken::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = EloquentCronToken::create($data);
        }
        return $record->toArray();
    }

    public function update(int $id, array $data): void
    {
        $record = EloquentCronToken::find($id);
        if ($record) {
            $record->fill($data);
            $record->save();
        }
    }

    public function delete(int $id): int
    {
        $record = EloquentCronToken::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public function touchLastUsed(int $id): void
    {
        try {
            EloquentCronToken::where('id', $id)->update(['last_used_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable) {
        }
    }
}
