<?php

declare(strict_types=1);

namespace kintai\Core\Database;

use kintai\Core\Auth\PasswordHasher;
use kintai\Domain\Eloquent\Role;
use kintai\Domain\Eloquent\RoleAssignment;
use kintai\Domain\Eloquent\Shift;
use kintai\Domain\Eloquent\ShiftType;
use kintai\Domain\Eloquent\ShiftTypeStore;
use kintai\Domain\Eloquent\Store;
use kintai\Domain\Eloquent\StoreUser;
use kintai\Domain\Eloquent\User;

/**
 * Données de démonstration du Core : 2 magasins, 3 types de shift, 1 gérant par magasin, 4 employés
 * (dont un présent dans les deux magasins) et 14 jours de planning.
 *
 * Écrit par les modèles Eloquent (donc indépendant du pilote SQLite/MySQL) et ne touche qu'aux tables
 * du Core : les données propres à un bundle sont du ressort du bundle. Remplace les anciens fichiers
 * SQL de database/seeds/, restés figés sur le schéma des premières versions.
 *
 * À appeler après les migrations (les rôles Owner / Manager / Employee viennent d'une migration).
 */
final class DemoDataSeeder
{
    /** Mot de passe de tous les comptes de démonstration (≥ 8 caractères : valable aussi pour l'API). */
    public const PASSWORD = 'Demo1234!';

    private const STORES = [
        ['name' => 'Kintai Shibuya',  'code' => 'SHB'],
        ['name' => 'Kintai Shinjuku', 'code' => 'SJK'],
    ];

    private const SHIFT_TYPES = [
        ['name' => 'Matin',   'code' => 'AM',  'start' => '08:00', 'end' => '16:00', 'rate' => 1100.0, 'color' => '#10B981'],
        ['name' => 'Soir',    'code' => 'PM',  'start' => '16:00', 'end' => '23:00', 'rate' => 1200.0, 'color' => '#6366F1'],
        ['name' => 'Journée', 'code' => 'DAY', 'start' => '09:00', 'end' => '18:00', 'rate' => 1150.0, 'color' => '#F59E0B'],
    ];

    /** [prénom, nom, e-mail, rôle, index des magasins] */
    private const PEOPLE = [
        ['Alice', 'Martin',  'alice.martin@kintai.local',  'manager',  [0]],
        ['Bruno', 'Sato',    'bruno.sato@kintai.local',    'manager',  [1]],
        ['Chloé', 'Dupont',  'chloe.dupont@kintai.local',  'employee', [0]],
        ['David', 'Tanaka',  'david.tanaka@kintai.local',  'employee', [0]],
        ['Emma',  'Suzuki',  'emma.suzuki@kintai.local',   'employee', [1]],
        ['Hugo',  'Moreau',  'hugo.moreau@kintai.local',   'employee', [0, 1]],
    ];

    /**
     * Comptes créés par run(), affichés par l'installateur à la fin (tous avec le mot de passe PASSWORD).
     *
     * @return list<array{name:string, email:string, role:string}>
     */
    public static function accounts(): array
    {
        return array_map(
            static fn (array $p): array => ['name' => $p[0] . ' ' . $p[1], 'email' => $p[2], 'role' => $p[3]],
            self::PEOPLE,
        );
    }

    /** @return array{stores:int,shift_types:int,users:int,shifts:int} Nombre de lignes créées */
    public function run(): array
    {
        $now = date('Y-m-d H:i:s');

        $roleIds = [];
        foreach (['manager', 'employee'] as $slug) {
            $role = Role::where('slug', $slug)->first();
            if ($role === null) {
                throw new \RuntimeException("Rôle « {$slug} » introuvable : les migrations ont-elles été jouées ?");
            }
            $roleIds[$slug] = (int) $role->id;
        }

        $storeIds = [];
        foreach (self::STORES as $def) {
            $existing = Store::where('code', $def['code'])->first();
            $storeIds[] = (int) ($existing?->id ?? Store::create([
                'name' => $def['name'], 'code' => $def['code'], 'type' => 'store', 'currency' => 'JPY',
                'timezone' => 'Asia/Tokyo', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ])->id);
        }

        $typeIds = [];
        foreach (self::SHIFT_TYPES as $def) {
            $type = ShiftType::where('code', $def['code'])->first() ?? ShiftType::create([
                'name' => $def['name'], 'code' => $def['code'], 'start_time' => $def['start'], 'end_time' => $def['end'],
                'hourly_rate' => $def['rate'], 'color' => $def['color'], 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $typeIds[$def['code']] = (int) $type->id;
            foreach ($storeIds as $storeId) {
                ShiftTypeStore::firstOrCreate(['shift_type_id' => (int) $type->id, 'store_id' => $storeId]);
            }
        }

        $hash = PasswordHasher::hash(self::PASSWORD);
        $employeesByStore = [];
        $createdUsers = 0;
        foreach (self::PEOPLE as $i => [$first, $last, $email, $roleSlug, $storeIdx]) {
            $user = User::where('email', $email)->first();
            if ($user === null) {
                $user = User::create([
                    'email' => $email, 'password_hash' => $hash, 'first_name' => $first, 'last_name' => $last,
                    'furigana_first_name' => $first, 'furigana_last_name' => $last,
                    'display_name' => $first . ' ' . $last, 'color' => ['#6366F1', '#10B981', '#F59E0B', '#EF4444', '#3B82F6', '#8B5CF6'][$i % 6],
                    'employee_code' => sprintf('%03d', 100 + $i), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $createdUsers++;
            }
            foreach ($storeIdx as $idx) {
                $storeId = $storeIds[$idx];
                StoreUser::firstOrCreate(
                    ['store_id' => $storeId, 'user_id' => (int) $user->id],
                    ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
                );
                RoleAssignment::firstOrCreate(
                    ['user_id' => (int) $user->id, 'scope_type' => 'store', 'scope_id' => $storeId],
                    ['role_id' => $roleIds[$roleSlug], 'created_at' => $now],
                );
                if ($roleSlug === 'employee') {
                    $employeesByStore[$storeId][] = (int) $user->id;
                }
            }
        }

        $createdShifts = 0;
        $codes = array_keys($typeIds);
        for ($day = 0; $day < 14; $day++) {
            $date = date('Y-m-d', strtotime("+{$day} days"));
            if ((int) date('N', strtotime($date)) >= 6) {
                continue; // pas de planning le week-end dans la démo
            }
            foreach ($employeesByStore as $storeId => $userIds) {
                foreach ($userIds as $n => $userId) {
                    $def = self::SHIFT_TYPES[($day + $n) % count($codes)];
                    $exists = Shift::where('store_id', $storeId)->where('user_id', $userId)->where('shift_date', $date)->exists();
                    if ($exists) {
                        continue;
                    }
                    $minutes = (int) ((strtotime($date . ' ' . $def['end']) - strtotime($date . ' ' . $def['start'])) / 60);
                    Shift::create([
                        'store_id' => $storeId, 'user_id' => $userId, 'shift_date' => $date,
                        'start_time' => $def['start'], 'end_time' => $def['end'], 'shift_type_id' => $typeIds[$def['code']],
                        'pause_minutes' => 60, 'duration_minutes' => $minutes, 'net_minutes_override' => null,
                        'starts_at' => $date . ' ' . $def['start'] . ':00', 'ends_at' => $date . ' ' . $def['end'] . ':00',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $createdShifts++;
                }
            }
        }

        return ['stores' => count($storeIds), 'shift_types' => count($typeIds), 'users' => $createdUsers, 'shifts' => $createdShifts];
    }
}
