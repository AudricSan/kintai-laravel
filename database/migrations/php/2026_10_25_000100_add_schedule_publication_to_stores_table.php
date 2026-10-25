<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Publication du planning par magasin (voir SchedulePublication) : schedule_publication l'active, et
 * schedule_published_until est le dernier jour visible des employés. Désactivée par défaut : rien ne change pour les
 * magasins existants tant que le manager ne l'active pas.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('stores', 'schedule_publication')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->boolean('schedule_publication')->default(false);
            $table->date('schedule_published_until')->nullable();
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('stores', 'schedule_publication')) {
            return;
        }
        $this->schema()->table('stores', function (Blueprint $table) {
            $table->dropColumn(['schedule_publication', 'schedule_published_until']);
        });
    }
};
