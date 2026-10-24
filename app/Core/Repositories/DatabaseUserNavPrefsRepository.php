<?php
declare(strict_types=1);

namespace kintai\Core\Repositories;

use kintai\Domain\Eloquent\UserNavPref as EloquentUserNavPref;

final class DatabaseUserNavPrefsRepository implements UserNavPrefsRepositoryInterface
{
    public function __construct() {}

    public function getHidden(int $userId): array
    {
        $record = EloquentUserNavPref::where('user_id', $userId)->first();
        if ($record === null) {
            return [];
        }
        $decoded = json_decode($record['hidden_items'] ?? '[]', true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveHidden(int $userId, array $hidden): void
    {
        $data = [
            'hidden_items' => json_encode(array_values($hidden)),
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        EloquentUserNavPref::updateOrCreate(['user_id' => $userId], $data);
    }

    public function getSectionOrder(int $userId): array
    {
        $record = EloquentUserNavPref::where('user_id', $userId)->first();
        if ($record === null || empty($record['section_order'])) {
            return [];
        }
        $decoded = json_decode($record['section_order'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveSectionOrder(int $userId, array $order): void
    {
        $data = [
            'section_order' => json_encode(array_values($order)),
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        EloquentUserNavPref::updateOrCreate(['user_id' => $userId], $data);
    }

    public function getBottomNavItems(int $userId): array
    {
        $record = EloquentUserNavPref::where('user_id', $userId)->first();
        if ($record === null || empty($record['bottom_nav_items'])) {
            return [];
        }
        $decoded = json_decode($record['bottom_nav_items'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function saveBottomNavItems(int $userId, array $items): void
    {
        $data = [
            'bottom_nav_items' => json_encode(array_values($items)),
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        EloquentUserNavPref::updateOrCreate(['user_id' => $userId], $data);
    }
}
