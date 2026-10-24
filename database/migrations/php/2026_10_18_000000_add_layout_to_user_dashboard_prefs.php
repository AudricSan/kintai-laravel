<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Widgets du tableau de bord déplaçables : l'ordre et la largeur choisis par chaque utilisateur sont enregistrés dans
 * user_dashboard_prefs.layout (voir kintai\Core\Extension\DashboardLayout). Une installation neuve a déjà la colonne
 * (migration de création de la table) ; celle-ci l'ajoute aux instances existantes.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('user_dashboard_prefs', 'layout')) {
            return;
        }
        $this->schema()->table('user_dashboard_prefs', function (Blueprint $table) {
            $table->text('layout')->nullable();
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('user_dashboard_prefs', 'layout')) {
            return;
        }
        $this->schema()->table('user_dashboard_prefs', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
