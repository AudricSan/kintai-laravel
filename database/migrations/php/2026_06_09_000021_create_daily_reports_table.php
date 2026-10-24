<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('daily_reports')) {
            return;
        }
        $this->schema()->create('daily_reports', function (Blueprint $table) {
            $table->increments('id');
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
            $table->integer('is_finalized')->default(0);
            $table->integer('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->integer('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->unique(['store_id', 'report_date']);
            $table->index(['store_id', 'report_date', 'deleted_at']);
            $table->index(['author_id', 'report_date']);
            $table->index(['store_id', 'status', 'report_date']);
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('validated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('daily_reports');
    }
};
