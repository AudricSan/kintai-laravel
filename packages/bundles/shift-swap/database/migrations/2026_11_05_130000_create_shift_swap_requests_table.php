<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_swap_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requester_shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('target_shift_id')->constrained('shifts')->cascadeOnDelete();
            // Version (`ical_sequence`) des deux shifts à la demande : un shift modifié depuis ne s'échange plus.
            $table->unsignedInteger('requester_shift_sequence')->default(0);
            $table->unsignedInteger('target_shift_sequence')->default(0);
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            // Réponse du collègue (le statut reste « en attente » jusqu'à la décision du gérant).
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            // Remplaçants créés par l'échange : celui du shift du demandeur (désormais au collègue) et inversement.
            $table->foreignId('applied_requester_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('applied_target_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index(['target_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_swap_requests');
    }
};
