<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Contexte technique auto-détecté à la soumission, pour aider un manager/owner
 * à trier un signalement de bug sans devoir redemander la page, la version et
 * l'appareil à l'employé : voir FeedbackController::submit().
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('employee_feedbacks', 'page_path')) {
            return;
        }
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->string('page_path', 255)->nullable()->after('anonymous');
            $table->string('app_version', 30)->nullable()->after('page_path');
            $table->string('device_type', 20)->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('employee_feedbacks', 'page_path')) {
            return;
        }
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropColumn(['page_path', 'app_version', 'device_type']);
        });
    }
};
