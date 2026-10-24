<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * shift_id manquait de la migration initiale alors que DatabaseFeedbackRepository::findByShift()
 * et FeedbackController s'appuient dessus pour appliquer la contrainte "un feedback par shift".
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasColumn('employee_feedbacks', 'shift_id')) {
            return;
        }
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->integer('shift_id')->nullable()->after('store_id');
            $table->unique('shift_id');
            $table->foreign('shift_id')->references('id')->on('shifts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (!$this->schema()->hasColumn('employee_feedbacks', 'shift_id')) {
            return;
        }
        $this->schema()->table('employee_feedbacks', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropUnique(['shift_id']);
            $table->dropColumn('shift_id');
        });
    }
};
