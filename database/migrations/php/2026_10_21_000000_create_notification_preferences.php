<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Préférences de notification : une ligne par écart avec les valeurs par défaut du type (NotificationType::$push /
 * $email), jamais plus. L'absence de ligne signifie « valeur par défaut » ; la cloche (in-app) n'est pas
 * désactivable et n'a donc pas de ligne.
 *
 * Les heures silencieuses sont deux colonnes de users (HH:MM, fuseau de l'instance) ; la plage peut passer minuit.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if (!$this->schema()->hasTable('notification_preferences')) {
            $this->schema()->create('notification_preferences', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('type', 64);
                $table->string('channel', 16);
                $table->boolean('enabled');

                $table->unique(['user_id', 'type', 'channel']);
            });
        }

        foreach (['quiet_hours_start', 'quiet_hours_end'] as $column) {
            if (!$this->schema()->hasColumn('users', $column)) {
                $this->schema()->table('users', function (Blueprint $table) use ($column) {
                    $table->string($column, 5)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notification_preferences');
        foreach (['quiet_hours_start', 'quiet_hours_end'] as $column) {
            if ($this->schema()->hasColumn('users', $column)) {
                $this->schema()->table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
