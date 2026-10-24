<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->string('furigana_last_name')->nullable()->after('furigana');
            $table->string('furigana_first_name')->nullable()->after('furigana_last_name');
        });

        $this->schema()->table('hiring_reports', function (Blueprint $table) {
            $table->string('furigana_last_name')->nullable()->after('furigana');
            $table->string('furigana_first_name')->nullable()->after('furigana_last_name');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->dropColumn(['furigana_last_name', 'furigana_first_name']);
        });

        $this->schema()->table('hiring_reports', function (Blueprint $table) {
            $table->dropColumn(['furigana_last_name', 'furigana_first_name']);
        });
    }
};
