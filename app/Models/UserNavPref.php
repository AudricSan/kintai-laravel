<?php
declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserNavPref extends Model
{
    protected $table = 'user_nav_prefs';
    protected $guarded = [];
    public $timestamps = false;

    public static function getHiddenRow(int $userId): array
    {
        $record = static::where('user_id', $userId)->first();
        if ($record === null) {
            return [];
        }
        $decoded = json_decode($record['hidden_items'] ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function saveHidden(int $userId, array $hidden): void
    {
        $data = [
            'hidden_items' => json_encode(array_values($hidden)),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        static::updateOrCreate(['user_id' => $userId], $data);
    }

    public static function getSectionOrder(int $userId): array
    {
        $record = static::where('user_id', $userId)->first();
        if ($record === null || empty($record['section_order'])) {
            return [];
        }
        $decoded = json_decode($record['section_order'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function saveSectionOrder(int $userId, array $order): void
    {
        $data = [
            'section_order' => json_encode(array_values($order)),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        static::updateOrCreate(['user_id' => $userId], $data);
    }

    public static function getBottomNavItems(int $userId): array
    {
        $record = static::where('user_id', $userId)->first();
        if ($record === null || empty($record['bottom_nav_items'])) {
            return [];
        }
        $decoded = json_decode($record['bottom_nav_items'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function saveBottomNavItems(int $userId, array $items): void
    {
        $data = [
            'bottom_nav_items' => json_encode(array_values($items)),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        static::updateOrCreate(['user_id' => $userId], $data);
    }
}
