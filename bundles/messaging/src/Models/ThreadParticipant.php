<?php
declare(strict_types=1);

namespace kintai\Bundles\Installed\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Repris du Core à l'étape 4 du plan du contrat (extraction) : la table garde son nom et ses données.
 */
final class ThreadParticipant extends Model
{
    protected $table = 'thread_participants';
    protected $guarded = [];
    public $timestamps = false;

    public static function findById(int $id): ?array
    {
        $record = self::find($id);
        return $record ? $record->toArray() : null;
    }

    public static function put(array $data): array
    {
        if (!empty($data['id'])) {
            $record = self::findOrFail((int) $data['id']);
            $record->fill($data);
            $record->save();
        } else {
            $record = self::create($data);
        }
        return $record->toArray();
    }

    public static function remove(int $id): int
    {
        $record = self::find($id);
        return $record ? ($record->delete() ? 1 : 0) : 0;
    }

    public static function findByThread(int $threadId): array
    {
        return self::where('thread_id', $threadId)->get()->toArray();
    }

    public static function removeByThread(int $threadId): int
    {
        return self::where('thread_id', $threadId)->delete();
    }

    public static function findByThreadAndUser(int $threadId, int $userId): ?array
    {
        $record = self::where('thread_id', $threadId)
            ->where('user_id', $userId)
            ->first();
        return $record ? $record->toArray() : null;
    }

    public static function findByUser(int $userId): array
    {
        return self::where('user_id', $userId)->get()->toArray();
    }

    public static function countUnread(int $userId): int
    {
        return self::where('user_id', $userId)
            ->where('is_read', 0)
            ->whereNull('hidden_at')
            ->count();
    }
}
