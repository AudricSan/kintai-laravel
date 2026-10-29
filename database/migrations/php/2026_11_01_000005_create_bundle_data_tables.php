<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;
use kintai\Core\Database\Migration;

/**
 * Tables des fonctionnalités fournies par les bundles (pointage, congés, échanges, rapports, photos…).
 */
return new class($this->capsule) extends Migration {
    public function up(): void
    {
        $this->schema()->create('timeclocks', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'shift_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'shift_date', ['length' => 255]);
            $table->addColumn('string', 'clock_in_time', ['length' => 255]);
            $table->addColumn('string', 'clock_out_time', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'duration_minutes', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'clock_in_ip', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'clock_out_ip', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['user_id', 'store_id'], 'timeclocks_user_id_store_id_index');
            $table->foreign(['user_id'], 'timeclocks_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['store_id'], 'timeclocks_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['shift_id'], 'timeclocks_shift_id_foreign')->references(['id'])->on('shifts')->onDelete('set null');
            $table->index(['store_id', 'shift_date'], 'timeclocks_store_id_shift_date_index');
        });

        $this->schema()->create('timeoff_requests', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'start_date', ['length' => 255]);
            $table->addColumn('string', 'end_date', ['length' => 255]);
            $table->addColumn('string', 'type', ['length' => 255, 'default' => 'vacation']);
            $table->addColumn('string', 'status', ['length' => 255, 'default' => 'pending']);
            $table->addColumn('text', 'reason', ['nullable' => true]);
            $table->addColumn('text', 'admin_note', ['nullable' => true]);
            $table->addColumn('integer', 'processed_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'processed_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['user_id', 'store_id'], 'timeoff_requests_user_id_store_id_index');
            $table->foreign(['user_id'], 'timeoff_requests_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['store_id'], 'timeoff_requests_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['processed_by'], 'timeoff_requests_processed_by_foreign')->references(['id'])->on('users')->onDelete('set null');
        });

        $this->schema()->create('shift_claims', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'shift_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'status', ['length' => 255, 'default' => 'pending']);
            $table->addColumn('text', 'note', ['nullable' => true]);
            $table->addColumn('timestamp', 'claimed_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'resolved_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'resolved_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['shift_id', 'user_id'], 'shift_claims_shift_id_user_id_unique');
            $table->foreign(['shift_id'], 'shift_claims_shift_id_foreign')->references(['id'])->on('shifts')->onDelete('cascade');
            $table->foreign(['user_id'], 'shift_claims_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['store_id'], 'shift_claims_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['resolved_by'], 'shift_claims_resolved_by_foreign')->references(['id'])->on('users')->onDelete('set null');
        });

        $this->schema()->create('shift_swap_requests', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'requester_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'target_user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'requester_shift_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'target_shift_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'status', ['length' => 255, 'default' => 'pending']);
            $table->addColumn('text', 'reason', ['nullable' => true]);
            $table->addColumn('integer', 'accepted_by_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'accepted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'approved_by_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'approved_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->index(['store_id', 'status'], 'shift_swap_requests_store_id_status_index');
            $table->index(['requester_id'], 'shift_swap_requests_requester_id_index');
            $table->index(['target_user_id'], 'shift_swap_requests_target_user_id_index');
            $table->foreign(['store_id'], 'shift_swap_requests_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['requester_shift_id'], 'shift_swap_requests_requester_shift_id_foreign')->references(['id'])->on('shifts')->onDelete('cascade');
            $table->foreign(['target_shift_id'], 'shift_swap_requests_target_shift_id_foreign')->references(['id'])->on('shifts')->onDelete('set null');
            $table->foreign(['requester_id'], 'shift_swap_requests_requester_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['target_user_id'], 'shift_swap_requests_target_user_id_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->foreign(['accepted_by_id'], 'shift_swap_requests_accepted_by_id_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->foreign(['approved_by_id'], 'shift_swap_requests_approved_by_id_foreign')->references(['id'])->on('users')->onDelete('set null');
        });

        $this->schema()->create('daily_reports', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'author_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'report_date', ['length' => 255]);
            $table->addColumn('decimal', 'sales_total', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('integer', 'customer_count', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('decimal', 'labor_cost', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'waste_total', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('text', 'data', ['nullable' => true]);
            $table->addColumn('string', 'status', ['length' => 255, 'default' => 'draft']);
            $table->addColumn('timestamp', 'submitted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'validated_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'validated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'mail_sent_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'nullable' => true]);

            $table->unique(['store_id', 'report_date'], 'daily_reports_store_id_report_date_unique');
            $table->index(['store_id', 'report_date', 'deleted_at'], 'daily_reports_store_id_report_date_deleted_at_index');
            $table->index(['author_id', 'report_date'], 'daily_reports_author_id_report_date_index');
            $table->index(['store_id', 'status', 'report_date'], 'daily_reports_store_id_status_report_date_index');
            $table->foreign(['store_id'], 'daily_reports_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['author_id'], 'daily_reports_author_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            $table->foreign(['validated_by'], 'daily_reports_validated_by_foreign')->references(['id'])->on('users')->onDelete('set null');
        });

        $this->schema()->create('hiring_reports', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'employee_number', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'employee_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'gender', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'tax_classification', ['length' => 255, 'nullable' => true]);
            $table->addColumn('date', 'birth_date', ['nullable' => true]);
            $table->addColumn('date', 'hire_date', ['nullable' => true]);
            $table->addColumn('string', 'education', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'postal_code', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'address', ['nullable' => true]);
            $table->addColumn('string', 'phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'mobile_phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'email', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'guarantor_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'guarantor_phone', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'store_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'hired_by', ['length' => 255, 'nullable' => true]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('string', 'furigana_last_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'furigana_first_name', ['length' => 255, 'nullable' => true]);

            $table->foreign(['store_id'], 'hiring_reports_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'hiring_reports_user_id_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->foreign(['created_by'], 'hiring_reports_created_by_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->index(['store_id'], 'hiring_reports_store_id_index');
        });

        $this->schema()->create('resignation_reports', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'employee_number', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'employee_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('date', 'resignation_date', ['nullable' => true]);
            $table->addColumn('text', 'reason', ['nullable' => true]);
            $table->addColumn('text', 'resignation_notice', ['nullable' => true]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('string', 'person_in_charge', ['length' => 255, 'nullable' => true]);
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['store_id'], 'resignation_reports_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['user_id'], 'resignation_reports_user_id_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->foreign(['created_by'], 'resignation_reports_created_by_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->index(['store_id'], 'resignation_reports_store_id_index');
        });

        $this->schema()->create('salary_reports', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'target_month', ['length' => 7]);
            $table->addColumn('string', 'store_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('string', 'person_in_charge', ['length' => 255, 'nullable' => true]);
            $table->addColumn('decimal', 'total_payment', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'total_deductions', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'income_tax_base', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'withholding_tax', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'residence_tax', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'other_deductions', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'net_payment', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('integer', 'active_employees', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('decimal', 'hand_delivered_salary', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'staff_man_hours', ['total' => 10, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'staff_total_payment', ['total' => 12, 'places' => 2, 'default' => 0]);
            $table->addColumn('decimal', 'staff_avg_hourly_wage', ['total' => 10, 'places' => 2, 'default' => 0]);
            $table->addColumn('text', 'employee_work_hours', ['nullable' => true]);
            $table->addColumn('integer', 'new_hires', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'resigned_staff', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('text', 'hire_registrations', ['nullable' => true]);
            $table->addColumn('text', 'remarks', ['nullable' => true]);
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'user_id', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('string', 'employee_name', ['length' => 255, 'nullable' => true]);
            $table->addColumn('decimal', 'bank_transfer_salary', ['total' => 12, 'places' => 2, 'default' => 0]);

            $table->foreign(['store_id'], 'salary_reports_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['created_by'], 'salary_reports_created_by_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->index(['store_id'], 'salary_reports_store_id_index');
            $table->unique(['store_id', 'target_month', 'user_id'], 'salary_reports_store_id_target_month_user_id_unique');
        });

        $this->schema()->create('store_photo_submissions', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'store_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'week_label', ['length' => 10]);
            $table->addColumn('text', 'notes', ['nullable' => true]);
            $table->addColumn('integer', 'image_count', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('integer', 'retention_days', ['autoIncrement' => false, 'unsigned' => false, 'default' => 14]);
            $table->addColumn('tinyInteger', 'compressed', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('timestamp', 'compressed_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'mail_sent_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('timestamp', 'deleted_at', ['precision' => 0, 'nullable' => true]);
            $table->addColumn('integer', 'created_by', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);
            $table->addColumn('timestamp', 'updated_at', ['precision' => 0, 'nullable' => true]);

            $table->foreign(['store_id'], 'store_photo_submissions_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            $table->foreign(['created_by'], 'store_photo_submissions_created_by_foreign')->references(['id'])->on('users')->onDelete('set null');
            $table->index(['store_id'], 'store_photo_submissions_store_id_index');
            $table->index(['week_label'], 'store_photo_submissions_week_label_index');
        });

        $this->schema()->create('store_photo_images', function (Blueprint $table) {
            $table->addColumn('integer', 'id', ['autoIncrement' => true, 'unsigned' => false]);
            $table->addColumn('integer', 'submission_id', ['autoIncrement' => false, 'unsigned' => false]);
            $table->addColumn('string', 'filename', ['length' => 255]);
            $table->addColumn('string', 'filepath', ['length' => 512]);
            $table->addColumn('integer', 'filesize', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('string', 'mime_type', ['length' => 50, 'nullable' => true]);
            $table->addColumn('integer', 'compressed_filesize', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'width', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'height', ['autoIncrement' => false, 'unsigned' => false, 'nullable' => true]);
            $table->addColumn('integer', 'sort_order', ['autoIncrement' => false, 'unsigned' => false, 'default' => 0]);
            $table->addColumn('timestamp', 'created_at', ['precision' => 0, 'useCurrent' => true]);

            $table->foreign(['submission_id'], 'store_photo_images_submission_id_foreign')->references(['id'])->on('store_photo_submissions')->onDelete('cascade');
            $table->index(['submission_id'], 'store_photo_images_submission_id_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('store_photo_images');
        $this->schema()->dropIfExists('store_photo_submissions');
        $this->schema()->dropIfExists('salary_reports');
        $this->schema()->dropIfExists('resignation_reports');
        $this->schema()->dropIfExists('hiring_reports');
        $this->schema()->dropIfExists('daily_reports');
        $this->schema()->dropIfExists('shift_swap_requests');
        $this->schema()->dropIfExists('shift_claims');
        $this->schema()->dropIfExists('timeoff_requests');
        $this->schema()->dropIfExists('timeclocks');
    }
};
