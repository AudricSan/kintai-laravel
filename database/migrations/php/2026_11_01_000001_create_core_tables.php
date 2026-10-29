<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Utilisateurs, magasins, types de shifts, shifts, disponibilités et réglages par magasin.
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('users', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'email', ['length' => 255, 'unique' => true]);
            $table->addColumn('string', 'password_hash', ['length' => 255]);
            $table->addColumn('string', 'first_name', ['length' => 255]);
            $table->addColumn('string', 'last_name', ['length' => 255]);
            $table->addColumn('string', 'display_name', ['length' => 255]);
            $table->addColumn('string', 'phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'color', ['length' => 255, 'default' => '#3B82F6']);
            $table->addColumn('integer', 'is_active', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('timestamp', 'last_login_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('string', 'employee_code', ['length' => 255, 'nullable' => true, 'unique' => true]);
            $table->addColumn('string', 'language', ['length' => 255, 'default' => '']);
            $table->addColumn('string', 'gender', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'tax_classification', ['length' => 255, 'nullable' => true]);
            $table->addColumn('date', 'birth_date', ['nullable' => true]);
            $table->addColumn('string', 'education', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'postal_code', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'address', ['nullable' => true]);
            $table->addColumn('string', 'mobile_phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'guarantor_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'guarantor_phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'furigana_last_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'furigana_first_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'avatar_path', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'bio', ['nullable' => true]);
            $table->addColumn('text', 'skills', ['nullable' => true]);
            $table->addColumn('text', 'languages_spoken', ['nullable' => true]);
            $table->addColumn('text', 'hobbies', ['nullable' => true]);
            $table->addColumn('boolean', 'show_in_directory', ['default' => true]);
            $table->addColumn('boolean', 'share_email', ['default' => false]);
            $table->addColumn('boolean', 'share_phone', ['default' => false]);
            $table->addColumn('boolean', 'share_mobile_phone', ['default' => false]);
            $table->addColumn('string', 'quiet_hours_start', ['length' => 5, 'nullable' => true]);
            $table->addColumn('string', 'quiet_hours_end', ['length' => 5, 'nullable' => true]);

            $table->index(['is_active', 'deleted_at'], 'users_is_active_deleted_at_index');
            $table->index(['deleted_at'], 'users_deleted_at_index');
        });

        $this->schema()->create('stores', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'name', ['length' => 255]);
            $table->addColumn('string', 'code', ['length' => 255, 'unique' => true]);
            $table->addColumn('string', 'type', ['length' => 255, 'default' => 'retail']);
            $table->addColumn('string', 'timezone', ['length' => 255, 'default' => 'UTC']);
            $table->addColumn('string', 'currency', ['length' => 255, 'default' => 'EUR']);
            $table->addColumn('string', 'locale', ['length' => 255, 'default' => 'en']);
            $table->addColumn('string', 'address_street', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'address_city', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'address_postal', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'address_country', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'email', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'is_active', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'low_staff_hour_start', ['autoIncrement' => false, 'unsigned' => false, 'default' => -1]);
            $table->addColumn('integer', 'low_staff_hour_end', ['autoIncrement' => false, 'unsigned' => false, 'default' => -1]);
            $table->addColumn('integer', 'min_staff_per_day', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'min_shift_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 120]);
            $table->addColumn('integer', 'max_shift_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 480]);
            $table->addColumn('text', 'daily_report_settings', ['nullable' => true]);
            $table->addColumn('integer', 'week_start_day', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('string', 'currency_symbol_style', ['length' => 255, 'default' => 'kanji']);
            $table->addColumn('boolean', 'schedule_publication', ['default' => false]);
            $table->addColumn('date', 'schedule_published_until', ['nullable' => true]);

            $table->index(['is_active', 'deleted_at'], 'stores_is_active_deleted_at_index');
        });

        $this->schema()->create('store_user', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'staff_code', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'hire_date', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'contract_type', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'is_active', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'subject_to_deductions', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('string', 'hired_by', ['length' => 255, 'nullable' => true]);

            $table->unique(['store_id', 'user_id'], 'store_user_store_id_user_id_unique');
            $table->unique(['store_id', 'staff_code'], 'store_user_store_id_staff_code_unique');
            $table->index(['user_id'], 'store_user_user_id_index');
            $table->foreign(['store_id'], 'store_user_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'store_user_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('store_features', function (Blueprint $table) {
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'feature_key', ['length' => 255]);
            $table->addColumn('boolean', 'enabled', ['default' => true]);

            $table->primary(['store_id', 'feature_key'], 'store_features_store_id_feature_key_primary');
            $table->foreign(['store_id'], 'store_features_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('store_deduction_settings', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false, 'unique' => true]);
            $table->addColumn('integer', 'enabled', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('decimal', 'health_insurance_rate', ['total' => 6, 'places' => 2, 'default' => 4.99]);
            $table->addColumn('decimal', 'pension_rate', ['total' => 6, 'places' => 2, 'default' => 9.15]);
            $table->addColumn('decimal', 'employment_insurance_rate', ['total' => 6, 'places' => 2, 'default' => 0.6]);
            $table->addColumn('decimal', 'income_tax_rate', ['total' => 6, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'resident_tax_monthly', ['total' => 10, 'places' => 2, 'default' => 0]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['store_id'], 'store_deduction_settings_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('store_import_settings', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false, 'unique' => true]);
            $table->addColumn('integer', 'col_start', ['autoIncrement' => false, 'unsigned' => false, 'default' => 4]);
            $table->addColumn('integer', 'col_end', ['autoIncrement' => false, 'unsigned' => false, 'default' => 52]);
            $table->addColumn('integer', 'base_hour', ['autoIncrement' => false, 'unsigned' => false, 'default' => 6]);
            $table->addColumn('integer', 'minutes_per_col', ['autoIncrement' => false, 'unsigned' => false, 'default' => 30]);
            $table->addColumn('integer', 'block_size', ['autoIncrement' => false, 'unsigned' => false, 'default' => 9]);
            $table->addColumn('integer', 'shift_rows', ['autoIncrement' => false, 'unsigned' => false, 'default' => 7]);
            $table->addColumn('string', 'sheet_filter_pattern', ['length' => 255, 'default' => '^\\d{4}$']);
            $table->addColumn('integer', 'auto_pause_after_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'auto_pause_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 30]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['store_id'], 'store_import_settings_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('route_slugs', function (Blueprint $table) {
            $table->addColumn('bigInteger', 'id', ['autoIncrement' => true, 'unsigned' => true]);
            $table->addColumn('string', 'entity_type', ['length' => 20]);
            $table->addColumn('bigInteger', 'entity_id', ['autoIncrement' => false, 'unsigned' => true]);
            $table->addColumn('string', 'slug', ['length' => 191]);
            $table->addColumn('boolean', 'is_current', ['default' => false]);
            $table->addColumn('boolean', 'is_manual', ['default' => false]);
            $table->addColumn('dateTime', 'created_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['entity_type', 'slug'], 'route_slugs_entity_type_slug_unique');
            $table->index(['entity_type', 'entity_id', 'is_current'], 'route_slugs_entity_type_entity_id_is_current_index');
        });

        $this->schema()->create('shift_types', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('string', 'name', ['length' => 255]);
            $table->addColumn('string', 'code', ['length' => 255]);
            $table->addColumn('string', 'start_time', ['length' => 255]);
            $table->addColumn('string', 'end_time', ['length' => 255]);
            $table->addColumn('decimal', 'hourly_rate', ['total' => 10, 'places' => 2, 'default' => 0]);
            $table->addColumn('string', 'color', ['length' => 255, 'default' => '#3B82F6']);
            $table->addColumn('integer', 'sort_order', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'is_active', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
        });

        $this->schema()->create('shift_type_stores', function (Blueprint $table) {
            $table->addColumn('integer', 'shift_type_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);

            $table->primary(['shift_type_id', 'store_id'], 'shift_type_stores_shift_type_id_store_id_primary');
            $table->foreign(['shift_type_id'], 'shift_type_stores_shift_type_id_foreign')->references(['id'])->on('shift_types')->onDelete('cascade');
            $table->foreign(['store_id'], 'shift_type_stores_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('shifts', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'shift_date', ['length' => 255]);
            $table->addColumn('string', 'start_time', ['length' => 255]);
            $table->addColumn('string', 'end_time', ['length' => 255]);
            $table->addColumn('integer', 'shift_type_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'cross_midnight', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'pause_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'duration_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('string', 'starts_at', ['length' => 255]);
            $table->addColumn('string', 'ends_at', ['length' => 255]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'is_open', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('text', 'open_shift_note', ['nullable' => true]);
            $table->addColumn('decimal', 'estimated_salary', ['total' => 10, 'places' => 2, 'nullable' => true]);
            $table->addColumn('integer', 'ical_sequence', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('decimal', 'hourly_rate_override', ['total' => 10, 'places' => 2, 'nullable' => true]);
            $table->addColumn('integer', 'net_minutes_override', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);

            $table->index(['store_id', 'shift_date', 'deleted_at'], 'shifts_store_id_shift_date_deleted_at_index');
            $table->index(['user_id', 'shift_date', 'deleted_at'], 'shifts_user_id_shift_date_deleted_at_index');
            $table->index(['user_id', 'starts_at', 'ends_at', 'deleted_at'], 'shifts_user_id_starts_at_ends_at_deleted_at_index');
            $table->index(['store_id', 'user_id', 'shift_date'], 'shifts_store_id_user_id_shift_date_index');
            $table->index(['is_open', 'deleted_at'], 'shifts_is_open_deleted_at_index');
            $table->foreign(['store_id'], 'shifts_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'shifts_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['shift_type_id'], 'shifts_shift_type_id_foreign')->references(['id'])->on('shift_types')->onDelete('set null');
            $table->foreign(['created_by'], 'shifts_created_by_foreign')->references(['id'])->on('users')->onDelete('set null');
        });

        $this->schema()->create('shift_deletion_log', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'shift_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'shift_date', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'start_time', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'end_time', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'cross_midnight', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'shift_type_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'pause_minutes', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'ical_sequence', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('timestamp', 'shift_created_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'useCurrent' => true]);

            $table->index(['user_id', 'store_id', 'deleted_at'], 'shift_deletion_log_user_id_store_id_deleted_at_index');
            $table->foreign(['store_id'], 'shift_deletion_log_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'shift_deletion_log_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });

        $this->schema()->create('shift_week_templates', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'name', ['length' => 100]);
            $table->addColumn('text', 'entries');
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['store_id'], 'shift_week_templates_store_id_index');
            $table->foreign(['store_id'], 'shift_week_templates_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('timesheet_approvals', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('date', 'week_start');
            $table->addColumn('integer', 'approved_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('dateTime', 'approved_at', ['precision' => 0]);
            $table->addColumn('string', 'fingerprint', ['length' => 40]);
            $table->addColumn('text', 'totals');
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['store_id', 'week_start'], 'timesheet_approvals_store_id_week_start_unique');
            $table->foreign(['store_id'], 'timesheet_approvals_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('availabilities', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'day_of_week', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'start_time', ['length' => 255]);
            $table->addColumn('string', 'end_time', ['length' => 255]);
            $table->addColumn('integer', 'is_available', ['autoIncrement' => false, 'unsigned' => false, 'default' => 1]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['user_id', 'store_id'], 'availabilities_user_id_store_id_index');
            $table->foreign(['user_id'], 'availabilities_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['store_id'], 'availabilities_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
        });

        $this->schema()->create('user_shift_type_rates', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'shift_type_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('decimal', 'hourly_rate', ['total' => 10, 'places' => 2]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['user_id', 'shift_type_id'], 'user_shift_type_rates_user_id_shift_type_id_unique');
            $table->foreign(['user_id'], 'user_shift_type_rates_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['shift_type_id'], 'user_shift_type_rates_shift_type_id_foreign')->references(['id'])->on('shift_types')->onDelete('cascade');
        });

        $this->schema()->create('import_aliases', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'staff_name', ['length' => 255]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->unique(['store_id', 'staff_name'], 'import_aliases_store_id_alias_name_unique');
            $table->foreign(['store_id'], 'import_aliases_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'import_aliases_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('import_aliases');
        $this->schema()->dropIfExists('user_shift_type_rates');
        $this->schema()->dropIfExists('availabilities');
        $this->schema()->dropIfExists('timesheet_approvals');
        $this->schema()->dropIfExists('shift_week_templates');
        $this->schema()->dropIfExists('shift_deletion_log');
        $this->schema()->dropIfExists('shifts');
        $this->schema()->dropIfExists('shift_type_stores');
        $this->schema()->dropIfExists('shift_types');
        $this->schema()->dropIfExists('route_slugs');
        $this->schema()->dropIfExists('store_import_settings');
        $this->schema()->dropIfExists('store_deduction_settings');
        $this->schema()->dropIfExists('store_features');
        $this->schema()->dropIfExists('store_user');
        $this->schema()->dropIfExists('stores');
        $this->schema()->dropIfExists('users');
    }
};
