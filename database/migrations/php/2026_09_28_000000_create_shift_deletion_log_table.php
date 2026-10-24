<?php

declare(strict_types=1);

namespace kintai\Database\Migrations;

use kintai\Core\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class($this->capsule) extends Migration {
    public function up(): void
    {
        if ($this->schema()->hasTable('shift_deletion_log')) {
            return;
        }
        $this->schema()->create('shift_deletion_log', function (Blueprint $table) {
            $table->increments('id');
            // Pas de clé étrangère sur shift_id : la ligne shifts d'origine est
            // physiquement supprimée (hard delete), c'est justement ce que cette
            // table capture pour permettre au flux iCal d'émettre un VEVENT
            // STATUS:CANCELLED avec le même UID (shift-{shift_id}@kintai).
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
            $table->timestamp('shift_created_at')->nullable();
            $table->timestamp('deleted_at')->useCurrent();

            $table->index(['user_id', 'store_id', 'deleted_at']);

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('shift_deletion_log');
    }
};
