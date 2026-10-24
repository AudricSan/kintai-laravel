<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('user_dashboard_prefs')) {
            return;
        }
        $this->schema()->create('user_dashboard_prefs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('dashboard_type')->default('employee');
            $table->text('widgets')->default($this->textDefault('[]'));
            // Ordre et largeur des widgets (DashboardLayout), null = disposition par défaut.
            $table->text('layout')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['user_id', 'dashboard_type']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('user_dashboard_prefs');
    }
};
