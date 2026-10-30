<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Écart d'un utilisateur par rapport au réglage par défaut d'un type de notification, pour un canal. */
final class NotificationPreference extends Model
{
    protected $table = 'notification_preferences';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['enabled' => 'boolean'];

    /**
     * Écarts de ces utilisateurs en une requête : [utilisateur => [type => [canal => activé]]].
     *
     * @param list<int> $userIds
     * @return array<int, array<string, array<string, bool>>>
     */
    public static function deviationsFor(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $out = [];
        if ($userIds === []) {
            return $out;
        }
        foreach (self::whereIn('user_id', $userIds)->get(['user_id', 'type', 'channel', 'enabled']) as $row) {
            $out[(int) $row->user_id][(string) $row->type][(string) $row->channel] = (bool) $row->enabled;
        }

        return $out;
    }

    /** Enregistre l'écart d'un utilisateur pour un type et un canal (un seul par triplet). */
    public static function put(int $userId, string $type, string $channel, bool $enabled): void
    {
        self::updateOrCreate(['user_id' => $userId, 'type' => $type, 'channel' => $channel], ['enabled' => $enabled]);
    }
}