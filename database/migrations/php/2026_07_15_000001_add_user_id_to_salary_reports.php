<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Rapport de salaire par employé (item 7 de task/idea.md), en plus du rapport
 * par magasin existant : `user_id`/`employee_name` nullables — NULL = rapport
 * global du magasin (comportement actuel inchangé), renseigné = rapport pour
 * cet employé uniquement.
 *
 * L'ancienne contrainte unique (store_id, target_month) empêchait plusieurs
 * rapports de salaire pour le même mois — nécessaire désormais pour
 * coexister avec le rapport global. Remplacée par (store_id, target_month,
 * user_id) ; NULL n'étant jamais égal à NULL dans un index unique (MySQL et
 * SQLite), l'unicité du rapport global par mois reste appliquée côté
 * application (AdminSalaryReportController::storeSalaryReport()), pas par
 * cette contrainte.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasColumn('salary_reports', 'user_id')) {
            $this->schema()->table('salary_reports', function (Blueprint $table) {
                $table->integer('user_id')->nullable()->after('store_id');
                $table->string('employee_name')->nullable()->after('user_id');
            });
        }

        $this->schema()->table('salary_reports', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'target_month']);
            $table->unique(['store_id', 'target_month', 'user_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('salary_reports', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'target_month', 'user_id']);
            $table->unique(['store_id', 'target_month']);
            $table->dropColumn(['user_id', 'employee_name']);
        });
    }
};
