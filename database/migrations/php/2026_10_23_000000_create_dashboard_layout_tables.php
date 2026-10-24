<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Mises en page nommées d'un tableau de bord (jusqu'à 5 par utilisateur et par tableau de bord) et mise en page par
 * défaut d'un rôle, définie par l'Owner. La disposition « en cours » reste dans user_dashboard_prefs.layout : une mise
 * en page nommée en est un instantané qu'on active d'un clic, et le défaut du rôle ne sert qu'à qui n'a rien enregistré.
 *
 * Pas de clés étrangères : SQLite n'applique pas les suppressions en cascade ici, le code supprime les lignes liées.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('user_dashboard_layouts')) {
            $this->schema()->create('user_dashboard_layouts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->index();
                $table->string('dashboard_type', 20);
                $table->string('name', 60);
                $table->text('layout');
                $table->boolean('is_active')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->unique(['user_id', 'dashboard_type', 'name']);
            });
        }

        if (!$this->schema()->hasTable('dashboard_role_layouts')) {
            $this->schema()->create('dashboard_role_layouts', function (Blueprint $table) {
                $table->unsignedInteger('role_id');
                $table->string('dashboard_type', 20);
                $table->text('layout');
                $table->timestamp('updated_at')->nullable();
                $table->primary(['role_id', 'dashboard_type']);
            });
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('dashboard_role_layouts');
        $this->schema()->dropIfExists('user_dashboard_layouts');
    }
};
