<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->string('furigana')->nullable()->after('display_name');
            $table->string('gender')->nullable()->after('furigana');
            $table->string('tax_classification')->nullable()->after('gender');
            $table->date('birth_date')->nullable()->after('tax_classification');
            $table->string('education')->nullable()->after('birth_date');
            $table->string('postal_code')->nullable()->after('education');
            $table->text('address')->nullable()->after('postal_code');
            $table->string('mobile_phone')->nullable()->after('phone');
            $table->string('guarantor_name')->nullable()->after('mobile_phone');
            $table->string('guarantor_phone')->nullable()->after('guarantor_name');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', function (Blueprint $table) {
            $table->dropColumn([
                'furigana', 'gender', 'tax_classification', 'birth_date',
                'education', 'postal_code', 'address', 'mobile_phone',
                'guarantor_name', 'guarantor_phone',
            ]);
        });
    }
};
