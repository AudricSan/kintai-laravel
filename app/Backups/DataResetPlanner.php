<?php

namespace App\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que la réinitialisation des données efface. Les tables viennent du schéma réel : une table ajoutée plus tard (par un
 * bundle, par exemple) est vidée sans qu'il faille y penser, sauf si elle est déclarée préservée.
 *
 * Toujours préservées : l'identité de l'installation (comptes, rôles, droits, passkeys, fiches employé, jetons d'API,
 * réglages de l'instance, sessions), l'état technique (migrations, cache, files d'attente) et le journal d'activité.
 * Facultatives (cases « garder ») : magasins et membres, types de shift et taux, préférences d'interface.
 */
class DataResetPlanner
{
    /** Phrase à recopier pour confirmer la réinitialisation. */
    public const CONFIRMATION = 'RESET';

    /** @var list<string> */
    public const ALWAYS_PRESERVED = [
        'migrations',
        'users', 'password_reset_tokens', 'sessions', 'passkeys', 'employee_profiles',
        'roles', 'role_permissions', 'role_user',
        'personal_access_tokens',
        'app_settings',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'activity_entries',
    ];

    /** @var array<string, list<string>> */
    public const OPTIONAL = [
        'stores' => ['stores', 'store_user', 'store_features'],
        'shift_types' => ['shift_types', 'shift_type_store', 'shift_type_user'],
        'ui_prefs' => ['dashboard_layouts', 'user_nav_prefs', 'notification_preferences'],
    ];

    /**
     * Tables vidées, dans un ordre qui respecte les clés étrangères (les tables qui en référencent d'autres d'abord).
     *
     * @param  list<string>  $keep  Catégories gardées : `stores`, `shift_types`, `ui_prefs`.
     * @return list<string>
     */
    public function tablesToWipe(array $keep): array
    {
        $preserved = self::ALWAYS_PRESERVED;

        foreach ($keep as $category) {
            $preserved = [...$preserved, ...(self::OPTIONAL[$category] ?? [])];
        }

        $tables = array_values(array_diff($this->tables(), $preserved));

        return $this->order($tables);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int> Nombre de lignes de chaque table.
     */
    public function counts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Vide les tables dans une transaction, clés étrangères actives : les suppressions en cascade font disparaître avec
     * leurs magasins les rattachements de rôle propres à un magasin, et le journal garde ses lignes (magasin mis à null).
     *
     * @param  list<string>  $tables  Résultat de `tablesToWipe()`.
     */
    public function wipe(array $tables): void
    {
        DB::transaction(function () use ($tables): void {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }
        });
    }

    /**
     * Libellé lisible d'une table connue (sinon son nom).
     */
    public static function label(string $table): string
    {
        return match ($table) {
            'shifts' => __('Shifts'),
            'availabilities' => __('Availabilities'),
            'notifications' => __('Notifications'),
            'notification_preferences' => __('Notification preferences'),
            'web_push_subscriptions' => __('Web Push subscriptions'),
            'device_push_tokens' => __('Mobile push tokens'),
            'ical_tokens' => __('Calendar links'),
            'stores' => __('Stores'),
            'store_user' => __('Store members'),
            'store_features' => __('Store features'),
            'shift_types' => __('Shift types'),
            'shift_type_store' => __('Shift types per store'),
            'shift_type_user' => __('Employee hourly rates'),
            'dashboard_layouts' => __('Dashboard layouts'),
            'user_nav_prefs' => __('Menu preferences'),
            default => $table,
        };
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map(fn (array $table): string => (string) $table['name'], Schema::getTables());
    }

    /**
     * Tri topologique : une table n'est vidée qu'après celles qui la référencent.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function order(array $tables): array
    {
        $references = [];

        foreach ($tables as $table) {
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $parent = (string) $foreignKey['foreign_table'];

                if ($parent !== $table && in_array($parent, $tables, true)) {
                    $references[$table][] = $parent;
                }
            }
        }

        $ordered = [];
        $pending = $tables;

        while ($pending !== []) {
            $before = count($pending);

            foreach ($pending as $key => $table) {
                // Prête quand plus aucune table en attente ne la référence.
                $referenced = false;

                foreach ($pending as $other) {
                    if ($other !== $table && in_array($table, $references[$other] ?? [], true)) {
                        $referenced = true;
                        break;
                    }
                }

                if (! $referenced) {
                    $ordered[] = $table;
                    unset($pending[$key]);
                }
            }

            // Cycle entre tables : on garde l'ordre restant, les cascades du schéma feront le reste.
            if (count($pending) === $before) {
                array_push($ordered, ...array_values($pending));
                break;
            }
        }

        return $ordered;
    }
}
