<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Le salaire net peut être versé en partie en espèces (hand_delivered_salary,
 * déjà existant) et en partie par virement bancaire — ce nouveau champ couvre
 * la seconde partie, saisie manuellement, par défaut à 0 comme les autres
 * montants du rapport.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('salary_reports', 'bank_transfer_salary')) {
            return;
        }
        $this->schema()->table('salary_reports', function (Blueprint $table) {
            $table->decimal('bank_transfer_salary', 12, 2)->default(0)->after('hand_delivered_salary');
        });
    }

    public function down(): void
    {
        $this->schema()->table('salary_reports', function (Blueprint $table) {
            $table->dropColumn('bank_transfer_salary');
        });
    }
};
