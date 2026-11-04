<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un seul mécanisme de disposition : personnelle (user_id) ou imposée à un rôle (role_id).
        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('dashboard', 20);
            $table->string('name');
            $table->json('layout');
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'dashboard']);
            $table->index(['role_id', 'dashboard']);
        });

        Schema::create('user_nav_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('hidden_items')->nullable();
            $table->json('bottom_nav_items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_nav_prefs');
        Schema::dropIfExists('dashboard_layouts');
    }
};
