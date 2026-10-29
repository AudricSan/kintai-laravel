<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables des fonctionnalités fournies par les bundles (pointage, congés, échanges, rapports, photos…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeclocks', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('store_id');
            $table->integer('shift_id')->nullable();
            $table->string('shift_date');
            $table->string('clock_in_time');
            $table->string('clock_out_time')->nullable();
            $table->integer('duration_minutes')->nullable();
            $table->string('clock_in_ip')->nullable();
            $table->string('clock_out_ip')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->index(['user_id', 'store_id'], 'timeclocks_user_id_store_id_index');
            $table->foreign('user_id', 'timeclocks_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id', 'timeclocks_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('shift_id', 'timeclocks_shift_id_foreign')->references('id')->on('shifts')->onDelete('set null');
            $table->index(['store_id', 'shift_date'], 'timeclocks_store_id_shift_date_index');
        });

        Schema::create('timeoff_requests', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('user_id');
            $table->integer('store_id');
            $table->string('start_date');
            $table->string('end_date');
            $table->string('type')->default('vacation');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->integer('processed_by')->nullable();
            $table->timestamp('processed_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->index(['user_id', 'store_id'], 'timeoff_requests_user_id_store_id_index');
            $table->foreign('user_id', 'timeoff_requests_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id', 'timeoff_requests_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('processed_by', 'timeoff_requests_processed_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('shift_claims', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('shift_id');
            $table->integer('user_id');
            $table->integer('store_id');
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('claimed_at', 0)->useCurrent();
            $table->timestamp('resolved_at', 0)->nullable();
            $table->integer('resolved_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->unique(['shift_id', 'user_id'], 'shift_claims_shift_id_user_id_unique');
            $table->foreign('shift_id', 'shift_claims_shift_id_foreign')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('user_id', 'shift_claims_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('store_id', 'shift_claims_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('resolved_by', 'shift_claims_resolved_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('requester_id');
            $table->integer('target_user_id')->nullable();
            $table->integer('requester_shift_id');
            $table->integer('target_shift_id')->nullable();
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->integer('accepted_by_id')->nullable();
            $table->timestamp('accepted_at', 0)->nullable();
            $table->integer('approved_by_id')->nullable();
            $table->timestamp('approved_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->index(['store_id', 'status'], 'shift_swap_requests_store_id_status_index');
            $table->index('requester_id', 'shift_swap_requests_requester_id_index');
            $table->index('target_user_id', 'shift_swap_requests_target_user_id_index');
            $table->foreign('store_id', 'shift_swap_requests_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('requester_shift_id', 'shift_swap_requests_requester_shift_id_foreign')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('target_shift_id', 'shift_swap_requests_target_shift_id_foreign')->references('id')->on('shifts')->onDelete('set null');
            $table->foreign('requester_id', 'shift_swap_requests_requester_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_user_id', 'shift_swap_requests_target_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('accepted_by_id', 'shift_swap_requests_accepted_by_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by_id', 'shift_swap_requests_approved_by_id_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('daily_reports', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('author_id');
            $table->string('report_date');
            $table->decimal('sales_total', 12, 2)->default(0);
            $table->integer('customer_count')->default(0);
            $table->decimal('labor_cost', 12, 2)->default(0);
            $table->decimal('waste_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('data')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('submitted_at', 0)->nullable();
            $table->integer('validated_by')->nullable();
            $table->timestamp('validated_at', 0)->nullable();
            $table->timestamp('mail_sent_at', 0)->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();

            $table->unique(['store_id', 'report_date'], 'daily_reports_store_id_report_date_unique');
            $table->index(['store_id', 'report_date', 'deleted_at'], 'daily_reports_store_id_report_date_deleted_at_index');
            $table->index(['author_id', 'report_date'], 'daily_reports_author_id_report_date_index');
            $table->index(['store_id', 'status', 'report_date'], 'daily_reports_store_id_status_report_date_index');
            $table->foreign('store_id', 'daily_reports_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('author_id', 'daily_reports_author_id_foreign')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('validated_by', 'daily_reports_validated_by_foreign')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('hiring_reports', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('employee_number')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('gender')->nullable();
            $table->string('tax_classification')->nullable();
            $table->date('birth_date')->nullable();
            $table->date('hire_date')->nullable();
            $table->string('education')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('email')->nullable();
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone')->nullable();
            $table->string('store_name')->nullable();
            $table->string('hired_by')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->string('furigana_last_name')->nullable();
            $table->string('furigana_first_name')->nullable();

            $table->foreign('store_id', 'hiring_reports_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'hiring_reports_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by', 'hiring_reports_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->index('store_id', 'hiring_reports_store_id_index');
        });

        Schema::create('resignation_reports', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->integer('user_id')->nullable();
            $table->string('employee_number')->nullable();
            $table->string('employee_name')->nullable();
            $table->date('resignation_date')->nullable();
            $table->text('reason')->nullable();
            $table->text('resignation_notice')->nullable();
            $table->text('notes')->nullable();
            $table->string('person_in_charge')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('store_id', 'resignation_reports_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id', 'resignation_reports_user_id_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by', 'resignation_reports_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->index('store_id', 'resignation_reports_store_id_index');
        });

        Schema::create('salary_reports', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->string('target_month', 7);
            $table->string('store_name')->nullable();
            $table->string('person_in_charge')->nullable();
            $table->decimal('total_payment', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('income_tax_base', 12, 2)->default(0);
            $table->decimal('withholding_tax', 12, 2)->default(0);
            $table->decimal('residence_tax', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_payment', 12, 2)->default(0);
            $table->integer('active_employees')->default(0);
            $table->decimal('hand_delivered_salary', 12, 2)->default(0);
            $table->decimal('staff_man_hours', 10, 2)->default(0);
            $table->decimal('staff_total_payment', 12, 2)->default(0);
            $table->decimal('staff_avg_hourly_wage', 10, 2)->default(0);
            $table->text('employee_work_hours')->nullable();
            $table->integer('new_hires')->default(0);
            $table->integer('resigned_staff')->default(0);
            $table->text('hire_registrations')->nullable();
            $table->text('remarks')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();
            $table->integer('user_id')->nullable();
            $table->string('employee_name')->nullable();
            $table->decimal('bank_transfer_salary', 12, 2)->default(0);

            $table->foreign('store_id', 'salary_reports_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('created_by', 'salary_reports_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->index('store_id', 'salary_reports_store_id_index');
            $table->unique(['store_id', 'target_month', 'user_id'], 'salary_reports_store_id_target_month_user_id_unique');
        });

        Schema::create('store_photo_submissions', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('store_id');
            $table->string('week_label', 10);
            $table->text('notes')->nullable();
            $table->integer('image_count')->default(0);
            $table->integer('retention_days')->default(14);
            $table->tinyInteger('compressed')->default(0);
            $table->timestamp('compressed_at', 0)->nullable();
            $table->timestamp('mail_sent_at', 0)->nullable();
            $table->timestamp('deleted_at', 0)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->nullable();

            $table->foreign('store_id', 'store_photo_submissions_store_id_foreign')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('created_by', 'store_photo_submissions_created_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->index('store_id', 'store_photo_submissions_store_id_index');
            $table->index('week_label', 'store_photo_submissions_week_label_index');
        });

        Schema::create('store_photo_images', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('submission_id');
            $table->string('filename');
            $table->string('filepath', 512);
            $table->integer('filesize')->default(0);
            $table->string('mime_type', 50)->nullable();
            $table->integer('compressed_filesize')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at', 0)->useCurrent();

            $table->foreign('submission_id', 'store_photo_images_submission_id_foreign')->references('id')->on('store_photo_submissions')->onDelete('cascade');
            $table->index('submission_id', 'store_photo_images_submission_id_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_photo_images');
        Schema::dropIfExists('store_photo_submissions');
        Schema::dropIfExists('salary_reports');
        Schema::dropIfExists('resignation_reports');
        Schema::dropIfExists('hiring_reports');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('shift_swap_requests');
        Schema::dropIfExists('shift_claims');
        Schema::dropIfExists('timeoff_requests');
        Schema::dropIfExists('timeclocks');
    }
};
