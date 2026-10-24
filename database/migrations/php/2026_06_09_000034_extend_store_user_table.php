<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('store_user', function (Blueprint $table) {
            $table->string('hired_by')->nullable()->after('hire_date');
        });
    }

    public function down(): void
    {
        $this->schema()->table('store_user', function (Blueprint $table) {
            $table->dropColumn('hired_by');
        });
    }
};
