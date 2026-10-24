<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;

/**
 * Les préférences de widgets dashboard (user_dashboard_prefs.widgets) sont une
 * liste blanche : seule une clé présente dans le JSON sauvegardé s'affiche,
 * tout le reste est masqué. Plusieurs refontes du dashboard ont renommé/retiré
 * des clés de widgets (ex. l'ancien dashboard employé utilisait des clés comme
 * "timeclocks"/"timeoff"/"open_shifts", remplacées depuis par
 * "timeclock"/"monthly_stats"/...) sans jamais migrer les lignes existantes.
 * Résultat : tout utilisateur ayant personnalisé son dashboard avant une de ces
 * refontes se retrouve avec des widgets actuels (dont le widget salaire estimé
 * "monthly_stats" côté employé) silencieusement masqués pour toujours, sans
 * aucun moyen de deviner qu'ils existent.
 * On supprime les lignes contenant au moins une clé inconnue du catalogue actuel :
 * elles ont été sauvegardées sous un ancien schéma et ne reflètent plus une
 * intention fiable. Sans ligne, getEnabledWidgets() retourne null et
 * les contrôleurs retombent sur le jeu de widgets par défaut (tout activé).
 */
return new class($this->capsule) extends Migration {
    private const CURRENT_WIDGETS = [
        'admin' => [
            'kpi_counters', 'quick_nav', 'store_stats_summary', 'shifts_today',
            'pending_timeoff', 'pending_swaps', 'timeclocks_today',
        ],
        'employee' => [
            'timeclock', 'shifts_today', 'upcoming', 'monthly_stats',
            'pending_timeoff', 'pending_swaps',
        ],
    ];

    public function up(): void
    {
        $conn = $this->capsule->getConnection();

        if (!$conn->getSchemaBuilder()->hasTable('user_dashboard_prefs')) {
            return;
        }

        $staleIds = [];
        foreach ($conn->table('user_dashboard_prefs')->get(['id', 'dashboard_type', 'widgets']) as $row) {
            $valid   = self::CURRENT_WIDGETS[$row->dashboard_type] ?? null;
            $decoded = json_decode($row->widgets ?? '[]', true);
            if ($valid === null || !is_array($decoded)) {
                continue;
            }
            if (array_diff($decoded, $valid) !== []) {
                $staleIds[] = $row->id;
            }
        }

        if ($staleIds !== []) {
            $conn->table('user_dashboard_prefs')->whereIn('id', $staleIds)->delete();
        }
    }

    public function down(): void
    {
        // Nettoyage de données : pas de rollback (les lignes supprimées étaient
        // de toute façon obsolètes, les recréer n'aurait pas de sens).
    }
};
