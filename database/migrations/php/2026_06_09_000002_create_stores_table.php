<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('stores')) {
            return;
        }
        $this->schema()->create('stores', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type')->default('retail');
            $table->string('timezone')->default('UTC');
            $table->string('currency')->default('EUR');
            $table->string('locale')->default('en');
            $table->string('address_street')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_postal')->nullable();
            $table->string('address_country')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('break_settings')->default($this->textDefault('{"min_shift_hours": 6, "break_minutes": 60}'));
            $table->text('opening_hours')->default($this->textDefault('{}'));
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('excel_import_settings')->default($this->textDefault('{"col_start":4,"col_end":52,"base_hour":6,"minutes_per_col":30,"block_size":9,"shift_rows":7,"date_row_offsets":[2,0,1,3],"sheet_filter_pattern":"^\\\\d{4}$"}'));
            $table->text('deduction_settings')->nullable();
            $table->integer('low_staff_hour_start')->default(-1);
            $table->integer('low_staff_hour_end')->default(-1);
            $table->integer('min_staff_per_day')->default(0);
            $table->integer('min_shift_minutes')->default(120);
            $table->integer('max_shift_minutes')->default(480);
            $table->text('daily_report_settings')->nullable();
            $table->integer('week_start_day')->default(1);
            $table->text('features')->nullable();

            $table->index(['is_active', 'deleted_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('stores');
    }
};
