<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeclocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            // Le shift prévu ce jour-là, retrouvé à l'entrée ; il peut disparaître sans effacer le pointage.
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            // Jour local (fuseau de l'instance) de l'entrée.
            $table->date('shift_date');
            // Horodatages en UTC, comme les shifts.
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('clock_in_ip', 45)->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'shift_date']);
            $table->index(['user_id', 'clock_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeclocks');
    }
};
