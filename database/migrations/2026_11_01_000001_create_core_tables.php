<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Utilisateurs, magasins, types de shifts, shifts, disponibilités et réglages par magasin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('email')->unique();
            $table->string('password_hash');
            // Jeton « rester connecté » du garde de session de Laravel.
            $table->rememberToken();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('display_name');
            $table->string('phone')->nullable();
            $table->string('color')->default('#3B82F6');
            $table->integer('is_active')->default(1);
            $table->timestamp('last_login_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->string('employee_code')->nullable()->unique();
            $table->string('language')->default('');
            $table->string('gender')->nullable();
            $table->string('tax_classification')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('education')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('address')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone')->nullable();
            $table->string('furigana_last_name')->nullable();
            $table->string('furigana_first_name')->nullable();
            $table->string('avatar_path')->nullable();
            $table->text('bio')->nullable();
            $table->text('skills')->nullable();
            $table->text('languages_spoken')->nullable();
            $table->text('hobbies')->nullable();
            $table->boolean('show_in_directory')->default(true);
            $table->boolean('share_email')->default(false);
            $table->boolean('share_phone')->default(false);
            $table->boolean('share_mobile_phone')->default(false);
            $table->string('quiet_hours_start', 5)->nullable();
            $table->string('quiet_hours_end', 5)->nullable();

            $table->index(['is_active', 'deleted_at'], 'users_is_active_deleted_at_index');
            $table->index('deleted_at', 'users_deleted_at_index');
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->integer('id', true);
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
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->integer('low_staff_hour_start')->default(-1);
            $table->integer('low_staff_hour_end')->default(-1);
            $table->integer('min_staff_per_day')->default(0);
            $table->integer('min_shift_minutes')->default(120);
            $table->integer('max_shift_minutes')->default(480);
            $table->text('daily_report_settings')->nullable();
            $table->integer('week_start_day')->default(1);
            $table->string('currency_symbol_style')->default('kanji');
            $table->boolean('schedule_publication')->default(false);
            $table->date('schedule_published_until')->nullable();

            $table->index(['is_active', 'deleted_at'], 'stores_is_active_deleted_at_index');
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('user_id');
            $table->string('staff_code')->nullable();
            $table->string('hire_date')->nullable();
            $table->string('contract_type')->nullable();
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->integer('subject_to_deductions')->default(0);
            $table->string('hired_by')->nullable();

            $table->unique(['store_id', 'user_id'], 'store_user_store_id_user_id_unique');
            $table->unique(['store_id', 'staff_code'], 'store_user_store_id_staff_code_unique');
            $table->index('user_id', 'store_user_user_id_index');
            $table->foreign('store_id', 'store_user_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'store_user_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('store_features', function (Blueprint $table) {
            $table->integer('store_id');
            $table->string('feature_key');
            $table->boolean('enabled')->default(true);

            $table->primary(['store_id', 'feature_key'], 'store_features_store_id_feature_key_primary');
            $table->foreign('store_id', 'store_features_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('store_deduction_settings', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id')->unique();
            $table->integer('enabled')->default(0);
            $table->decimal('health_insurance_rate', 6, 2)->default(4.99);
            $table->decimal('pension_rate', 6, 2)->default(9.15);
            $table->decimal('employment_insurance_rate', 6, 2)->default(0.6);
            $table->decimal('income_tax_rate', 6, 2)->default(0);
            $table->decimal('resident_tax_monthly', 10, 2)->default(0);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('store_id', 'store_deduction_settings_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('store_import_settings', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id')->unique();
            $table->integer('col_start')->default(4);
            $table->integer('col_end')->default(52);
            $table->integer('base_hour')->default(6);
            $table->integer('minutes_per_col')->default(30);
            $table->integer('block_size')->default(9);
            $table->integer('shift_rows')->default(7);
            $table->string('sheet_filter_pattern')->default('^\\d{4}$');
            $table->integer('auto_pause_after_minutes')->default(0);
            $table->integer('auto_pause_minutes')->default(30);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('store_id', 'store_import_settings_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('route_slugs', function (Blueprint $table) {
            $table->bigInteger('id', true, true);
            $table->string('entity_type', 20);
            $table->bigInteger('entity_id')->unsigned();
            $table->string('slug', 191);
            $table->boolean('is_current')->default(false);
            $table->boolean('is_manual')->default(false);
            $table->dateTime('created_at', 0)->nullable();

            $table->unique(['entity_type', 'slug'], 'route_slugs_entity_type_slug_unique');
            $table->index(['entity_type', 'entity_id', 'is_current'], 'route_slugs_entity_type_entity_id_is_current_index');
        });

        Schema::create('shift_types', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name');
            $table->string('code');
            $table->string('start_time');
            $table->string('end_time');
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->string('color')->default('#3B82F6');
            $table->integer('sort_order')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
        });

        Schema::create('shift_type_stores', function (Blueprint $table) {
            $table->integer('shift_type_id');
            $table->integer('store_id');

            $table->primary(['shift_type_id', 'store_id'], 'shift_type_stores_shift_type_id_store_id_primary');
            $table->foreign('shift_type_id', 'shift_type_stores_shift_type_id_foreign')->references('id')->on('shift_types')->onDelete('cascade');
            $table->foreign('store_id', 'shift_type_stores_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('shift_date');
            $table->string('start_time');
            $table->string('end_time');
            $table->integer('shift_type_id')->nullable();
            $table->integer('cross_midnight')->default(0);
            $table->integer('pause_minutes')->default(0);
            $table->integer('duration_minutes')->default(0);
            $table->string('starts_at');
            $table->string('ends_at');
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->integer('is_open')->default(0);
            $table->text('open_shift_note')->nullable();
            $table->decimal('estimated_salary', 10, 2)->nullable();
            $table->integer('ical_sequence')->default(0);
            $table->decimal('hourly_rate_override', 10, 2)->nullable();
            $table->integer('net_minutes_override')->nullable();

            $table->index(['store_id', 'shift_date', 'deleted_at'], 'shifts_store_id_shift_date_deleted_at_index');
            $table->index(['user_id', 'shift_date', 'deleted_at'], 'shifts_user_id_shift_date_deleted_at_index');
            $table->index(['user_id', 'starts_at', 'ends_at', 'deleted_at'], 'shifts_user_id_starts_at_ends_at_deleted_at_index');
            $table->index(['store_id', 'user_id', 'shift_date'], 'shifts_store_id_user_id_shift_date_index');
            $table->index(['is_open', 'deleted_at'], 'shifts_is_open_deleted_at_index');
            $table->foreign('store_id', 'shifts_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'shifts_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shift_type_id', 'shifts_shift_type_id_foreign')->references('id')->on('shift_types')->onDelete('set null');
            $table->foreign('created_by', 'shifts_created_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('shift_deletion_log', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('shift_id');
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('shift_date')->nullable();
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->integer('cross_midnight')->default(0);
            $table->integer('shift_type_id')->nullable();
            $table->integer('pause_minutes')->default(0);
            $table->integer('ical_sequence')->default(0);
            $table->timestamp('shift_created_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->useCurrent();

            $table->index(['user_id', 'store_id', 'deleted_at'], 'shift_deletion_log_user_id_store_id_deleted_at_index');
            $table->foreign('store_id', 'shift_deletion_log_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'shift_deletion_log_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('shift_week_templates', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->string('name', 100);
            $table->text('entries');
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->index('store_id', 'shift_week_templates_store_id_index');
            $table->foreign('store_id', 'shift_week_templates_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('timesheet_approvals', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->date('week_start');
            $table->integer('approved_by')->nullable();
            $table->dateTime('approved_at', 0);
            $table->string('fingerprint', 40);
            $table->text('totals');
            $table->timestamp('created_at', 0)->nullable();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['store_id', 'week_start'], 'timesheet_approvals_store_id_week_start_unique');
            $table->foreign('store_id', 'timesheet_approvals_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('availabilities', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('store_id');
            $table->integer('day_of_week');
            $table->string('start_time');
            $table->string('end_time');
            $table->integer('is_available')->default(1);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->index(['user_id', 'store_id'], 'availabilities_user_id_store_id_index');
            $table->foreign('user_id', 'availabilities_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id', 'availabilities_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
        });

        Schema::create('user_shift_type_rates', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('shift_type_id');
            $table->decimal('hourly_rate', 10, 2);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['user_id', 'shift_type_id'], 'user_shift_type_rates_user_id_shift_type_id_unique');
            $table->foreign('user_id', 'user_shift_type_rates_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('shift_type_id', 'user_shift_type_rates_shift_type_id_foreign')->references('id')->on('shift_types')->onDelete('cascade');
        });

        Schema::create('import_aliases', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->string('staff_name');
            $table->integer('user_id');
            $table->timestamp('created_at', 0)->useCurrent();

            $table->unique(['store_id', 'staff_name'], 'import_aliases_store_id_alias_name_unique');
            $table->foreign('store_id', 'import_aliases_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'import_aliases_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_aliases');
        Schema::dropIfExists('user_shift_type_rates');
        Schema::dropIfExists('availabilities');
        Schema::dropIfExists('timesheet_approvals');
        Schema::dropIfExists('shift_week_templates');
        Schema::dropIfExists('shift_deletion_log');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_type_stores');
        Schema::dropIfExists('shift_types');
        Schema::dropIfExists('route_slugs');
        Schema::dropIfExists('store_import_settings');
        Schema::dropIfExists('store_deduction_settings');
        Schema::dropIfExists('store_features');
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('users');
    }
};
