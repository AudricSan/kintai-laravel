<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CalendarConnection extends Model
{
    protected $table = 'calendar_connections';
    protected $guarded = [];
    public $timestamps = false;

    /** La liaison d'un utilisateur avec un fournisseur d'agenda, ou null. */
    public static function ofUser(int $userId, string $provider = 'google'): ?array
    {
        return static::where('user_id', $userId)->where('provider', $provider)->first()?->toArray();
    }

    public static function asArray(int $id): ?array
    {
        return static::find($id)?->toArray();
    }

    /** @param list<int> $userIds */
    public static function activeForUsers(array $userIds, string $provider = 'google'): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($userIds === []) {
            return [];
        }

        return static::whereIn('user_id', $userIds)->where('provider', $provider)->where('status', 'active')->get()->toArray();
    }

    /** Remplace la liaison de l'utilisateur : une nouvelle liaison repart de zéro (l'ancien agenda n'est plus celui de Kintai). */
    public static function replaceFor(int $userId, array $data, string $provider = 'google'): array
    {
        $existing = static::ofUser($userId, $provider);
        if ($existing !== null) {
            static::remove((int) $existing['id']);
        }

        return static::create($data + ['user_id' => $userId, 'provider' => $provider, 'status' => 'active'])->toArray();
    }

    public static function patch(int $id, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        static::where('id', $id)->update($fields);
    }

    /** Supprime la liaison avec ses liens d'événements et sa file de synchronisation. */
    public static function remove(int $id): void
    {
        CalendarEventLink::where('connection_id', $id)->delete();
        CalendarSyncQueueItem::where('connection_id', $id)->delete();
        static::where('id', $id)->delete();
    }

    public static function removeAllFor(int $userId): void
    {
        foreach (static::where('user_id', $userId)->pluck('id') as $id) {
            static::remove((int) $id);
        }
    }

    public static function countActive(): int
    {
        return static::where('status', 'active')->count();
    }

    /** @return list<int> */
    public static function activeUserIds(string $provider = 'google'): array
    {
        return array_map('intval', static::where('provider', $provider)->where('status', 'active')->pluck('user_id')->all());
    }}
