<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Permet à un admin de corriger le taux horaire et/ou les minutes actives d'un
 * shift précis sans changer ses horaires — utilisé par ShiftWageCalculator::costOf()
 * en priorité sur la résolution automatique (taux perso/type de shift, durée-pause).
 * NULL = pas d'ajustement, comportement automatique inchangé.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('shifts', 'hourly_rate_override')) {
            return;
        }
        $this->schema()->table('shifts', function (Blueprint $table) {
            $table->decimal('hourly_rate_override', 10, 2)->nullable()->after('estimated_salary');
            $table->integer('net_minutes_override')->nullable()->after('hourly_rate_override');
        });
    }

    public function down(): void
    {
        $this->schema()->table('shifts', function (Blueprint $table) {
            $table->dropColumn(['hourly_rate_override', 'net_minutes_override']);
        });
    }
};
